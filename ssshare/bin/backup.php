<?php

declare(strict_types=1);

/*
 * Encrypted, authenticated backups of the database (and optionally stored files).
 *
 *   php bin/backup.php create [--with-files] [--dir=storage/backups]
 *   php bin/backup.php verify <file>
 *   php bin/backup.php restore <file> [--with-files] [--force]
 *   php bin/backup.php prune [--keep-days=7] [--dir=storage/backups]
 *
 * Format: "SSSBK1\n" + chunks of [uint32 length | 12-byte nonce | 16-byte tag | AES-256-GCM ciphertext].
 * Each chunk is gzip-compressed JSON lines; the chunk index and a final-chunk flag are bound as
 * associated data, so reordered, truncated or tampered files are rejected.
 *
 * The key comes from BACKUP_KEY (base64, 32 bytes). Keep it outside the server: a backup must stay
 * readable when the server is lost, and unreadable to whoever takes only the backup. Restore needs
 * the same APP_KEY too, because encrypted fields (TOTP seeds, webhook secrets) are copied as-is.
 *
 * Expired and deleted content is not resurrected by old backups: keep them for a short time (prune).
 */

require __DIR__ . '/../bootstrap.php';

use App\Services\StorageService;
use App\Support\Database;

const MAGIC = "SSSBK1\n";
const CHUNK_BYTES = 1048576;
// Re-created from migrations on the target; restoring them would conflict with the schema.
const SKIP_TABLES = ['migrations', 'rate_limits', 'scheduler_heartbeats', 'link_sessions', 'idempotency_keys'];
// Seeded by migrations; replaced by the backup's rows.
const SEEDED_TABLES = ['plans', 'feature_flags', 'email_templates', 'settings'];

function fail(string $message): never
{
    fwrite(STDERR, "backup: $message\n");
    exit(1);
}

function backup_key(): string
{
    $raw = getenv('BACKUP_KEY') ?: (string) ($_ENV['BACKUP_KEY'] ?? '');
    if ($raw !== '') {
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            fail('BACKUP_KEY must be 32 random bytes, base64-encoded (openssl rand -base64 32).');
        }
        return $key;
    }
    if (config('app.env') === 'production') {
        fail('Set BACKUP_KEY in production; backups must not depend on a key stored only on this server.');
    }
    fwrite(STDERR, "backup: warning: BACKUP_KEY is not set; deriving a development key from APP_KEY.\n");
    return hash_hkdf('sha256', (string) config('app.key'), 32, 'ssshare-backup');
}

function option(array $args, string $name, ?string $default = null): ?string
{
    foreach ($args as $arg) {
        if (str_starts_with($arg, "--$name=")) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return in_array("--$name", $args, true) ? '1' : $default;
}

final class BackupWriter
{
    private string $buffer = '';
    private int $index = 0;

    public function __construct(private $handle, private string $key)
    {
        fwrite($handle, MAGIC);
    }

    public function record(array $record): void
    {
        $this->buffer .= json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($this->buffer) >= CHUNK_BYTES) {
            $this->flush(false);
        }
    }

    public function close(): void
    {
        $this->record(['t' => 'end']);
        $this->flush(true);
        fclose($this->handle);
    }

    private function flush(bool $final): void
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(gzencode($this->buffer, 6), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, MAGIC . $this->index . ($final ? ':final' : ''));
        fwrite($this->handle, pack('N', strlen($cipher)) . $nonce . $tag . $cipher);
        $this->buffer = '';
        $this->index++;
    }
}

/** @return Generator<array> decrypted records, in order */
function read_backup(string $path, string $key): Generator
{
    $handle = @fopen($path, 'rb');
    if ($handle === false || fread($handle, strlen(MAGIC)) !== MAGIC) {
        fail("$path is not an SSShare backup.");
    }
    $index = 0;
    $sawEnd = false;
    while (($len = fread($handle, 4)) !== '' && $len !== false) {
        if (strlen($len) !== 4) {
            fail('Backup is truncated.');
        }
        $size = unpack('N', $len)[1];
        $nonce = fread($handle, 12);
        $tag = fread($handle, 16);
        $cipher = $size > 0 ? fread($handle, $size) : '';
        if (strlen($cipher) !== $size) {
            fail('Backup is truncated.');
        }
        $final = fread($handle, 1) === '' && feof($handle);
        if (!$final) {
            fseek($handle, -1, SEEK_CUR);
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, MAGIC . $index . ($final ? ':final' : ''));
        if ($plain === false) {
            fail("Chunk $index failed authentication: wrong BACKUP_KEY, or the file was modified, reordered or truncated.");
        }
        foreach (explode("\n", rtrim((string) gzdecode($plain), "\n")) as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($record['t'] === 'end') {
                $sawEnd = true;
                continue;
            }
            yield $record;
        }
        $index++;
    }
    fclose($handle);
    if (!$sawEnd) {
        fail('Backup is incomplete (no end marker).');
    }
}

// Non-UTF-8 values (rare binary columns) are carried as base64.
function pack_row(array $row): array
{
    foreach ($row as $column => $value) {
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $row[$column] = ['b64' => base64_encode($value)];
        }
    }
    return $row;
}

function unpack_row(array $row): array
{
    foreach ($row as $column => $value) {
        if (is_array($value) && isset($value['b64'])) {
            $row[$column] = base64_decode($value['b64']);
        }
    }
    return $row;
}

function tables(): array
{
    return array_values(array_diff(Database::pdo()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN), SKIP_TABLES));
}

