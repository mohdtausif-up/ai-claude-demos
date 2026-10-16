<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use ZipArchive;

/** Data export and account deletion (privacy rights). */
final class AccountService
{
    public function requestExport(array $user): array
    {
        if (Database::value("SELECT 1 FROM data_exports WHERE user_id = ? AND status = 'pending'", [$user['id']]) !== null) {
            throw new HttpException(409, 'invalid_state', 'An export is already being prepared.');
        }
        $id = Database::insert('data_exports', ['public_id' => Ids::publicId(20), 'user_id' => $user['id'], 'created_at' => db_time()]);
        AuditLogService::record('user.export_requested', 'user', $user['uuid']);
        return Database::first('SELECT * FROM data_exports WHERE id = ?', [$id]);
    }

    /** Builds pending exports (run by the scheduler). */
    public function buildPending(): int
    {
        $built = 0;
        foreach (Database::select("SELECT * FROM data_exports WHERE status = 'pending' ORDER BY id LIMIT 3") as $export) {
            try {
                $this->build($export);
                $built++;
            } catch (\Throwable $e) {
                Database::update('data_exports', ['status' => 'failed'], 'id = ?', [$export['id']]);
                error_log('Export failed: ' . $e->getMessage());
            }
        }
        foreach (Database::select("SELECT * FROM data_exports WHERE status = 'ready' AND expires_at < ?", [db_time()]) as $old) {
            StorageService::driver()->delete($old['object_key']);
            Database::update('data_exports', ['status' => 'expired'], 'id = ?', [$old['id']]);
        }
        return $built;
    }

    public function build(array $export): void
    {
        $user = Database::first('SELECT * FROM users WHERE id = ?', [$export['user_id']]);
        $tmp = tempnam(config('app.storage.tmp_path'), 'export');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $json = static fn ($data) => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $zip->addFromString('account.json', $json([
            'id' => $user['uuid'], 'email' => $user['email'], 'name' => $user['display_name'], 'created_at' => SharePresenter::iso($user['created_at']),
            'two_factor_enabled' => $user['totp_enabled_at'] !== null,
            'workspaces' => array_map(static fn ($w) => ['id' => $w['public_id'], 'name' => $w['name'], 'role' => $w['member_role']], WorkspaceService::forUser($user)),
            'api_keys' => Database::select('SELECT name, prefix, scopes, created_at, last_used_at, revoked_at FROM api_keys WHERE user_id = ?', [$user['id']]),
            'sessions' => Database::select('SELECT device, browser, method, created_at, last_seen_at FROM user_sessions WHERE user_id = ?', [$user['id']]),
        ]));
        $uploads = Database::select('SELECT * FROM uploads WHERE owner_user_id = ? ORDER BY id', [$user['id']]);
        $storage = StorageService::driver();
        $manifest = [];
        foreach ($uploads as $upload) {
            $file = UploadService::primaryFile((int) $upload['id']);
            $entry = SharePresenter::upload($upload, $file, Database::select('SELECT * FROM share_links WHERE upload_id = ?', [$upload['id']]));
            if ($upload['status'] === 'active' && $file !== null && $file['deleted_at'] === null && $upload['content_type'] !== 'encrypted') {
                $name = 'content/' . $upload['uuid'] . '.' . $file['ext'];
                $zip->addFromString($name, $storage->get($file['object_key']));
                $entry['export_file'] = $name;
            }
            $entry['comments'] = CommentService::forUpload((int) $upload['id']);
            $manifest[] = $entry;
        }
        $zip->addFromString('uploads.json', $json($manifest));
        $zip->addFromString('comments_written.json', $json(Database::select('SELECT public_id, upload_id, body, created_at FROM comments WHERE author_user_id = ?', [$user['id']])));
        $zip->addFromString('audit.json', $json(Database::select('SELECT action, target_type, target_id, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id', [$user['id']])));
        $zip->close();

        $key = 'exports/' . $export['public_id'] . '.zip';
        $storage->put($key, (string) file_get_contents($tmp));
        $bytes = filesize($tmp);
        @unlink($tmp);
        Database::update('data_exports', ['status' => 'ready', 'object_key' => $key, 'bytes' => $bytes, 'expires_at' => db_time(now()->modify('+24 hours'))], 'id = ?', [$export['id']]);
        NotificationService::notify((int) $user['id'], 'export', 'Your data export is ready', null, '/account/privacy', 'data_export', ['url' => url('account/privacy')]);
    }

    /** Deletes the account and all of its content. Workspaces with other members must be handed over first. */
    public function delete(array $user, string $password): void
    {
        if (!PasswordService::verify($password, $user['password_hash'])) {
            throw HttpException::validation(['password' => 'That password is incorrect.']);
        }
        $owned = Database::select(
            'SELECT w.* FROM workspaces w WHERE w.owner_user_id = ? AND (SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id) > 1',
            [$user['id']]
        );
        if ($owned !== []) {
            throw new HttpException(409, 'invalid_state', 'Transfer or remove the other members of “' . $owned[0]['name'] . '” before deleting your account.');
        }
        $deletion = new DeletionService();
        foreach (Database::select("SELECT * FROM uploads WHERE owner_user_id = ? AND status <> 'deleted'", [$user['id']]) as $upload) {
            $deletion->deleteNow($upload, 'account_deleted');
        }
        foreach (Database::select('SELECT object_key FROM data_exports WHERE user_id = ? AND object_key IS NOT NULL', [$user['id']]) as $export) {
            StorageService::driver()->delete($export['object_key']);
        }
        AuditLogService::record('user.deleted', 'user', $user['uuid'], [], 'user', (int) $user['id']);
        Database::transaction(function () use ($user) {
            Database::execute('UPDATE comments SET author_user_id = NULL, author_name = ? WHERE author_user_id = ?', ['Deleted user', $user['id']]);
            Database::execute('DELETE FROM workspaces WHERE owner_user_id = ?', [$user['id']]);
            Database::execute('DELETE FROM users WHERE id = ?', [$user['id']]);
        });
    }
}
