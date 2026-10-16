<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** Shared event album: guest uploads (optionally reviewed), host moderation, download. */
final class AlbumService
{
    public function __construct(private ImageService $images, private RateLimiter $limiter)
    {
    }

    /** @param array|null $eg uploading guest, null for the public QR poster */
    public function submit(array $event, array $settings, ?array $eg, array $file, string $caption, string $ip): int
    {
        if (!(int) $settings['album_enabled']) {
            throw new ValidationException(['file' => ['The photo album is not turned on for this event.']]);
        }
        $this->limiter->enforce('album:' . ($eg['id'] ?? 'ip' . $ip), $eg ? 20 : 8, 3600);
        $total = (int) Db::value('SELECT COUNT(*) FROM guest_media WHERE event_id = ? AND status <> ?', [$event['id'], 'rejected']);
        if ($total >= 500) {
            throw new ValidationException(['file' => ['This album is full.']]);
        }
        $res = $this->images->store($event, null, $file, null);
        // Anonymous poster uploads are always reviewed; guests are reviewed when the host asks for it.
        $status = $eg !== null && !(int) $settings['album_moderated'] ? 'approved' : 'pending';
        return Db::insert('guest_media', [
            'uuid' => uuid4(), 'event_id' => $event['id'], 'event_guest_id' => $eg['id'] ?? null, 'media_id' => $res['id'],
            'caption' => $caption !== '' ? mb_substr($caption, 0, 200) : null, 'status' => $status, 'created_at' => utcnow(),
        ]);
    }

    public function list(int $eventId, ?string $status = 'approved', ?int $ownerEgId = null): array
    {
        $where = 'gm.event_id = ?';
        $params = [$eventId];
        if ($status !== null) {
            $where .= $ownerEgId ? ' AND (gm.status = ? OR (gm.event_guest_id = ? AND gm.status = \'pending\'))' : ' AND gm.status = ?';
            array_push($params, $status);
            if ($ownerEgId) {
                $params[] = $ownerEgId;
            }
        }
        return Db::all(
            "SELECT gm.uuid, gm.caption, gm.status, gm.created_at, m.path, m.width, m.height, g.first_name
             FROM guest_media gm JOIN media_assets m ON m.id = gm.media_id LEFT JOIN event_guests eg ON eg.id = gm.event_guest_id LEFT JOIN guests g ON g.id = eg.guest_id
             WHERE $where ORDER BY gm.id DESC LIMIT 500",
            $params
        );
    }

    public function counts(int $eventId): array
    {
        return array_column(Db::all('SELECT status, COUNT(*) n FROM guest_media WHERE event_id = ? GROUP BY status', [$eventId]), 'n', 'status');
    }

    public function moderate(int $eventId, string $uuid, string $status): void
    {
        if (in_array($status, ['approved', 'rejected', 'pending'], true)) {
            Db::exec('UPDATE guest_media SET status = ? WHERE event_id = ? AND uuid = ?', [$status, $eventId, $uuid]);
        }
    }

    public function delete(int $eventId, string $uuid): void
    {
        $row = Db::one('SELECT gm.id, m.id AS media_id, m.path FROM guest_media gm JOIN media_assets m ON m.id = gm.media_id WHERE gm.event_id = ? AND gm.uuid = ?', [$eventId, $uuid]);
        if ($row) {
            @unlink(BASE_PATH . '/public/uploads/' . $row['path']);
            Db::exec('DELETE FROM media_assets WHERE id = ?', [$row['media_id']]); // cascades to guest_media
        }
    }

    /** Stream every approved photo as a ZIP. */
    public function streamZip(int $eventId, string $slug): void
    {
        $zip = new ZipWriter(static function (string $b): void {
            echo $b;
            @flush();
        });
        foreach ($this->list($eventId, 'approved') as $i => $p) {
            $file = BASE_PATH . '/public/uploads/' . $p['path'];
            if (is_file($file)) {
                $zip->addFile(sprintf('%s-%03d.%s', $slug, $i + 1, pathinfo($p['path'], PATHINFO_EXTENSION)), $file);
            }
        }
        $zip->finish();
    }
}