function create(array $args): void
{
    $key = backup_key();
    $dir = option($args, 'dir', base_path('storage/backups'));
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fail("Cannot create $dir.");
    }
    $path = rtrim($dir, '/') . '/ssshare-' . gmdate('Ymd-His') . '.sssbk';
    $tmp = $path . '.part';
    $handle = fopen($tmp, 'xb') ?: fail("Cannot write $tmp.");
    chmod($tmp, 0600);
    $writer = new BackupWriter($handle, $key);

    $pdo = Database::pdo();
    $withFiles = option($args, 'with-files') !== null;
    $writer->record([
        't' => 'meta', 'version' => 1, 'created_at' => gmdate('c'), 'files' => $withFiles,
        'migrations' => $pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN),
    ]);
    // A consistent snapshot across all tables without locking writers.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $rows = 0;
    foreach (tables() as $table) {
        $writer->record(['t' => 'table', 'name' => $table]);
        $keys = $pdo->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        $order = $keys !== [] ? ' ORDER BY ' . implode(', ', array_map(static fn ($k) => '`' . $k['Column_name'] . '`', $keys)) : '';
        for ($offset = 0; ; $offset += 1000) {
            $batch = $pdo->query("SELECT * FROM `$table`$order LIMIT 1000 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $row) {
                $writer->record(['t' => 'row', 'd' => pack_row($row)]);
            }
            $rows += count($batch);
            if (count($batch) < 1000) {
                break;
            }
        }
    }
    $files = 0;
    if ($withFiles) {
        $storage = StorageService::driver();
        $objects = $pdo->query('SELECT object_key FROM upload_files WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($objects as $objectKey) {
            try {
                $writer->record(['t' => 'file', 'k' => $objectKey, 'b' => base64_encode($storage->get($objectKey))]);
                $files++;
            } catch (Throwable $e) {
                fwrite(STDERR, "backup: warning: skipped missing object $objectKey\n");
            }
        }
    }
    $pdo->exec('COMMIT');
    $writer->close();
    rename($tmp, $path);
    printf("Wrote %s (%s, %d rows, %d files).\n", $path, format_bytes((int) filesize($path)), $rows, $files);
}

function verify(string $path): void
{
    $counts = ['tables' => 0, 'rows' => 0, 'files' => 0];
    $meta = null;
    foreach (read_backup($path, backup_key()) as $record) {
        match ($record['t']) {
            'meta' => $meta = $record,
            'table' => $counts['tables']++,
            'row' => $counts['rows']++,
            'file' => $counts['files']++,
            default => fail('Unknown record type ' . $record['t']),
        };
    }
    printf("OK: backup from %s, %d tables, %d rows, %d files.\n", $meta['created_at'] ?? '?', $counts['tables'], $counts['rows'], $counts['files']);
}

function restore(string $path, array $args): void
{
    $key = backup_key();
    $pdo = Database::pdo();
    $force = option($args, 'force') !== null;
    $withFiles = option($args, 'with-files') !== null;

    // Pass 1: authenticate the whole file before touching the database.
    $meta = null;
    foreach (read_backup($path, $key) as $record) {
        $meta ??= $record['t'] === 'meta' ? $record : null;
    }
    $applied = $pdo->query('SELECT name FROM migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    if ($meta === null || $meta['migrations'] !== $applied) {
        fail('Schema mismatch: run the same migrations as the backup (php bin/migrate.php) on an empty database first.');
    }
    foreach (array_diff(tables(), SEEDED_TABLES) as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() > 0) {
            if (!$force || $table === 'audit_logs') {
                fail("Table $table is not empty. Restore into a freshly migrated database" . ($table === 'audit_logs' ? ' (the audit log is append-only).' : ', or pass --force.'));
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();
    $known = array_flip(tables());
    $table = null;
    $rows = $files = 0;
    $storage = $withFiles ? StorageService::driver() : null;
    try {
        foreach (read_backup($path, $key) as $record) {
            if ($record['t'] === 'table') {
                $table = isset($known[$record['name']]) ? $record['name'] : null;
                if ($table !== null && (in_array($table, SEEDED_TABLES, true) || $force)) {
                    $pdo->exec("DELETE FROM `$table`");
                }
            } elseif ($record['t'] === 'row' && $table !== null) {
                $row = unpack_row($record['d']);
                $columns = implode(', ', array_map(static fn ($c) => "`$c`", array_keys($row)));
                $pdo->prepare("INSERT INTO `$table` ($columns) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
                $rows++;
            } elseif ($record['t'] === 'file' && $storage !== null) {
                $storage->put($record['k'], base64_decode($record['b']));
                $files++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Restore failed and was rolled back: ' . $e->getMessage());
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    printf("Restored %d rows and %d files from %s.\n", $rows, $files, $path);
    if (!$withFiles && !empty($meta['files'])) {
        echo "The backup also contains files; pass --with-files to restore them.\n";
    }
}

function prune(array $args): void
{
    $days = max(1, (int) option($args, 'keep-days', '7'));
    $dir = option($args, 'dir', base_path('storage/backups'));
    $removed = 0;
    foreach (glob(rtrim($dir, '/') . '/ssshare-*.sssbk') ?: [] as $file) {
        if (filemtime($file) < time() - $days * 86400 && unlink($file)) {
            $removed++;
        }
    }
    echo "Removed $removed backup(s) older than $days day(s).\n";
}

$args = array_slice($argv, 1);
$command = $args[0] ?? '';
$file = $args[1] ?? null;
match ($command) {
    'create' => create($args),
    'verify' => verify($file ?? fail('Usage: backup.php verify <file>')),
    'restore' => restore($file ?? fail('Usage: backup.php restore <file>'), $args),
    'prune' => prune($args),
    default => fail('Usage: backup.php create|verify|restore|prune (see the header of this file).'),
};
