<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Documented retention policy (docs/RETENTION.md):
 *  - Deleting an event soft-deletes it; hosts can restore it for RETENTION_DAYS (default 30).
 *  - After that window the event and everything under it (guests' RSVPs, answers, check-ins, messages) is permanently
 *    removed, and guest address-book rows that no longer belong to any event are anonymised.
 */
final class RetentionService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public static function days(): int
    {
        return max(1, (int) env('RETENTION_DAYS', 30));
    }

    /** @return int number of events purged */
    public function purgeDeletedEvents(): int
    {
        $cutoff = utcnow(-self::days() * 86400);
        $events = Db::all('SELECT id, uuid, workspace_id FROM events WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]);
        foreach ($events as $e) {
            Db::transaction(function () use ($e) {
                // Keep the people rows but strip personal data when no other event references them.
                $guestIds = Db::column('SELECT guest_id FROM event_guests WHERE event_id = ?', [$e['id']]);
                // Uploaded images (cover art, gallery, album) live on disk: remove them with the event.
                foreach (Db::column('SELECT path FROM media_assets WHERE event_id = ?', [$e['id']]) as $path) {
                    @unlink(BASE_PATH . '/public/uploads/' . $path);
                }
                @rmdir(BASE_PATH . '/public/uploads/' . $e['uuid']);
                Db::exec('DELETE FROM events WHERE id = ?', [$e['id']]); // cascades to everything event-scoped
                foreach ($guestIds as $gid) {
                    if ((int) Db::value('SELECT COUNT(*) FROM event_guests WHERE guest_id = ?', [$gid]) === 0) {
                        $this->anonymizeGuestRow((int) $gid);
                    }
                }
            });
            $this->audit->log('event.purged', ['workspace_id' => (int) $e['workspace_id'], 'actor_type' => 'system', 'entity_type' => 'event', 'entity_uuid' => $e['uuid']]);
        }
        return count($events);
    }

    public function anonymizeGuestRow(int $guestId): void
    {
        Db::exec(
            "UPDATE guests SET first_name = 'Anonymised', last_name = NULL, email = NULL, phone = NULL, anonymized_at = ?, updated_at = ? WHERE id = ?",
            [utcnow(), utcnow(), $guestId]
        );
    }
}
