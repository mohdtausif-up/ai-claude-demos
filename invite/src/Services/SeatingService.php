<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** Tables and seat assignment. A guest is seated with their whole party (guest + plus-ones + children). */
final class SeatingService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> tables with seated parties and headcount used */
    public function tables(int $eventId): array
    {
        $tables = Db::all('SELECT * FROM seating_tables WHERE event_id = ? ORDER BY position, id', [$eventId]);
        $seated = Db::all(
            "SELECT sa.table_id, eg.uuid AS guest_uuid, g.first_name, g.last_name, eg.anonymized_at, gr.name AS group_name,
                    COALESCE(1 + r.plus_ones + r.children, 1) AS party, r.status
             FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id JOIN event_guests eg ON eg.id = sa.event_guest_id JOIN guests g ON g.id = eg.guest_id
             LEFT JOIN guest_groups gr ON gr.id = eg.group_id LEFT JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0
             WHERE t.event_id = ? ORDER BY g.first_name",
            [$eventId]
        );
        $by = [];
        foreach ($seated as $s) {
            $by[$s['table_id']][] = $s;
        }
        foreach ($tables as &$t) {
            $t['guests'] = $by[$t['id']] ?? [];
            $t['used'] = array_sum(array_column($t['guests'], 'party'));
            $t['free'] = (int) $t['capacity'] - $t['used'];
        }
        return $tables;
    }

    /** Attending guests without a seat, with party size. */
    public function unassigned(int $eventId): array
    {
        return Db::all(
            "SELECT eg.uuid, eg.id, eg.group_id, g.first_name, g.last_name, gr.name AS group_name, 1 + r.plus_ones + r.children AS party
             FROM event_guests eg JOIN guests g ON g.id = eg.guest_id JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0 AND r.status = 'attending'
             LEFT JOIN guest_groups gr ON gr.id = eg.group_id LEFT JOIN seat_assignments sa ON sa.event_guest_id = eg.id
             WHERE eg.event_id = ? AND sa.id IS NULL AND eg.anonymized_at IS NULL ORDER BY gr.name, g.first_name",
            [$eventId]
        );
    }

    public function addTable(array $event, string $name, int $capacity): int
    {
        $name = trim($name);
        if ($name === '' || $capacity < 1 || $capacity > 500) {
            throw new ValidationException(['name' => ['Give the table a name and a capacity between 1 and 500.']]);
        }
        $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM seating_tables WHERE event_id = ?', [$event['id']]);
        return Db::insert('seating_tables', ['uuid' => uuid4(), 'event_id' => $event['id'], 'name' => mb_substr($name, 0, 80), 'capacity' => $capacity, 'position' => $pos]);
    }

    /** Create N tables named "Table 1…N". */
    public function addMany(array $event, int $count, int $capacity): void
    {
        $start = (int) Db::value('SELECT COUNT(*) FROM seating_tables WHERE event_id = ?', [$event['id']]);
        for ($i = 1; $i <= min(60, $count); $i++) {
            $this->addTable($event, 'Table ' . ($start + $i), $capacity);
        }
    }

    public function findTable(int $eventId, string $uuid): ?array
    {
        return is_uuid($uuid) ? Db::one('SELECT * FROM seating_tables WHERE event_id = ? AND uuid = ?', [$eventId, $uuid]) : null;
    }

    public function updateTable(array $table, string $name, int $capacity, ?string $notes): void
    {
        Db::update('seating_tables', ['name' => mb_substr(trim($name), 0, 80) ?: $table['name'], 'capacity' => max(1, min(500, $capacity)), 'notes' => $notes !== null ? mb_substr($notes, 0, 200) : null], ['id' => $table['id']]);
    }

    public function deleteTable(array $table): void
    {
        Db::exec('DELETE FROM seating_tables WHERE id = ?', [$table['id']]);
    }

    private function used(int $tableId): int
    {
        return (int) Db::value("SELECT COALESCE(SUM(1 + r.plus_ones + r.children), 0) FROM seat_assignments sa JOIN rsvp_responses r ON r.event_guest_id = sa.event_guest_id AND r.sub_event_scope = 0 WHERE sa.table_id = ?", [$tableId]);
    }

    /** @throws ValidationException when the party does not fit (unless $force) */
    public function assign(array $event, array $table, array $eg, bool $force = false): void
    {
        $party = (int) Db::value("SELECT 1 + plus_ones + children FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_scope = 0 AND status = 'attending'", [$eg['id']]);
        if ($party < 1) {
            throw new ValidationException(['guest' => ['Only guests who are attending can be seated.']]);
        }
        Db::transaction(function () use ($table, $eg, $party, $force) {
            Db::value('SELECT id FROM seating_tables WHERE id = ? FOR UPDATE', [$table['id']]);
            $current = Db::value('SELECT table_id FROM seat_assignments WHERE event_guest_id = ?', [$eg['id']]);
            $used = $this->used((int) $table['id']) - ((int) $current === (int) $table['id'] ? $party : 0);
            if (!$force && $used + $party > (int) $table['capacity']) {
                throw new ValidationException(['guest' => [sprintf('%s has %d seat(s) left; this party is %d.', $table['name'], max(0, $table['capacity'] - $used), $party)]]);
            }
            Db::run('INSERT INTO seat_assignments (table_id, event_guest_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE table_id = VALUES(table_id)', [$table['id'], $eg['id']]);
        });
    }

    public function unassign(array $eg): void
    {
        Db::exec('DELETE FROM seat_assignments WHERE event_guest_id = ?', [$eg['id']]);
    }

    /**
     * Seat everyone who has no seat yet. Households (guest groups) are kept together where a table has room;
     * a household too big for any single table is split guest by guest.
     * @return array{placed:int,left:int}
     */
    public function autoAssign(array $event): array
    {
        $tables = $this->tables((int) $event['id']);
        $free = array_column($tables, 'free', 'id');
        $units = [];
        foreach ($this->unassigned((int) $event['id']) as $g) {
            $key = $g['group_id'] ? 'g' . $g['group_id'] : 's' . $g['id'];
            $units[$key]['members'][] = $g;
            $units[$key]['size'] = ($units[$key]['size'] ?? 0) + (int) $g['party'];
        }
        uasort($units, static fn ($a, $b) => $b['size'] <=> $a['size']);
        $placed = $left = 0;
        $put = function (array $members, int $tableId) use (&$free, &$placed) {
            foreach ($members as $m) {
                Db::run('INSERT IGNORE INTO seat_assignments (table_id, event_guest_id) VALUES (?, ?)', [$tableId, $m['id']]);
                $free[$tableId] -= (int) $m['party'];
                $placed++;
            }
        };
        foreach ($units as $u) {
            $fit = null;
            foreach ($free as $tid => $f) { // best fit: the table that wastes the fewest seats
                if ($f >= $u['size'] && ($fit === null || $f < $free[$fit])) {
                    $fit = $tid;
                }
            }
            if ($fit !== null) {
                $put($u['members'], $fit);
                continue;
            }
            foreach ($u['members'] as $m) { // split the household
                $t = null;
                foreach ($free as $tid => $f) {
                    if ($f >= (int) $m['party'] && ($t === null || $f < $free[$t])) {
                        $t = $tid;
                    }
                }
                if ($t === null) {
                    $left++;
                } else {
                    $put([$m], $t);
                }
            }
        }
        return ['placed' => $placed, 'left' => $left];
    }

    public function clearAll(int $eventId): void
    {
        Db::exec('DELETE sa FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id WHERE t.event_id = ?', [$eventId]);
    }

    /** "Your table" lookup for a guest's own page. */
    public function tableFor(int $eventGuestId): ?array
    {
        return Db::one('SELECT t.name, t.notes FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id WHERE sa.event_guest_id = ?', [$eventGuestId]);
    }

    /** Meal summary and dietary notes per table, for caterers and the printable chart. */
    public function mealsByTable(int $eventId): array
    {
        $rows = Db::all(
            "SELECT t.id AS table_id, q.label, a.answer, q.type, g.first_name
             FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id JOIN event_guests eg ON eg.id = sa.event_guest_id JOIN guests g ON g.id = eg.guest_id
             JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0 AND r.status = 'attending'
             JOIN rsvp_answers a ON a.response_id = r.id JOIN rsvp_questions q ON q.id = a.question_id AND q.type IN ('meal','dietary')
             WHERE t.event_id = ?",
            [$eventId]
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'meal') {
                $out[$r['table_id']]['meals'][$r['answer']] = ($out[$r['table_id']]['meals'][$r['answer']] ?? 0) + 1;
            } elseif ($r['answer'] !== '') {
                $out[$r['table_id']]['diet'][] = $r['first_name'] . ': ' . $r['answer'];
            }
        }
        return $out;
    }
}