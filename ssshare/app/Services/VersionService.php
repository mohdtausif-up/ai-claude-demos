<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use Throwable;

/** Replace content without changing the shared URL, keep history, restore and compare. */
final class VersionService
{
    public function __construct(private readonly UploadService $uploads = new UploadService())
    {
    }

    /**
     * @param array{bytes?:string, content?:string, language?:?string, name?:?string} $input
     * @return array the new version row
     */
    public function replace(array $upload, ?array $user, array $input, ?string $note): array
    {
        $this->assertReplaceable($upload);
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 255) {
            throw HttpException::validation(['change_note' => 'Keep the change note under 255 characters.']);
        }
        $next = (int) Database::value('SELECT MAX(version_number) FROM upload_versions WHERE upload_id = ?', [$upload['id']]) + 1;
        $region = $upload['storage_region'];
        $files = match ($upload['content_type']) {
            'image' => $this->uploads->buildImageFiles(
                $input['bytes'] ?? throw HttpException::validation(['file' => 'Choose a replacement image.']),
                $input['name'] ?? null, $upload['uuid'], $next, $region, $user
            )[0],
            'text' => $this->uploads->buildTextFiles(
                $input['content'] ?? throw HttpException::validation(['content' => 'Enter the new text.']),
                $input['language'] ?? null, $upload['uuid'], $next, $region
            ),
            default => throw new HttpException(409, 'invalid_state', 'This kind of share cannot be replaced.'),
        };
        $bytes = array_sum(array_map(static fn ($f) => strlen($f['bytes']), $files));
        $owner = $upload['owner_user_id'] !== null ? Database::first('SELECT * FROM users WHERE id = ?', [$upload['owner_user_id']]) : null;
        $this->uploads->guardQuota($owner, $bytes);

        $storage = StorageService::driver();
        foreach ($files as $file) {
            $storage->put($file['key'], $file['bytes']);
        }
        try {
            $versionId = Database::transaction(function () use ($upload, $user, $note, $next, $files, $bytes) {
                Database::first('SELECT id FROM uploads WHERE id = ? FOR UPDATE', [$upload['id']]);
                $id = Database::insert('upload_versions', [
                    'upload_id' => $upload['id'], 'version_number' => $next, 'uploader_user_id' => $user['id'] ?? null,
                    'change_note' => $note !== '' ? $note : null, 'created_at' => db_time(),
                ]);
                UploadService::insertFiles((int) $upload['id'], $id, $files);
                Database::execute(
                    "UPDATE uploads SET current_version_id = ?, storage_bytes = storage_bytes + ?,
                        ocr_status = IF(content_type = 'image', 'pending', ocr_status), updated_at = ? WHERE id = ?",
                    [$id, $bytes, db_time(), $upload['id']]
                );
                return $id;
            });
        } catch (Throwable $e) {
            foreach ($files as $file) {
                $storage->delete($file['key']);
            }
            throw $e;
        }

