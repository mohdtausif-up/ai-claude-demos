<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Session;
use ZipArchive;

/** Multi-item collections and galleries with ordering, per-item overrides and combined expiry. */
final class CollectionService
{
    public const LAYOUTS = ['grid' => 'Grid', 'masonry' => 'Masonry', 'slideshow' => 'Slideshow', 'list' => 'List'];

    public function create(array $user, array $input): array
    {
        $data = $this->validate($user, $input, null);
        $now = db_time();
        $id = Database::insert('collections', $data + [
            'public_id' => Ids::publicId(10),
            'owner_user_id' => $user['id'],
            'workspace_id' => WorkspaceService::current($user)['id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $collection = Database::first('SELECT * FROM collections WHERE id = ?', [$id]);
        AuditLogService::record('collection.created', 'collection', $collection['public_id']);
        return $collection;
    }

    public function update(array $collection, array $user, array $input): array
    {
        $data = $this->validate($user, $input, $collection);
        Database::update('collections', $data + ['updated_at' => db_time()], 'id = ?', [$collection['id']]);
        AuditLogService::record('collection.updated', 'collection', $collection['public_id'], ['fields' => array_keys($data)]);
        return Database::first('SELECT * FROM collections WHERE id = ?', [$collection['id']]);
    }

    private function validate(array $user, array $input, ?array $existing): array
    {
        $errors = [];
        $data = [];
        if ($existing === null || array_key_exists('title', $input)) {
            $title = trim((string) ($input['title'] ?? ''));
            $title === '' || mb_strlen($title) > 160 ? $errors['title'] = 'Give the collection a title up to 160 characters.' : $data['title'] = $title;
        }
        if (array_key_exists('description', $input)) {
            $description = trim((string) $input['description']);
            mb_strlen($description) > 2000 ? $errors['description'] = 'Keep the description under 2000 characters.' : $data['description'] = $description ?: null;
        }
        if (array_key_exists('layout', $input)) {
            isset(self::LAYOUTS[$input['layout']]) ? $data['layout'] = $input['layout'] : $errors['layout'] = 'Choose a layout.';
        }
        if (array_key_exists('visibility', $input)) {
            $visibility = (string) $input['visibility'];
            if (!in_array($visibility, ['unlisted', 'public', 'private'], true)) {
                $errors['visibility'] = 'Choose unlisted, public or private.';
            } elseif ($visibility === 'public' && !PlanService::has($user, 'public_galleries')) {
                $errors['visibility'] = 'Public galleries are available on the Pro plan.';
            } else {
                $data['visibility'] = $visibility;
            }
        }
        if (array_key_exists('password', $input)) {
            $password = (string) $input['password'];
            if ($password !== '' && (mb_strlen($password) < 4 || mb_strlen($password) > 200)) {
                $errors['password'] = 'Passwords must be 4 to 200 characters.';
            } elseif ($password !== '') {
                $data['password_hash'] = PasswordService::hash($password);
            }
        }
        if (filter_var($input['remove_password'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $data['password_hash'] = null;
        }
        if (array_key_exists('custom_slug', $input)) {
            $slug = strtolower(trim((string) $input['custom_slug']));
            if ($slug === '') {
                $data['custom_slug'] = null;
            } elseif ($slug !== ($existing['custom_slug'] ?? null)) {
                ($error = UploadService::slugError($slug, $user)) !== null ? $errors['custom_slug'] = $error : $data['custom_slug'] = $slug;
            }
        }
        foreach (['allow_download_all', 'comments_enabled', 'expire_items'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $data[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (array_key_exists('branded', $input)) {
            $branded = filter_var($input['branded'], FILTER_VALIDATE_BOOLEAN);
            if ($branded && !PlanService::has($user, 'branding')) {
                $errors['branded'] = 'Branded collections are available on the Pro plan.';
            } else {
                $data['branded'] = $branded ? 1 : 0;
            }
        }
        if (array_key_exists('expires_in', $input) && $input['expires_in'] !== null && $input['expires_in'] !== '') {
            try {
                $policy = (new ExpirationService())->buildPolicy(['expires_in' => $input['expires_in']], true, UploadService::maxLifetime($user));
                $data['expires_at'] = $policy['expires_at'] !== null ? db_time($policy['expires_at']) : null;
            } catch (HttpException $e) {
                $errors['expires_in'] = $e->details['expires_in'] ?? 'Choose an expiry.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        return $data;
    }

    /** Adds the user's uploads. View-limited or burn-after-view shares cannot join a collection. */
    public function addItems(array $collection, array $user, array $uploadUuids): int
    {
        $added = 0;
        $position = (int) Database::value('SELECT COALESCE(MAX(position), 0) FROM collection_items WHERE collection_id = ?', [$collection['id']]);
        foreach (array_slice(array_unique($uploadUuids), 0, 500) as $uuid) {
            $upload = Database::first("SELECT * FROM uploads WHERE uuid = ? AND owner_user_id = ? AND status = 'active'", [$uuid, $user['id']]);
            if ($upload === null || in_array($upload['content_type'], ['encrypted'], true)) {
                continue;
            }
            $link = self::primaryLink((int) $upload['id']);
            if ($link === null || (int) $link['burn_after_view'] === 1 || $link['view_limit'] !== null) {
                throw HttpException::validation(['items' => 'View-once or view-limited shares cannot be added to a collection.']);
            }
            $added += Database::execute(
                'INSERT IGNORE INTO collection_items (collection_id, upload_id, position, created_at) VALUES (?, ?, ?, ?)',
                [$collection['id'], $upload['id'], ++$position, db_time()]
            );
        }
        if ($collection['cover_upload_id'] === null) {
            $first = Database::value("SELECT ci.upload_id FROM collection_items ci JOIN uploads u ON u.id = ci.upload_id WHERE ci.collection_id = ? AND u.content_type = 'image' ORDER BY ci.position LIMIT 1", [$collection['id']]);
            if ($first !== null) {
                Database::update('collections', ['cover_upload_id' => $first], 'id = ?', [$collection['id']]);
            }
        }
        return $added;
    }

    public function reorder(array $collection, array $uploadUuids): void
    {
        Database::transaction(function () use ($collection, $uploadUuids) {
            foreach (array_values($uploadUuids) as $i => $uuid) {
                Database::execute(
                    'UPDATE collection_items ci JOIN uploads u ON u.id = ci.upload_id SET ci.position = ? WHERE ci.collection_id = ? AND u.uuid = ?',
                    [$i + 1, $collection['id'], $uuid]
                );
            }
        });
    }

    public function removeItem(array $collection, string $uploadUuid): void
    {
        Database::execute('DELETE ci FROM collection_items ci JOIN uploads u ON u.id = ci.upload_id WHERE ci.collection_id = ? AND u.uuid = ?', [$collection['id'], $uploadUuid]);
    }

    /** Per-item overrides: hide, require an extra password, caption, or make it the cover. */
    public function updateItem(array $collection, string $uploadUuid, array $input): void
    {
        $item = Database::first('SELECT ci.* FROM collection_items ci JOIN uploads u ON u.id = ci.upload_id WHERE ci.collection_id = ? AND u.uuid = ?', [$collection['id'], $uploadUuid])
            ?? throw HttpException::notFound('Item not found.');
        $changes = [];
        if (array_key_exists('hidden', $input)) {
            $changes['hidden'] = filter_var($input['hidden'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('caption', $input)) {
            $caption = trim((string) $input['caption']);
            if (mb_strlen($caption) > 500) {
                throw HttpException::validation(['caption' => 'Captions can be up to 500 characters.']);
            }
            $changes['caption'] = $caption ?: null;
        }
        if (array_key_exists('password', $input)) {
            $password = (string) $input['password'];
            if ($password !== '' && mb_strlen($password) < 4) {
                throw HttpException::validation(['password' => 'Passwords must be at least 4 characters.']);
            }
            $changes['password_hash'] = $password === '' ? null : PasswordService::hash($password);
        }
        if ($changes !== []) {
            Database::update('collection_items', $changes, 'id = ?', [$item['id']]);
        }
        if (filter_var($input['cover'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            Database::update('collections', ['cover_upload_id' => $item['upload_id']], 'id = ?', [$collection['id']]);
        }
    }

    public function delete(array $collection): void
    {
        Database::update('collections', ['status' => 'deleted', 'updated_at' => db_time()], 'id = ?', [$collection['id']]);
        AuditLogService::record('collection.deleted', 'collection', $collection['public_id']);
    }

    public static function find(string $idOrSlug): ?array
    {
        return Database::first('SELECT * FROM collections WHERE (public_id = ? OR custom_slug = ?) AND status <> ?', [$idOrSlug, strtolower($idOrSlug), 'deleted']);
    }

    public static function owned(string $publicId, int $userId): ?array
    {
        return Database::first("SELECT * FROM collections WHERE public_id = ? AND owner_user_id = ? AND status <> 'deleted'", [$publicId, $userId]);
    }

    public static function primaryLink(int $uploadId): ?array
    {
        return Database::first("SELECT * FROM share_links WHERE upload_id = ? AND status IN ('active', 'paused') ORDER BY id LIMIT 1", [$uploadId]);
    }

    /** Items visible in a collection (active uploads with a live link), in order. */
    public static function items(array $collection, bool $includeHidden = false): array
    {
        return Database::select(
            "SELECT ci.*, u.uuid, u.title, u.content_type, u.status AS upload_status, u.comments_enabled,
                    f.id AS file_id, f.ext, f.width, f.height, f.text_language, f.original_name, f.bytes,
                    l.public_id AS link_public_id, l.status AS link_status
             FROM collection_items ci
             JOIN uploads u ON u.id = ci.upload_id
             JOIN upload_files f ON f.upload_version_id = u.current_version_id AND f.role IN ('display', 'text', 'video')
             JOIN share_links l ON l.id = (SELECT MIN(id) FROM share_links WHERE upload_id = u.id AND status = 'active')
             WHERE ci.collection_id = ? AND u.status = 'active'" . ($includeHidden ? '' : ' AND ci.hidden = 0') . '
             ORDER BY ci.position, ci.id',
            [$collection['id']]
        );
    }

    public static function isUnlocked(array $collection): bool
    {
        return $collection['password_hash'] === null || (Session::get('unlocked_collections')[$collection['public_id']] ?? null) === hash('sha256', $collection['password_hash']);
    }

    public static function unlock(array $collection, string $password): bool
    {
        if ($collection['password_hash'] === null) {
            return true;
        }
        \App\Support\RateLimiter::enforce('collection-pw:' . $collection['id'] . ':' . ip_hash(), 5, 900, 'Too many incorrect attempts. Try again in 15 minutes.');
        if (!PasswordService::verify($password, $collection['password_hash'])) {
            return false;
        }
        $unlocked = Session::get('unlocked_collections', []);
        $unlocked[$collection['public_id']] = hash('sha256', $collection['password_hash']);
        Session::put('unlocked_collections', $unlocked);
        return true;
    }

    public static function itemUnlocked(array $item): bool
    {
        return $item['password_hash'] === null || (Session::get('unlocked_items')[$item['id']] ?? null) === hash('sha256', $item['password_hash']);
    }

    public static function unlockItem(array $item, string $password): bool
    {
        \App\Support\RateLimiter::enforce('collection-item-pw:' . $item['id'] . ':' . ip_hash(), 5, 900, 'Too many incorrect attempts. Try again in 15 minutes.');
        if ($item['password_hash'] === null || !PasswordService::verify($password, $item['password_hash'])) {
            return false;
        }
        $unlocked = Session::get('unlocked_items', []);
        $unlocked[$item['id']] = hash('sha256', $item['password_hash']);
        Session::put('unlocked_items', $unlocked);
        return true;
    }

    /** Builds a ZIP of every unlocked item and returns its temporary path. */
    public static function zip(array $collection, array $items): string
    {
        $path = tempnam(config('app.storage.tmp_path'), 'zip');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create archive');
        }
        $names = [];
        $storage = StorageService::driver();
        foreach ($items as $n => $item) {
            if (!self::itemUnlocked($item)) {
                continue;
            }
            $file = Database::first('SELECT * FROM upload_files WHERE id = ? AND deleted_at IS NULL', [$item['file_id']]);
            if ($file === null) {
                continue;
            }
            $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($item['title'] ?? $item['original_name'] ?? 'item', PATHINFO_FILENAME)) ?: 'item';
            $name = sprintf('%02d-%s.%s', $n + 1, $base, $file['ext']);
            while (isset($names[$name])) {
                $name = sprintf('%02d-%s-%s.%s', $n + 1, $base, bin2hex(random_bytes(2)), $file['ext']);
            }
            $names[$name] = true;
            $zip->addFromString($name, $storage->get($file['object_key']));
        }
        $zip->close();
        return $path;
    }

    /** Ends expired collections; optionally revokes the items they contained. */
    public function sweep(): int
    {
        $expired = Database::select("SELECT * FROM collections WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= ?", [db_time()]);
        foreach ($expired as $collection) {
            Database::update('collections', ['status' => 'expired', 'updated_at' => db_time()], 'id = ?', [$collection['id']]);
            if ((int) $collection['expire_items'] === 1) {
                $deletion = new DeletionService();
                foreach (Database::select("SELECT u.* FROM collection_items ci JOIN uploads u ON u.id = ci.upload_id WHERE ci.collection_id = ? AND u.status = 'active'", [$collection['id']]) as $upload) {
                    $deletion->deleteNow($upload, 'collection_expired');
                }
            }
            AuditLogService::record('collection.expired', 'collection', $collection['public_id'], [], 'system');
        }
        return count($expired);
    }
}
