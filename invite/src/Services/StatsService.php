<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** All dashboard numbers. Headcount model: guest (1) + plus-ones + children; only 'attending' counts as expected. */
final class StatsService
{
    /** @return array<string,mixed> */
    public function summary(array $event, array $settings, ?int $subEventId = null): array
    {
        $eid = (int) $event['id'];
        $scope = $subEventId ?? 0;
        $inv = Db::one(
            "SELECT COUNT(*) AS invited,
                    COALESCE(SUM(invite_sent_at IS NOT NULL), 0) AS sent,
                    COALESCE(SUM(first_opened_at IS NOT NULL), 0) AS opened,
                    COALESCE(SUM(delivery_status = 'failed'), 0) AS failed,
                    COALESCE(SUM(delivery_status = 'bounced'), 0) AS bounced,
                    COALESCE(SUM(delivery_status IN ('sent','delivered')), 0) AS delivered
             FROM event_guests WHERE event_id = ?" . ($subEventId ? ' AND id IN (SELECT event_guest_id FROM sub_event_guests WHERE sub_event_id = ?)' : ''),
            $subEventId ? [$eid, $subEventId] : [$eid]
        );
        $byStatus = [];
        foreach (Db::all(
            'SELECT status, COUNT(*) AS n, COALESCE(SUM(plus_ones), 0) AS plus_ones, COALESCE(SUM(children), 0) AS children
             FROM rsvp_responses WHERE event_id = ? AND sub_event_scope = ? GROUP BY status',
            [$eid, $scope]
        ) as $r) {
            $byStatus[$r['status']] = $r;
        }
        $n = static fn (string $s, string $f = 'n') => (int) ($byStatus[$s][$f] ?? 0);
        $responded = array_sum(array_map(static fn ($r) => (int) $r['n'], $byStatus));
        $adults = $n('attending');
        $plus = $n('attending', 'plus_ones');
        $kids = $n('attending', 'children');
        $expected = $adults + $plus + $kids;

        $checked = Db::one(
            'SELECT COUNT(DISTINCT event_guest_id) AS guests, COALESCE(SUM(headcount), 0) AS heads FROM checkins WHERE event_id = ? AND reversed_at IS NULL' . ($subEventId ? ' AND sub_event_id = ?' : ' AND sub_event_id IS NULL'),
            $subEventId ? [$eid, $subEventId] : [$eid]
        );
        $started = $event['start_at'] <= utcnow();
        $capacity = $subEventId
            ? (Db::value('SELECT capacity FROM sub_events WHERE id = ?', [$subEventId]))
            : $settings['capacity'];

        return [
            'invited' => (int) $inv['invited'],
            'sent' => (int) $inv['sent'],
            'delivered' => (int) $inv['delivered'],
            'opened' => (int) $inv['opened'],
            'failed' => (int) $inv['failed'] + (int) $inv['bounced'],
            'responded' => $responded,
            'pending' => max(0, (int) $inv['invited'] - $responded),
            'attending' => $adults,
            'declined' => $n('declined'),
            'maybe' => $n('maybe'),
            'waitlist' => $n('waitlist'),
            'adults' => $adults,
            'plus_ones' => $plus,
            'children' => $kids,
            'expected_headcount' => $expected,
            'maybe_headcount' => $n('maybe') + $n('maybe', 'plus_ones') + $n('maybe', 'children'),
            'waitlist_headcount' => $n('waitlist') + $n('waitlist', 'plus_ones') + $n('waitlist', 'children'),
            'checked_in_guests' => (int) $checked['guests'],
            'checked_in_headcount' => (int) $checked['heads'],
            'no_show' => $started ? max(0, $adults - (int) $checked['guests']) : null,
            'capacity' => $capacity !== null ? (int) $capacity : null,
            'utilization' => $capacity ? min(100, (int) round($expected / (int) $capacity * 100)) : null,
        ];
    }

