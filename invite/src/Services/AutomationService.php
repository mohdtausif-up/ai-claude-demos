<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use Throwable;

/**
 * Per-event automation rules (PRD 7.10). The scheduler calls run() every minute; each rule fires at most once per
 * (event, rule, reference) thanks to automation_runs. Guest emails honour quiet hours (21:00-08:00 event time),
 * unsubscribes and the host's verified-email requirement.
 */
final class AutomationService
{
    public const RULES = [
        'reminder_7d' => ['Remind pending guests 7 days before the RSVP deadline', 'Needs an RSVP deadline.'],
        'reminder_24h' => ['Final reminder 24 hours before the RSVP deadline', 'Needs an RSVP deadline.'],
        'venue_change' => ['Tell guests when the venue or time changes', 'Emails guests who are attending or maybe, as soon as you save the change.'],
        'event_day' => ['Send directions on the morning of the event', 'Goes to attending guests at 08:00 event time with the map link.'],
        'thank_you' => ['Thank attendees after the event', 'Sent the morning after the event to guests who attended.'],
        'capacity_alert' => ['Tell me when capacity reaches 80% and 100%', 'Emails you once at each level.'],
    ];

    public function __construct(private MessageService $messages, private Notifier $notifier, private StatsService $stats, private AuditLogger $audit)
    {
    }

    /** @return array<string,array{enabled:bool}> */
    public function forEvent(int $eventId): array
    {
        $rows = Db::all('SELECT rule_key, enabled FROM automations WHERE event_id = ?', [$eventId]);
        $out = [];
        foreach (self::RULES as $k => $_) {
            $out[$k] = ['enabled' => false];
        }
        foreach ($rows as $r) {
            if (isset($out[$r['rule_key']])) {
                $out[$r['rule_key']]['enabled'] = (bool) $r['enabled'];
            }
        }
        return $out;
    }

    public function isEnabled(int $eventId, string $rule): bool
    {
        return (bool) Db::value('SELECT enabled FROM automations WHERE event_id = ? AND rule_key = ?', [$eventId, $rule]);
    }

    /** @param list<string> $enabledKeys */
    public function save(array $user, array $event, array $enabledKeys): void
    {
        foreach (array_keys(self::RULES) as $k) {
            Db::run(
                'INSERT INTO automations (event_id, rule_key, enabled, created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
                [$event['id'], $k, in_array($k, $enabledKeys, true) ? 1 : 0, utcnow()]
            );
        }
        $this->audit->event($user, $event, 'automations.updated', ['enabled' => array_values(array_intersect(array_keys(self::RULES), $enabledKeys))]);
    }

