<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;

/**
 * Resumable uploads (tus-style): create a session, PATCH chunks with an Upload-Offset,
 * query the offset after a network failure, and the share is created when the last
 * byte arrives. Parts live in storage/tmp/resumable until completion or expiry.
 */
final class ResumableUploadService
{
    private const TTL_HOURS = 24;

    public static function dir(): string
    {
        $dir = config('app.storage.tmp_path') . '/resumable';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /** @return array{session:array, token:string} */
    public function create(?array $user, int $size, ?string $filename, array $options): array
    {
        $max = max(
            $user !== null ? (int) PlanService::forUser($user)['max_file_bytes'] : (int) config('app.uploads.max_file_bytes'),
            (int) config('app.uploads.max_video_bytes')
        );
        if ($size < 1 || $size > $max) {
            throw new HttpException(413, 'file_too_large', 'Files can be up to ' . format_bytes($max) . '.');
        }
        if ($user === null && !config('app.uploads.anonymous_enabled')) {
            throw new HttpException(401, 'login_required', 'Sign in to share content.');
        }
        // Validate settings now so a long upload never fails at the very end.
        (new ExpirationService())->buildPolicy($options, $user !== null, UploadService::maxLifetime($user));
        $token = Ids::token(24);
        $id = Database::insert('upload_sessions', [
            'public_id' => Ids::publicId(20),
            'user_id' => $user['id'] ?? null,
            'ip_hash' => ip_hash(),
            'token_hash' => Ids::hashToken($token),
            'total_bytes' => $size,
            'filename' => $filename !== null ? substr(basename($filename), 0, 255) : null,
            'options' => json_encode(array_intersect_key($options, array_flip([
                'title', 'password', 'expires_in', 'expires_at', 'burn_after_view', 'view_limit', 'view_limit_type',
                'download_limit', 'inactive_after', 'folder_id', 'custom_slug', 'disable_download', 'audience', 'audience_list',
                'recipients', 'max_sessions', 'download_for', 'comments_enabled', 'anonymous_comments', 'visibility',
            ]))),
            'expires_at' => db_time(now()->modify('+' . self::TTL_HOURS . ' hours')),
            'created_at' => db_time(),
            'updated_at' => db_time(),
        ]);
        touch(self::dir() . '/' . Database::value('SELECT public_id FROM upload_sessions WHERE id = ?', [$id]) . '.part');
        return ['session' => Database::first('SELECT * FROM upload_sessions WHERE id = ?', [$id]), 'token' => $token];
    }

    public static function find(string $publicId, ?array $user, ?string $token): array
    {
        $session = Database::first('SELECT * FROM upload_sessions WHERE public_id = ?', [$publicId]);
        $owned = $session !== null && (
            ($user !== null && (int) $session['user_id'] === (int) $user['id'])
            || ($token !== null && hash_equals($session['token_hash'], Ids::hashToken($token)))
        );
        if (!$owned) {
            throw HttpException::notFound('Upload session not found.');
        }
        if ($session['status'] === 'open' && strtotime($session['expires_at'] . ' UTC') < now()->getTimestamp()) {
            throw new HttpException(410, 'session_expired', 'This upload session expired. Start again.');
        }
        return $session;
    }

    /**
     * Appends a chunk at the given offset. Returns the session and, when complete, the share.
     *
     * @return array{session:array, result:?array}
     */
    public function append(array $session, int $offset, string $chunk, ?array $user): array
    {
        if ($session['status'] !== 'open') {
            throw new HttpException(409, 'invalid_state', 'This upload session is ' . $session['status'] . '.');
        }
        $chunkLimit = (int) config('app.uploads.resumable_chunk_bytes');
        if (strlen($chunk) > $chunkLimit) {
            throw new HttpException(413, 'chunk_too_large', 'Chunks can be up to ' . format_bytes($chunkLimit) . '.');
        }
        $path = self::dir() . '/' . $session['public_id'] . '.part';
        $handle = fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new HttpException(503, 'busy', 'Upload storage is busy. Retry the chunk.');
        }
        try {
            clearstatcache(true, $path);
            $received = filesize($path);
            if ($offset !== $received) {
                throw new HttpException(409, 'offset_mismatch', 'Upload-Offset does not match. Resume from the current offset.', ['offset' => $received], ['Upload-Offset' => (string) $received]);
            }
            if ($received + strlen($chunk) > (int) $session['total_bytes']) {
                throw new HttpException(413, 'too_much_data', 'The chunk goes past the declared file size.');
            }
            fseek($handle, $received);
            fwrite($handle, $chunk);
            fflush($handle);
            $received += strlen($chunk);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        Database::update('upload_sessions', ['received_bytes' => $received, 'updated_at' => db_time()], 'id = ?', [$session['id']]);
        $session['received_bytes'] = $received;

        $result = null;
        if ($received === (int) $session['total_bytes']) {
            $result = $this->complete($session, $user);
            $session['status'] = 'complete';
        }
        return ['session' => $session, 'result' => $result];
    }

    private function complete(array $session, ?array $user): array
    {
        $path = self::dir() . '/' . $session['public_id'] . '.part';
        $bytes = (string) file_get_contents($path);
        $options = json_decode($session['options'], true) ?: [];
        $uploads = new UploadService();
        try {
            $result = VideoService::looksLikeVideo($bytes)
                ? $uploads->createVideo($bytes, $session['filename'], $options, $user, 'web')
                : $uploads->createImage($bytes, $session['filename'], $options, $user, 'web');
        } catch (HttpException $e) {
            Database::update('upload_sessions', ['status' => 'aborted', 'updated_at' => db_time()], 'id = ?', [$session['id']]);
            @unlink($path);
            throw $e;
        }
        Database::update('upload_sessions', ['status' => 'complete', 'upload_id' => $result['upload']['id'], 'updated_at' => db_time()], 'id = ?', [$session['id']]);
        @unlink($path);
        return $result;
    }

    public function abort(array $session): void
    {
        Database::update('upload_sessions', ['status' => 'aborted', 'updated_at' => db_time()], 'id = ?', [$session['id']]);
        @unlink(self::dir() . '/' . $session['public_id'] . '.part');
    }

    /** Removes abandoned parts. */
    public function sweep(): int
    {
        $stale = Database::select("SELECT * FROM upload_sessions WHERE status = 'open' AND expires_at < ?", [db_time()]);
        foreach ($stale as $session) {
            $this->abort($session);
        }
        return count($stale);
    }
}