        if ((int) $upload['keep_old_versions'] === 0) {
            $this->purgeOlderThan($upload, $versionId);
        }
        AuditLogService::record('upload.replaced', 'upload', $upload['uuid'], ['version' => $next]);
        EventService::dispatch('upload.replaced', Database::first('SELECT * FROM uploads WHERE id = ?', [$upload['id']]), ['version' => $next]);
        return Database::first('SELECT * FROM upload_versions WHERE id = ?', [$versionId]);
    }

    /** Restores an older version by copying it into a new version, so history stays linear. */
    public function restore(array $upload, ?array $user, int $versionNumber): array
    {
        $this->assertReplaceable($upload);
        $version = Database::first('SELECT * FROM upload_versions WHERE upload_id = ? AND version_number = ?', [$upload['id'], $versionNumber])
            ?? throw HttpException::notFound('Version not found.');
        if ((int) $version['id'] === (int) $upload['current_version_id']) {
            throw new HttpException(409, 'invalid_state', 'That version is already current.');
        }
        $primary = Database::first("SELECT * FROM upload_files WHERE upload_version_id = ? AND role IN ('display', 'text') AND deleted_at IS NULL", [$version['id']])
            ?? throw new HttpException(410, 'gone', 'The files for that version were purged.');
        $bytes = StorageService::driver()->get($primary['object_key']);
        $input = $upload['content_type'] === 'text'
            ? ['content' => $bytes, 'language' => $primary['text_language']]
            : ['bytes' => $bytes, 'name' => $primary['original_name']];
        return $this->replace($upload, $user, $input, "Restored from version $versionNumber");
    }

    public function purgeVersion(array $upload, int $versionNumber): void
    {
        $version = Database::first('SELECT * FROM upload_versions WHERE upload_id = ? AND version_number = ?', [$upload['id'], $versionNumber])
            ?? throw HttpException::notFound('Version not found.');
        if ((int) $version['id'] === (int) $upload['current_version_id']) {
            throw new HttpException(409, 'invalid_state', 'The current version cannot be purged. Upload a replacement first.');
        }
        $this->purgeFiles((int) $upload['id'], [(int) $version['id']]);
        AuditLogService::record('upload.version_purged', 'upload', $upload['uuid'], ['version' => $versionNumber]);
    }

    private function purgeOlderThan(array $upload, int $currentVersionId): void
    {
        $ids = array_map('intval', array_column(Database::select('SELECT id FROM upload_versions WHERE upload_id = ? AND id <> ?', [$upload['id'], $currentVersionId]), 'id'));
        if ($ids !== []) {
            $this->purgeFiles((int) $upload['id'], $ids);
        }
    }

    private function purgeFiles(int $uploadId, array $versionIds): void
    {
        $storage = StorageService::driver();
        $files = Database::select(
            'SELECT * FROM upload_files WHERE upload_id = ? AND deleted_at IS NULL AND upload_version_id IN (' . Database::placeholders($versionIds) . ')',
            [$uploadId, ...$versionIds]
        );
        $freed = 0;
        foreach ($files as $file) {
            $storage->delete($file['object_key']);
            Database::update('upload_files', ['deleted_at' => db_time()], 'id = ?', [$file['id']]);
            $freed += (int) $file['bytes'];
        }
        Database::execute('UPDATE uploads SET storage_bytes = GREATEST(0, CAST(storage_bytes AS SIGNED) - ?) WHERE id = ?', [$freed, $uploadId]);
    }

    public static function versions(int $uploadId): array
    {
        return Database::select(
            "SELECT v.*, u.display_name AS uploader_name, f.id AS file_id, f.ext, f.width, f.height, f.bytes, f.deleted_at AS file_deleted_at, f.text_lines
             FROM upload_versions v
             LEFT JOIN users u ON u.id = v.uploader_user_id
             LEFT JOIN upload_files f ON f.upload_version_id = v.id AND f.role IN ('display', 'text', 'video')
             WHERE v.upload_id = ? ORDER BY v.version_number DESC",
            [$uploadId]
        );
    }

    private function assertReplaceable(array $upload): void
    {
        if ($upload['status'] !== 'active') {
            throw new HttpException(409, 'invalid_state', 'Only active shares can be replaced.');
        }
    }

    /**
     * Line diff (LCS). Returns a list of [op, text] with op in ' ', '+', '-'.
     * Large inputs fall back to a whole-block replacement.
     */
    public static function diff(string $old, string $new): array
    {
        $a = explode("\n", $old);
        $b = explode("\n", $new);
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4_000_000) {
            return [...array_map(static fn ($l) => ['-', $l], $a), ...array_map(static fn ($l) => ['+', $l], $b)];
        }
        // Trim the common prefix and suffix to keep the table small.
        $start = 0;
        while ($start < $n && $start < $m && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = $n - 1;
        $endB = $m - 1;
        while ($endA >= $start && $endB >= $start && $a[$endA] === $b[$endB]) {
            $endA--;
            $endB--;
        }
        $midA = array_slice($a, $start, $endA - $start + 1);
        $midB = array_slice($b, $start, $endB - $start + 1);
        $la = count($midA);
        $lb = count($midB);
        $table = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $table[$i][$j] = $midA[$i] === $midB[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }
        $ops = array_map(static fn ($l) => [' ', $l], array_slice($a, 0, $start));
        $i = $j = 0;
        while ($i < $la && $j < $lb) {
            if ($midA[$i] === $midB[$j]) {
                $ops[] = [' ', $midA[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $midA[$i++]];
            } else {
                $ops[] = ['+', $midB[$j++]];
            }
        }
        while ($i < $la) {
            $ops[] = ['-', $midA[$i++]];
        }
        while ($j < $lb) {
            $ops[] = ['+', $midB[$j++]];
        }
        foreach (array_slice($a, $endA + 1) as $line) {
            $ops[] = [' ', $line];
        }
        return $ops;
    }
}

