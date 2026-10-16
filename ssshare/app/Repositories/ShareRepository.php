<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;

final class ShareRepository
{
    public static function findLink(string $publicIdOrSlug): ?array
    {
        if (!preg_match('/^[A-Za-z0-9-]{3,64}$/', $publicIdOrSlug)) {
            return null;
        }
        return Database::first(
            'SELECT * FROM share_links WHERE public_id = ? OR custom_slug = ? LIMIT 1',
            [$publicIdOrSlug, strtolower($publicIdOrSlug)]
        );
    }

    public static function findLinkByPublicId(string $publicId): ?array
    {
        return Database::first('SELECT * FROM share_links WHERE public_id = ?', [$publicId]);
    }

    public static function upload(int $id): ?array
    {
        return Database::first('SELECT * FROM uploads WHERE id = ?', [$id]);
    }

    public static function uploadByUuid(string $uuid): ?array
    {
        return Database::first('SELECT * FROM uploads WHERE uuid = ?', [$uuid]);
    }

    public static function ownedUpload(string $uuid, int $userId): ?array
    {
        return Database::first('SELECT * FROM uploads WHERE uuid = ? AND owner_user_id = ?', [$uuid, $userId]);
    }

    public static function links(int $uploadId): array
    {
        return Database::select('SELECT * FROM share_links WHERE upload_id = ? ORDER BY id', [$uploadId]);
    }

    public static function file(int $fileId, int $uploadId): ?array
    {
        return Database::first('SELECT * FROM upload_files WHERE id = ? AND upload_id = ? AND deleted_at IS NULL', [$fileId, $uploadId]);
    }

    /**
     * Dashboard listing with filters.
     *
     * @return array{items:array, total:int}
     */
    public static function listForOwner(int $userId, array $filters, int $page, int $perPage): array
    {
        // Personal shares, or every share in a workspace the user belongs to.
        if (!empty($filters['workspace_id'])) {
            $where = ['u.workspace_id = ?'];
            $params = [(int) $filters['workspace_id']];
        } else {
            $where = ['u.owner_user_id = ?'];
            $params = [$userId];
        }

        $statuses = match ($filters['tab'] ?? 'active') {
            'expired' => ['expired'],
            'deleted' => ['revoked', 'deleted', 'blocked'],
            'scheduled' => ['active'],
            'all' => ['active', 'expired', 'revoked', 'deleted', 'blocked'],
            default => ['active'],
        };
        $where[] = 'u.status IN (' . Database::placeholders($statuses) . ')';
        array_push($params, ...$statuses);
        if (($filters['tab'] ?? '') === 'scheduled') {
            $where[] = 'l.starts_at IS NOT NULL AND l.starts_at > ?';
            $params[] = db_time();
        }

        if (in_array($filters['type'] ?? '', ['image', 'text', 'video', 'encrypted'], true)) {
            $where[] = 'u.content_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['folder'])) {
            $where[] = 'f.public_id = ?';
            $params[] = $filters['folder'];
        }
        if (($filters['protection'] ?? '') === 'password') {
            $where[] = "l.access_mode = 'password'";
        } elseif (($filters['protection'] ?? '') === 'burn') {
            $where[] = 'l.burn_after_view = 1';
        }
        if (($filters['expiring'] ?? '') === 'soon') {
            $where[] = 'l.expires_at IS NOT NULL AND l.expires_at <= ?';
            $params[] = db_time(now()->modify('+24 hours'));
        }
        if (!empty($filters['favorites'])) {
            $where[] = 'u.is_favorite = 1';
        }
        if (!empty($filters['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM upload_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.upload_id = u.id AND t.name = ?)';
            $params[] = mb_strtolower((string) $filters['tag']);
        }
        if (!empty($filters['q'])) {
            // Titles, file names, link IDs and text recognised in images (OCR).
            $q = (string) $filters['q'];
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.title LIKE ? OR l.public_id = ? OR uf.original_name LIKE ? OR u.ocr_text LIKE ?)';
            array_push($params, $like, $q, $like, $like);
        }

        $order = match ($filters['sort'] ?? 'newest') {
            'oldest' => 'u.created_at ASC',
            'views' => 'l.view_count DESC, u.created_at DESC',
            'size' => 'u.storage_bytes DESC',
            'expiry' => 'l.expires_at IS NULL, l.expires_at ASC',
            default => 'u.created_at DESC',
        };

        // The primary link is the first one created for the upload.
        $from = 'FROM uploads u
            JOIN share_links l ON l.id = (SELECT MIN(id) FROM share_links WHERE upload_id = u.id)
            LEFT JOIN folders f ON f.id = u.folder_id
            LEFT JOIN upload_files uf ON uf.upload_version_id = u.current_version_id AND uf.role IN (\'display\', \'text\', \'video\', \'cipher\')
            WHERE ' . implode(' AND ', $where);

        $total = (int) Database::value("SELECT COUNT(*) $from", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $items = Database::select(
            "SELECT u.*, l.id AS link_id, l.public_id AS link_public_id, l.custom_slug, l.status AS link_status, l.end_reason,
                    l.access_mode, l.burn_after_view, l.view_limit, l.view_limit_type, l.view_count, l.unique_view_count,
                    l.expires_at, l.starts_at, l.download_count, f.name AS folder_name, f.public_id AS folder_public_id,
                    uf.text_language, uf.original_name, uf.width, uf.height,
                    (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') FROM upload_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.upload_id = u.id) AS tag_list
             $from ORDER BY $order LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['items' => $items, 'total' => $total];
    }
}
