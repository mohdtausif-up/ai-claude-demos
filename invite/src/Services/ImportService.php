<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** CSV guest import, step 1 and 2 (upload + column mapping). Row processing happens in ImportJob on the worker. */
final class ImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 10000;
    public const FIELDS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'full_name' => 'Full name (split into first and last)',
        'email' => 'Email', 'phone' => 'Phone / WhatsApp', 'group_name' => 'Family / group',
        'tags' => 'Tags (comma separated)', 'is_vip' => 'VIP (yes/no)', 'plus_one_limit' => 'Plus-ones allowed', 'host_notes' => 'Notes',
    ];
    private const GUESSES = [
        'first_name' => ['firstname', 'first', 'givenname', 'fname'],
        'last_name' => ['lastname', 'last', 'surname', 'familyname', 'lname'],
        'full_name' => ['name', 'fullname', 'guest', 'guestname'],
        'email' => ['email', 'emailaddress', 'mail'],
        'phone' => ['phone', 'mobile', 'whatsapp', 'phonenumber', 'cell', 'contact', 'mobilenumber'],
        'group_name' => ['group', 'family', 'household', 'party'],
        'tags' => ['tags', 'tag', 'relationship', 'category', 'side'],
        'is_vip' => ['vip'],
        'plus_one_limit' => ['plusones', 'plusone', 'plus1', 'guests', 'companions'],
        'host_notes' => ['notes', 'note', 'comments', 'comment'],
    ];

    public function __construct(private QueueService $queue, private AuditLogger $audit)
    {
    }

    /** @param array{tmp_name:string,size:int,error:int,name:string} $file */
    public function stage(array $event, array $user, array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new ValidationException(['file' => ['The upload failed. Please try again.']]);
        }
        if ($file['size'] > self::MAX_BYTES || $file['size'] < 1) {
            throw new ValidationException(['file' => ['The file must be a CSV under 5 MB.']]);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            throw new ValidationException(['file' => ['Please upload a .csv or .xlsx file.']]);
        }
        $convertFrom = null;
        if ($ext === 'xlsx') {
            if ((string) file_get_contents($file['tmp_name'], false, null, 0, 2) !== 'PK') {
                throw new ValidationException(['file' => ['That does not look like an Excel (.xlsx) file.']]);
            }
            try {
                $csv = XlsxReader::toCsv($file['tmp_name']);
            } catch (\Throwable $e) {
                throw new ValidationException(['file' => ['We could not read that Excel file: ' . $e->getMessage() . '. Try saving it again, or export as CSV.']]);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'xl');
            file_put_contents($tmp, $csv);
            $file['tmp_name'] = $tmp;
            $file['size'] = strlen($csv);
            $convertFrom = $tmp;
        }
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 4096);
        if (str_contains($head, "\0") || !mb_check_encoding($head, 'UTF-8') && !mb_check_encoding(mb_convert_encoding($head, 'UTF-8', 'Windows-1252'), 'UTF-8')) {
            throw new ValidationException(['file' => ['That does not look like a text CSV file.']]);
        }
        $uuid = uuid4();
        $dir = BASE_PATH . '/storage/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $uuid . '.csv';
        $dest = $dir . '/' . $path;
        $stored = $convertFrom !== null || PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
        if ($convertFrom !== null) {
            @unlink($convertFrom);
        }
        if (!$stored) {
            throw new ValidationException(['file' => ['Could not store the upload.']]);
        }
        $rows = $this->readRows($dest, 6);
        if (count($rows) < 2) {
            @unlink($dest);
            throw new ValidationException(['file' => ['The file needs a header row and at least one guest.']]);
        }
        $id = Db::insert('imports', [
            'uuid' => $uuid, 'event_id' => $event['id'], 'created_by' => $user['id'], 'file_path' => $path,
            'preview_json' => json_out(['header' => $rows[0], 'rows' => array_slice($rows, 1, 5), 'guess' => $this->guess($rows[0])]),
            'status' => 'uploaded', 'created_at' => utcnow(),
        ]);
        return Db::one('SELECT * FROM imports WHERE id = ?', [$id]);
    }

    /** @return array<string,int> field => column index */
    public function guess(array $header): array
    {
        $map = [];
        foreach ($header as $i => $h) {
            $norm = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $h));
            foreach (self::GUESSES as $field => $names) {
                if (!isset($map[$field]) && in_array($norm, $names, true)) {
                    $map[$field] = $i;
                }
            }
        }
        // If first/last exist, a "name" column is redundant.
        if (isset($map['first_name'])) {
            unset($map['full_name']);
        }
        return $map;
    }

    /** @return list<list<string>> */
    public function readRows(string $file, ?int $limit = null): array
    {
        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delim = ',';
        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($firstLine, $d) > substr_count($firstLine, $delim)) {
                $delim = $d;
            }
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($r === [null] || !array_filter($r, static fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $rows[] = array_map(static fn ($c) => trim((string) $c), $r);
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
            if (count($rows) > self::MAX_ROWS + 1) {
                throw new ValidationException(['file' => ['At most ' . self::MAX_ROWS . ' guests can be imported at once.']]);
            }
        }
        fclose($fh);
        return $rows;
    }

    /** Validate the mapping and queue the job. */
    public function confirm(array $event, array $user, array $import, array $mapping, string $onDuplicate): void
    {
        $clean = [];
        $width = count(json_decode((string) $import['preview_json'], true)['header'] ?? []);
        foreach (self::FIELDS as $field => $_) {
            $idx = $mapping[$field] ?? '';
            if ($idx !== '' && is_numeric($idx) && (int) $idx >= 0 && (int) $idx < $width) {
                $clean[$field] = (int) $idx;
            }
        }
        if (!isset($clean['first_name']) && !isset($clean['full_name'])) {
            throw new ValidationException(['mapping' => ['Choose which column holds the guest name.']]);
        }
        Db::update('imports', [
            'mapping_json' => json_out($clean),
            'on_duplicate' => $onDuplicate === 'update' ? 'update' : 'skip',
            'status' => 'queued',
        ], ['id' => $import['id']]);
        $this->queue->push('import.process', ['import_id' => (int) $import['id']], 0, 1);
        $this->audit->event($user, $event, 'guests.import_queued', ['import' => $import['uuid']]);
    }

    public function find(int $eventId, string $uuid): ?array
    {
        return Db::one('SELECT * FROM imports WHERE event_id = ? AND uuid = ?', [$eventId, $uuid]);
    }
}