    /** Next 08:00 event-local time if "now" falls in quiet hours (21:00-08:00); otherwise null (send now). */
    public static function quietUntil(int $nowTs, string $tz): ?string
    {
        $local = (new \DateTimeImmutable('@' . $nowTs))->setTimezone(new \DateTimeZone($tz));
        $h = (int) $local->format('G');
        if ($h >= 8 && $h < 21) {
            return null;
        }
        $wake = $local->setTime(8, 0);
        if ($h >= 21) {
            $wake = $wake->modify('+1 day');
        }
        return $wake->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Scheduler entry point. @return int automations fired */
    public function run(?int $now = null): int
    {
        $now ??= time();
        $fired = 0;
        $rows = Db::all(
            "SELECT e.*, s.rsvp_deadline, s.capacity, a.rule_key FROM automations a JOIN events e ON e.id = a.event_id JOIN event_settings s ON s.event_id = e.id
             WHERE a.enabled = 1 AND e.deleted_at IS NULL AND e.status IN ('published','closed') AND e.moderation_status = 'ok'"
        );
        foreach ($rows as $e) {
            try {
                $fired += $this->evaluate($e, $e['rule_key'], $now) ? 1 : 0;
            } catch (Throwable $ex) {
                error_log('[automation] ' . $e['uuid'] . ' ' . $e['rule_key'] . ': ' . $ex->getMessage());
            }
        }
        return $fired;
    }

    private function claim(array $e, string $rule, string $ref, ?string $detail = null): bool
    {
        return Db::exec('INSERT IGNORE INTO automation_runs (event_id, rule_key, ref, detail, ran_at) VALUES (?,?,?,?,?)', [$e['id'], $rule, $ref, $detail, utcnow()]) === 1;
    }

    private function evaluate(array $e, string $rule, int $now): bool
    {
        $start = strtotime($e['start_at'] . ' UTC');
        $end = $e['end_at'] ? strtotime($e['end_at'] . ' UTC') : $start + 10800;
        $tz = new \DateTimeZone($e['timezone']);
        switch ($rule) {
            case 'reminder_7d':
            case 'reminder_24h':
                if (empty($e['rsvp_deadline'])) {
                    return false;
                }
                $deadline = strtotime($e['rsvp_deadline'] . ' UTC');
                $due = $deadline - ($rule === 'reminder_7d' ? 7 * 86400 : 86400);
                if ($now < $due || $now >= $deadline) {
                    return false;
                }
                if (!$this->claim($e, $rule, 'd' . $deadline)) {
                    return false;
                }
                $last = $rule === 'reminder_24h';
                return $this->sendToGuests($e, 'reminder', 'pending', $last ? 'Last call: please RSVP to {event_title}' : 'Reminder: please RSVP to {event_title}', "Hi {first_name},\n\nWe still need your answer for {event_title} on {event_date}. The RSVP deadline is " . fmt_dt($e['rsvp_deadline'], $e['timezone']) . ".\n\nIt takes under a minute: {link}\n\nThank you,\n{host}", $now);
            case 'event_day':
                $local = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
                $due = $local->setTime(8, 0)->getTimestamp();
                if ($now < $due || $now >= $start) {
                    return false;
                }
                if (!$this->claim($e, $rule, $local->format('Ymd'))) {
                    return false;
                }
                return $this->sendToGuests($e, 'update', 'attending', 'Today: {event_title}', "Hi {first_name},\n\nToday is the day! {event_title} starts at {event_date}.\n\nWhere: {event_place}\nDirections and your pass: {link}\n\nSee you soon,\n{host}", $now, true);
            case 'thank_you':
                $local = (new \DateTimeImmutable('@' . $end))->setTimezone($tz);
                $due = $local->modify('+1 day')->setTime(9, 0)->getTimestamp();
                if ($now < $due || $now > $due + 7 * 86400 || !$this->claim($e, $rule, 'once')) {
                    return false;
                }
                return $this->sendToGuests($e, 'thank_you', 'attending', 'Thank you for celebrating with us', "Hi {first_name},\n\nThank you for being part of {event_title}. It meant a great deal to us.\n\nWith love,\n{host}", $now, true);
            case 'capacity_alert':
                if ($e['capacity'] === null) {
                    return false;
                }
                $taken = $this->stats->summary($e, ['capacity' => $e['capacity']])['expected_headcount'];
                $pct = $taken / max(1, (int) $e['capacity']) * 100;
                foreach ([100, 80] as $level) {
                    if ($pct >= $level) {
                        if ($this->claim($e, $rule, (string) $level, "$taken of {$e['capacity']}")) {
                            $this->alertHost($e, $level, $taken);
                            return true;
                        }
                        return false;
                    }
                }
                return false;
        }
        return false;
    }

    private function alertHost(array $e, int $level, int $taken): void
    {
        $host = Db::one('SELECT * FROM users WHERE id = ?', [$e['created_by']]);
        if (!$host) {
            return;
        }
        $this->notifier->email($host['email'], $host['name'], $level >= 100 ? "{$e['title']} is full" : "{$e['title']} is $level% full", 'capacity_alert', [
            'event' => $e, 'level' => $level, 'taken' => $taken, 'capacity' => (int) $e['capacity'], 'link' => app_url('app/events/' . $e['uuid'] . '/guests'),
        ], ['event_id' => $e['id'], 'transactional' => true]);
    }

    /** Send through MessageService as the event creator; skipped silently if they have not verified their email. */
    private function sendToGuests(array $e, string $kind, string $segment, string $subject, string $body, int $now, bool $quiet = true): bool
    {
        $creator = Db::one('SELECT * FROM users WHERE id = ?', [$e['created_by']]);
        if (!$creator || !$creator['email_verified_at'] || $creator['status'] !== 'active') {
            return false;
        }
        $until = $quiet ? self::quietUntil($now, $e['timezone']) : null;
        try {
            $this->messages->send($creator, $e, ['kind' => $kind, 'segment' => $segment, 'subject' => $subject, 'body' => $body, 'scheduled_utc' => $until]);
        } catch (\App\Core\ValidationException) {
            return true; // nobody to email: the rule still counts as done
        }
        return true;
    }

    /** Venue/time change: called by EventService::update with the before/after rows. */
    public function onEventChanged(array $user, array $before, array $after): void
    {
        if ($after['status'] !== 'published' || !$this->isEnabled((int) $after['id'], 'venue_change')) {
            return;
        }
        $changed = [];
        foreach (['venue_name' => 'Venue', 'venue_address' => 'Address', 'start_at' => 'Date and time'] as $f => $label) {
            if ((string) $before[$f] !== (string) $after[$f]) {
                $changed[] = $label;
            }
        }
        if (!$changed || !$user['email_verified_at']) {
            return;
        }
        $ref = 'chg' . md5($after['venue_name'] . $after['venue_address'] . $after['start_at']);
        if (!$this->claim($after, 'venue_change', $ref, implode(', ', $changed))) {
            return;
        }
        try {
            $this->messages->send($user, $after, [
                'kind' => 'update', 'segment' => 'active', 'subject' => 'Update: details changed for {event_title}',
                'body' => "Hi {first_name},\n\nThe " . strtolower(implode(' and ', $changed)) . " for {event_title} has changed. New details:\n\nWhen: {event_date}\nWhere: {event_place}\n\nPlease check your invitation: {link}\n\n{host}",
                'scheduled_utc' => self::quietUntil(time(), $after['timezone']),
            ]);
        } catch (\App\Core\ValidationException) {
        }
    }
}
