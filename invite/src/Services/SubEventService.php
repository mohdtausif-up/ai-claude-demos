<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** Functions within an event (Haldi, Mehndi, Sangeet, Reception…): own time, venue, capacity and guest list. */
final class SubEventService
{
    /** @return list<array<string,mixed>> with 'eligible' count and 'taken' headcount */
    public function forEvent(int $eventId): array
    {
        return Db::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM sub_event_guests sg WHERE sg.sub_event_id = s.id) AS selected_count,
                    (SELECT COALESCE(SUM(1 + r.plus_ones + r.children), 0) FROM rsvp_responses r WHERE r.sub_event_id = s.id AND r.status = 'attending') AS taken
             FROM sub_events s WHERE s.event_id = ? ORDER BY s.position, s.start_at, s.id",
            [$eventId]
        );
    }

    public function findByUuid(int $eventId, string $uuid): ?array
    {
        return Db::one('SELECT * FROM sub_events WHERE event_id = ? AND uuid = ?', [$eventId, $uuid]);
    }

    /** @param array<string,mixed> $d validated: name, description, start_at, end_at, venue_*, map_url, capacity, rsvp_open, eligibility */
    public function save(array $event, array $d, ?int $id = null): int
    {
        $tz = $event['timezone'];
        if (!empty($d['end_at']) && $d['end_at'] <= $d['start_at']) {
            throw new ValidationException(['end_at' => ['End must be after the start.']], $d);
        }
        $row = [
            'name' => $d['name'], 'description' => $d['description'] ?? null,
            'start_at' => to_utc($d['start_at'], $tz), 'end_at' => !empty($d['end_at']) ? to_utc($d['end_at'], $tz) : null,
            'venue_name' => $d['venue_name'] ?? null, 'venue_address' => $d['venue_address'] ?? null, 'map_url' => $d['map_url'] ?? null,
            'capacity' => $d['capacity'] ?? null, 'rsvp_open' => !empty($d['rsvp_open']) ? 1 : 0,
            'eligibility' => ($d['eligibility'] ?? 'all') === 'selected' ? 'selected' : 'all',
        ];
        if ($id === null) {
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM sub_events WHERE event_id = ?', [$event['id']]);
            return Db::insert('sub_events', $row + ['uuid' => uuid4(), 'event_id' => $event['id'], 'position' => $pos, 'created_at' => utcnow()]);
        }
        Db::update('sub_events', $row, ['id' => $id, 'event_id' => $event['id']]);
        return $id;
    }

    public function delete(int $eventId, int $id): void
    {
        Db::exec('DELETE FROM sub_events WHERE id = ? AND event_id = ?', [$id, $eventId]);
    }

    /** Sub-events a guest may respond to. */
    public function eligibleFor(int $eventId, int $eventGuestId): array
    {
        return Db::all(
            "SELECT s.* FROM sub_events s
             WHERE s.event_id = ? AND (s.eligibility = 'all' OR EXISTS (SELECT 1 FROM sub_event_guests g WHERE g.sub_event_id = s.id AND g.event_guest_id = ?))
             ORDER BY s.position, s.start_at",
            [$eventId, $eventGuestId]
        );
    }

    /** @param list<string> $guestUuids */
    public function setEligible(array $event, int $subEventId, string $mode, array $guestUuids = [], ?string $groupUuid = null, ?string $tag = null): int
    {
        Db::transaction(function () use ($event, $subEventId, $mode, $guestUuids, $groupUuid, $tag) {
            if ($mode === 'all') {
                Db::update('sub_events', ['eligibility' => 'all'], ['id' => $subEventId]);
                Db::exec('DELETE FROM sub_event_guests WHERE sub_event_id = ?', [$subEventId]);
                return;
            }
            Db::update('sub_events', ['eligibility' => 'selected'], ['id' => $subEventId]);
            $ids = [];
            if ($groupUuid) {
                $ids = array_merge($ids, Db::column('SELECT eg.id FROM event_guests eg JOIN guest_groups g ON g.id = eg.group_id WHERE eg.event_id = ? AND g.uuid = ?', [$event['id'], $groupUuid]));
            }
            if ($tag) {
                $ids = array_merge($ids, Db::column('SELECT eg.id FROM event_guests eg JOIN guest_tags t ON t.event_guest_id = eg.id WHERE eg.event_id = ? AND t.tag = ?', [$event['id'], $tag]));
            }
            $guestUuids = array_values(array_filter($guestUuids, 'is_uuid'));
            if ($guestUuids) {
                $ids = array_merge($ids, Db::column('SELECT id FROM event_guests WHERE event_id = ? AND uuid IN (' . Db::placeholders($guestUuids) . ')', [$event['id'], ...$guestUuids]));
            }
            foreach (array_unique($ids) as $id) {
                Db::run('INSERT IGNORE INTO sub_event_guests (sub_event_id, event_guest_id) VALUES (?, ?)', [$subEventId, $id]);
            }
        });
        return (int) Db::value('SELECT COUNT(*) FROM sub_event_guests WHERE sub_event_id = ?', [$subEventId]);
    }

    public function clearEligible(int $subEventId, string $egUuid): void
    {
        Db::exec('DELETE g FROM sub_event_guests g JOIN event_guests eg ON eg.id = g.event_guest_id WHERE g.sub_event_id = ? AND eg.uuid = ?', [$subEventId, $egUuid]);
    }

    public function eligibleGuests(int $subEventId): array
    {
        return Db::all(
            'SELECT eg.uuid, g.first_name, g.last_name FROM sub_event_guests sg JOIN event_guests eg ON eg.id = sg.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE sg.sub_event_id = ? ORDER BY g.first_name LIMIT 300',
            [$subEventId]
        );
    }

    /** A guest's current per-function responses: sub_event_id => status. */
    public function responsesFor(int $eventGuestId): array
    {
        $out = [];
        foreach (Db::all('SELECT sub_event_id, status FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_id IS NOT NULL', [$eventGuestId]) as $r) {
            $out[(int) $r['sub_event_id']] = $r['status'];
        }
        return $out;
    }
}
