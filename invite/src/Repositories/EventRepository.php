<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class EventRepository
{
    public function find(int $id): ?array
    {
        return Db::one('SELECT * FROM events WHERE id = ?', [$id]);
    }

    /** Includes soft-deleted events; callers decide whether that is acceptable. */
    public function findByUuid(string $uuid): ?array
    {
        return Db::one('SELECT * FROM events WHERE uuid = ?', [$uuid]);
    }

    public function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM events WHERE slug = ? AND deleted_at IS NULL', [$slug]);
    }

    public function settings(int $eventId): array
    {
        return Db::one('SELECT * FROM event_settings WHERE event_id = ?', [$eventId]) ?? [];
    }

    public function newSlug(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $slug = '';
            for ($i = 0; $i < 12; $i++) {
                $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Db::value('SELECT 1 FROM events WHERE slug = ?', [$slug]));
        return $slug;
    }

    /**
     * Events visible to a user across all their workspaces, newest-start first.
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, string $scope = 'all', ?string $q = null): array
    {
        $where = ['m.user_id = ?', 'e.deleted_at IS NULL'];
        $params = [$userId];
        if ($scope === 'upcoming') {
            $where[] = 'COALESCE(e.end_at, e.start_at) >= ?';
            $params[] = utcnow();
        } elseif ($scope === 'past') {
            $where[] = 'COALESCE(e.end_at, e.start_at) < ?';
            $params[] = utcnow();
        } elseif ($scope === 'draft') {
            $where[] = "e.status = 'draft'";
        }
        if ($q !== null && $q !== '') {
            $where[] = 'e.title LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $order = $scope === 'past' ? 'e.start_at DESC' : 'e.start_at ASC';
        return Db::all(
            "SELECT e.*, w.name AS workspace_name, m.role,
                    (SELECT COUNT(*) FROM event_guests eg WHERE eg.event_id = e.id) AS invited_count,
                    (SELECT COUNT(*) FROM rsvp_responses r WHERE r.event_id = e.id AND r.sub_event_id IS NULL AND r.status = 'attending') AS attending_count
             FROM events e
             JOIN workspace_members m ON m.workspace_id = e.workspace_id
             JOIN workspaces w ON w.id = e.workspace_id
             WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT 200",
            $params
        );
    }

    public function deletedForUser(int $userId): array
    {
        return Db::all(
            "SELECT e.* FROM events e JOIN workspace_members m ON m.workspace_id = e.workspace_id
             WHERE m.user_id = ? AND m.role IN ('owner','admin') AND e.deleted_at IS NOT NULL ORDER BY e.deleted_at DESC",
            [$userId]
        );
    }

    public function update(int $id, array $data): void
    {
        Db::update('events', $data + ['updated_at' => utcnow()], ['id' => $id]);
    }

    public function updateSettings(int $eventId, array $data): void
    {
        if ($data) {
            Db::update('event_settings', $data, ['event_id' => $eventId]);
        }
    }

    public function coverUrl(array $event): ?string
    {
        if (empty($event['cover_media_id'])) {
            return null;
        }
        $path = Db::value('SELECT path FROM media_assets WHERE id = ?', [$event['cover_media_id']]);
        return $path ? '/uploads/' . $path : null;
    }
}