    /** Responses per day with a running total. @return list<array{date:string,new:int,total:int}> */
    public function trend(int $eventId, int $days = 30): array
    {
        $rows = Db::all(
            'SELECT DATE(responded_at) AS d, COUNT(*) AS n FROM rsvp_responses WHERE event_id = ? AND sub_event_scope = 0 AND responded_at >= ? GROUP BY DATE(responded_at) ORDER BY d',
            [$eventId, utcnow(-$days * 86400)]
        );
        $before = (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ? AND sub_event_scope = 0 AND responded_at < ?', [$eventId, utcnow(-$days * 86400)]);
        $out = [];
        $total = $before;
        foreach ($rows as $r) {
            $total += (int) $r['n'];
            $out[] = ['date' => $r['d'], 'new' => (int) $r['n'], 'total' => $total];
        }
        return $out;
    }

    /** Distribution of answers to meal-type questions among attending guests. @return list<array{label:string,answers:array<string,int>}> */
    public function mealDistribution(int $eventId): array
    {
        return $this->distribution($eventId, ['meal']);
    }

    public function choiceDistribution(int $eventId): array
    {
        return $this->distribution($eventId, ['single_choice', 'multiple_choice', 'dropdown', 'transport', 'accommodation', 'rating']);
    }

    private function distribution(int $eventId, array $types): array
    {
        $rows = Db::all(
            "SELECT q.id AS qid, q.label, a.answer
             FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id
             JOIN rsvp_responses r ON r.id = a.response_id
             WHERE q.event_id = ? AND q.type IN (" . Db::placeholders($types) . ") AND r.status = 'attending'",
            [$eventId, ...$types]
        );
        $out = [];
        foreach ($rows as $r) {
            $vals = $r['answer'] !== '' && $r['answer'][0] === '[' ? (json_decode($r['answer'], true) ?: []) : [$r['answer']];
            foreach ($vals as $v) {
                $out[$r['qid']]['label'] = $r['label'];
                $out[$r['qid']]['answers'][$v] = ($out[$r['qid']]['answers'][$v] ?? 0) + 1;
            }
        }
        foreach ($out as &$q) {
            arsort($q['answers']);
        }
        return array_values($out);
    }

    /** Free-text dietary notes among attending guests (first names only). */
    public function dietary(int $eventId): array
    {
        return Db::all(
            "SELECT g.first_name, a.answer FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id
             JOIN rsvp_responses r ON r.id = a.response_id JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id
             WHERE q.event_id = ? AND q.type = 'dietary' AND r.status = 'attending' AND a.answer <> '' ORDER BY g.first_name LIMIT 100",
            [$eventId]
        );
    }

    /** Where responses come from. @return list<array{source:string,invited:int,opened:int,responded:int}> */
    public function sources(int $eventId): array
    {
        return Db::all(
            "SELECT COALESCE(eg.last_channel, eg.source) AS source, COUNT(*) AS invited,
                    SUM(eg.first_opened_at IS NOT NULL) AS opened, SUM(r.id IS NOT NULL) AS responded
             FROM event_guests eg LEFT JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0
             WHERE eg.event_id = ? GROUP BY COALESCE(eg.last_channel, eg.source) ORDER BY invited DESC",
            [$eventId]
        );
    }

    /** For each reminder: how many recipients responded afterwards. */
    public function reminderEffectiveness(int $eventId): array
    {
        return Db::all(
            "SELECT m.uuid, m.subject, m.created_at, COUNT(mr.id) AS recipients,
                    SUM(r.id IS NOT NULL AND r.responded_at > m.created_at) AS responded_after
             FROM messages m JOIN message_recipients mr ON mr.message_id = m.id
             LEFT JOIN rsvp_responses r ON r.event_guest_id = mr.event_guest_id AND r.sub_event_scope = 0
             WHERE m.event_id = ? AND m.kind = 'reminder' GROUP BY m.id ORDER BY m.id DESC LIMIT 10",
            [$eventId]
        );
    }

    public function recent(int $eventId, int $limit = 10): array
    {
        return Db::all(
            'SELECT eg.uuid, g.first_name, g.last_name, r.status, r.plus_ones, r.children, r.responded_at, r.updated_at
             FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id
             WHERE r.event_id = ? AND r.sub_event_scope = 0 ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . (int) $limit,
            [$eventId]
        );
    }

    /** Check-ins per 15-minute bucket. @return list<array{t:string,n:int}> */
    public function checkinTimeline(int $eventId): array
    {
        return Db::all(
            "SELECT DATE_FORMAT(checked_in_at, '%Y-%m-%d %H:%i:00') - INTERVAL (MINUTE(checked_in_at) % 15) MINUTE AS t, COUNT(*) AS n
             FROM checkins WHERE event_id = ? AND reversed_at IS NULL GROUP BY t ORDER BY t",
            [$eventId]
        );
    }

    /** Everything the live dashboard polls for, plus a content hash so unchanged polls can be answered with 304. */
    public function live(array $event, array $settings): array
    {
        $data = [
            'summary' => $this->summary($event, $settings),
            'recent' => $this->recent((int) $event['id']),
            'at' => utcnow(),
        ];
        $hashSource = $data;
        unset($hashSource['at']);
        $data['etag'] = md5(json_encode($hashSource));
        return $data;
    }
}
