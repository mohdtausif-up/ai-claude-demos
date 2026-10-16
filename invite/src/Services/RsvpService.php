<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/**
 * Records RSVPs. One current row per guest (and sub-event) in rsvp_responses; every change is appended to
 * rsvp_response_history. Safe under concurrent double-submits: the guest's row is locked for the duration.
 */
final class RsvpService
{
    public function __construct(
        private QuestionService $questions,
        private Notifier $notifier,
        private LinkService $links,
        private AuditLogger $audit,
        private SubEventService $subEvents,
        private WebhookService $webhooks
    ) {
    }

    /** Why a guest cannot respond right now, or null if they can. */
    public function blockedReason(array $event, array $settings, bool $hasResponse): ?string
    {
        if (!in_array($event['status'], ['published', 'closed'], true) || $event['moderation_status'] !== 'ok' || $event['deleted_at'] !== null) {
            return t('rsvp.unavailable');
        }
        if ($event['status'] === 'closed' || !(int) $settings['rsvp_open']) {
            return t('rsvp.closed');
        }
        if (!empty($settings['rsvp_deadline']) && $settings['rsvp_deadline'] < utcnow()) {
            return t('rsvp.deadline_passed');
        }
        if ($hasResponse && !(int) $settings['allow_response_edit']) {
            return t('rsvp.edit_disabled');
        }
        return null;
    }

    public static function headcount(string $status, int $plusOnes, int $children): int
    {
        return in_array($status, ['attending', 'waitlist', 'maybe'], true) ? 1 + $plusOnes + $children : 0;
    }

    /** Seats taken by confirmed guests, optionally excluding one guest (when re-checking their own change). */
    public function attendingHeadcount(int $eventId, ?int $subEventId = null, ?int $excludeEventGuestId = null, bool $lock = false): int
    {
        $sql = "SELECT COALESCE(SUM(1 + plus_ones + children), 0) FROM rsvp_responses WHERE event_id = ? AND status = 'attending' AND sub_event_scope = ?";
        $params = [$eventId, $subEventId ?? 0];
        if ($excludeEventGuestId !== null) {
            $sql .= ' AND event_guest_id <> ?';
            $params[] = $excludeEventGuestId;
        }
        // Inside a capacity decision the count must be a locking (current) read, not a snapshot.
        return (int) Db::value($sql . ($lock ? ' FOR UPDATE' : ''), $params);
    }

    /**
     * @param array{status:string,plus_ones?:int,children?:int,companions?:array,answers?:?array,comment?:?string} $input
     *        answers/companions === null (absent) means "leave as is" (used by host status edits).
     * @return array{response:array,changed:bool,created:bool,status:string,waitlisted:bool}
     * @throws ValidationException
     */
    public function submit(array $event, array $settings, array $eg, array $input, string $actor = 'guest', ?int $actorUserId = null, bool $hostOverride = false): array
    {
        $status = $input['status'];
        $allowed = ['attending', 'declined', 'maybe'];
        if (!in_array($status, $allowed, true) || ($status === 'maybe' && !(int) $settings['allow_maybe'] && !$hostOverride)) {
            throw new ValidationException(['status' => ['Please choose how you would like to respond.']]);
        }
        $plus = max(0, (int) ($input['plus_ones'] ?? 0));
        $kids = max(0, (int) ($input['children'] ?? 0));
        if ($status === 'declined') {
            $plus = $kids = 0;
        }
        if (!$hostOverride) {
            $plusLimit = (int) ($eg['plus_one_limit'] ?? $settings['plus_one_limit']);
            $kidLimit = (int) ($eg['child_limit'] ?? $settings['max_children']);
            $errs = [];
            if ($plus > $plusLimit) {
                $errs['plus_ones'][] = $plusLimit === 0 ? 'This invitation does not include a plus-one.' : "You can bring up to $plusLimit additional adult" . ($plusLimit === 1 ? '' : 's') . '.';
            }
            if ($kids > 0 && !(int) $settings['allow_children']) {
                $errs['children'][] = 'Children are not included in this invitation.';
            } elseif ($kids > $kidLimit) {
                $errs['children'][] = "You can bring up to $kidLimit children.";
            }
            if ($errs) {
                throw new ValidationException($errs);
            }
        }

        $companions = $this->normaliseCompanions($input['companions'] ?? null, $plus, $kids);
        $answers = null;
        if (array_key_exists('answers', $input) && $input['answers'] !== null) {
            $qs = $this->questions->forEvent((int) $event['id'], null, false);
            $groupUuid = $eg['group_id'] ? (string) Db::value('SELECT uuid FROM guest_groups WHERE id = ?', [$eg['group_id']]) : '';
            $answers = $this->questions->validateAnswers($qs, $input['answers'], ['status' => $status, 'children' => $kids, 'plus_ones' => $plus, 'group_uuid' => $groupUuid]);
        }
        $comment = isset($input['comment']) && $input['comment'] !== '' ? mb_substr(trim((string) $input['comment']), 0, 500) : null;

        $result = Db::transaction(function () use ($event, $settings, $eg, $status, $plus, $kids, $companions, $answers, $comment, $actor, $actorUserId, $hostOverride, $input) {
            Db::value('SELECT id FROM event_guests WHERE id = ? FOR UPDATE', [$eg['id']]);
            $capacity = $settings['capacity'] !== null ? (int) $settings['capacity'] : null;
            if ($capacity !== null) {
                Db::value('SELECT id FROM events WHERE id = ? FOR UPDATE', [$event['id']]);
            }
            $old = Db::one('SELECT * FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_scope = 0 FOR UPDATE', [$eg['id']]);

            $final = $status;
            $waitlisted = false;
            if ($status === 'attending' && $capacity !== null && !$hostOverride) {
                $taken = $this->attendingHeadcount((int) $event['id'], null, (int) $eg['id'], true);
                if ($taken + 1 + $plus + $kids > $capacity) {
                    if ((int) $settings['allow_waitlist']) {
                        $final = 'waitlist';
                        $waitlisted = true;
                    } else {
                        throw new ValidationException(['status' => ['Sorry, this event is full.']]);
                    }
                }
            }
            // Keep a waitlisted guest waitlisted if they resubmit unchanged and there is still no room.
            $now = utcnow();
            $created = $old === null;
            $oldAnswers = $old ? $this->answerMap((int) $old['id']) : [];
            $oldComp = $old ? $this->companionNames((int) $old['id']) : [];

            if ($created) {
                $rid = Db::insert('rsvp_responses', [
                    'uuid' => uuid4(), 'event_id' => $event['id'], 'event_guest_id' => $eg['id'], 'sub_event_id' => null,
                    'status' => $final, 'plus_ones' => $plus, 'children' => $kids, 'comment' => $comment,
                    'show_name' => !empty($input['show_name']) ? 1 : 0,
                    'responded_at' => $now, 'updated_at' => $now,
                ]);
            } else {
                $rid = (int) $old['id'];
                $comment = $comment ?? (array_key_exists('comment', $input) ? null : $old['comment']);
            }

            $newAnswers = $answers === null ? $oldAnswers : $this->answerMapFromSubmission($answers);
            $newComp = $companions === null ? $oldComp : array_map(static fn ($c) => $c['kind'] . ':' . ($c['name'] ?? '') . ':' . ($c['age'] ?? ''), $companions);

            $diff = [];
            if (!$created) {
                foreach (['status' => $final, 'plus_ones' => $plus, 'children' => $kids, 'comment' => $comment] as $f => $new) {
                    if ((string) $old[$f] !== (string) ($new ?? '')) {
                        $diff[$f] = [$old[$f], $new];
                    }
                }
                foreach ($newAnswers + $oldAnswers as $label => $_) {
                    if (($oldAnswers[$label] ?? '') !== ($newAnswers[$label] ?? '')) {
                        $diff['answer: ' . $label] = [$oldAnswers[$label] ?? null, $newAnswers[$label] ?? null];
                    }
                }
                if ($oldComp !== $newComp) {
                    $diff['companions'] = [$oldComp, $newComp];
                }
            }

            if (!$created && ($diff || (array_key_exists('show_name', $input) && (int) !empty($input['show_name']) !== (int) $old['show_name']))) {
                $upd = ['status' => $final, 'plus_ones' => $plus, 'children' => $kids, 'comment' => $comment, 'updated_at' => $now];
                if (array_key_exists('show_name', $input)) {
                    $upd['show_name'] = !empty($input['show_name']) ? 1 : 0;
                }
                Db::update('rsvp_responses', $upd, ['id' => $rid]);
            }
            if (($created || isset($diff['companions'])) && $companions !== null) {
                Db::exec('DELETE FROM rsvp_companions WHERE response_id = ?', [$rid]);
                foreach ($companions as $c) {
                    Db::insert('rsvp_companions', ['response_id' => $rid, 'kind' => $c['kind'], 'name' => $c['name'], 'age' => $c['age']]);
                }
            }
            if ($answers !== null && ($created || array_filter(array_keys($diff), static fn ($k) => str_starts_with($k, 'answer: ')))) {
                Db::exec('DELETE FROM rsvp_answers WHERE response_id = ?', [$rid]);
                foreach ($answers as $uuid => $value) {
                    $qid = (int) Db::value('SELECT id FROM rsvp_questions WHERE uuid = ? AND event_id = ?', [$uuid, $event['id']]);
                    if ($qid) {
                        Db::insert('rsvp_answers', ['response_id' => $rid, 'question_id' => $qid, 'answer' => is_array($value) ? json_out($value) : $value]);
                    }
                }
            }

            $changed = $created || $diff;
            if ($changed) {
                $mealLabel = Db::value("SELECT label FROM rsvp_questions WHERE event_id = ? AND type = 'meal' ORDER BY position LIMIT 1", [$event['id']]);
                Db::insert('rsvp_response_history', [
                    'response_id' => $rid,
                    'event_guest_id' => $eg['id'],
                    'old_status' => $old['status'] ?? null,
                    'new_status' => $final,
                    'old_plus_ones' => $old['plus_ones'] ?? null,
                    'new_plus_ones' => $plus,
                    'old_children' => $old['children'] ?? null,
                    'new_children' => $kids,
                    'old_meal' => $mealLabel ? ($oldAnswers[$mealLabel] ?? null) : null,
                    'new_meal' => $mealLabel ? ($newAnswers[$mealLabel] ?? null) : null,
                    'changes_json' => $diff ? json_out($diff) : null,
                    'actor_type' => $actor,
                    'actor_user_id' => $actorUserId,
                    'created_at' => $now,
                ]);
            }
            $response = Db::one('SELECT * FROM rsvp_responses WHERE id = ?', [$rid]);
            return ['response' => $response, 'changed' => (bool) $changed, 'created' => $created, 'status' => $final, 'waitlisted' => $waitlisted || $final === 'waitlist', 'old_status' => $old['status'] ?? null];
        });

        if ($result['changed']) {
            $r = $result['response'];
            $this->webhooks->dispatch((int) $event['workspace_id'], $result['created'] ? 'rsvp.created' : 'rsvp.updated', WebhookService::guestPayload($event, $eg, [
                'status' => $result['status'], 'plus_ones' => (int) $r['plus_ones'], 'children' => (int) $r['children'], 'headcount' => self::headcount($result['status'], (int) $r['plus_ones'], (int) $r['children']), 'actor' => $actor,
            ]));
            if ($result['status'] === 'attending' && $settings['capacity'] !== null) {
                $total = $this->attendingHeadcount((int) $event['id']);
                $mine = 1 + (int) $r['plus_ones'] + (int) $r['children'];
                if ($total >= (int) $settings['capacity'] && $total - $mine < (int) $settings['capacity']) {
                    $this->webhooks->dispatch((int) $event['workspace_id'], 'event.capacity_reached', ['event_id' => $event['uuid'], 'capacity' => (int) $settings['capacity'], 'headcount' => $total]);
                }
            }
        }
        // A freed-up seat may let someone off the waitlist.
        if ($result['changed'] && $settings['capacity'] !== null && ($result['old_status'] ?? null) === 'attending') {
            $this->promoteWaitlist($event, $settings);
        }
        unset($result['old_status']);
        return $result;
    }

    /**
     * @return list<array{kind:string,name:?string,age:?int}>|null null when the caller supplied no companion data
     */
    private function normaliseCompanions(mixed $raw, int $plus, int $kids): ?array
    {
        if ($raw === null) {
            return null;
        }
        $out = [];
        foreach (['plus_one' => $plus, 'child' => $kids] as $kind => $count) {
            $list = is_array($raw[$kind] ?? null) ? array_values($raw[$kind]) : [];
            for ($i = 0; $i < $count; $i++) {
                $row = is_array($list[$i] ?? null) ? $list[$i] : [];
                $name = isset($row['name']) && is_scalar($row['name']) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $row['name']) ?? ''), 0, 150) : '';
                $age = $kind === 'child' && isset($row['age']) && is_numeric($row['age']) ? max(0, min(17, (int) $row['age'])) : null;
                $out[] = ['kind' => $kind, 'name' => $name !== '' ? $name : null, 'age' => $age];
            }
        }
        return $out;
    }

    /** label => text, for diffing and history. */
    private function answerMap(int $responseId): array
    {
        $rows = Db::all('SELECT q.label, a.answer FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id WHERE a.response_id = ?', [$responseId]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['label']] = $this->flatten($r['answer']);
        }
        return $out;
    }

    private function answerMapFromSubmission(array $answers): array
    {
        $out = [];
        foreach ($answers as $uuid => $v) {
            $label = Db::value('SELECT label FROM rsvp_questions WHERE uuid = ?', [$uuid]);
            if ($label) {
                $out[$label] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
        }
        return $out;
    }

    private function flatten(string $stored): string
    {
        if ($stored !== '' && $stored[0] === '[') {
            $d = json_decode($stored, true);
            return is_array($d) ? implode(', ', $d) : $stored;
        }
        return $stored;
    }

    private function companionNames(int $responseId): array
    {
        return array_map(
            static fn ($c) => $c['kind'] . ':' . ($c['name'] ?? '') . ':' . ($c['age'] ?? ''),
            Db::all('SELECT kind, name, age FROM rsvp_companions WHERE response_id = ? ORDER BY id', [$responseId])
        );
    }

    /**
     * Move waitlisted guests to attending, oldest first, while seats remain.
     * @param int|null $subEventId null = the main event
     * @return int promoted
     */
    public function promoteWaitlist(array $event, array $settings, ?int $subEventId = null): int
    {
        $capacity = $subEventId === null ? $settings['capacity'] : Db::value('SELECT capacity FROM sub_events WHERE id = ?', [$subEventId]);
        if ($capacity === null) {
            return 0;
        }
        $promoted = [];
        Db::transaction(function () use ($event, $subEventId, $capacity, &$promoted) {
            Db::value('SELECT id FROM events WHERE id = ? FOR UPDATE', [$event['id']]);
            $free = (int) $capacity - $this->attendingHeadcount((int) $event['id'], $subEventId, null, true);
            $waiting = Db::all("SELECT * FROM rsvp_responses WHERE event_id = ? AND status = 'waitlist' AND sub_event_scope = ? ORDER BY responded_at, id FOR UPDATE", [$event['id'], $subEventId ?? 0]);
            foreach ($waiting as $w) {
                $size = 1 + (int) $w['plus_ones'] + (int) $w['children'];
                if ($size <= $free) {
                    $free -= $size;
                    Db::update('rsvp_responses', ['status' => 'attending', 'updated_at' => utcnow()], ['id' => $w['id']]);
                    Db::insert('rsvp_response_history', [
                        'response_id' => $w['id'], 'event_guest_id' => $w['event_guest_id'],
                        'old_status' => 'waitlist', 'new_status' => 'attending',
                        'old_plus_ones' => $w['plus_ones'], 'new_plus_ones' => $w['plus_ones'],
                        'old_children' => $w['children'], 'new_children' => $w['children'],
                        'changes_json' => json_out(['status' => ['waitlist', 'attending']]),
                        'actor_type' => 'system', 'created_at' => utcnow(),
                    ]);
                    $promoted[] = (int) $w['event_guest_id'];
                }
            }
        });
        foreach ($promoted as $egId) {
            $this->sendPromotionEmail($event, $egId);
        }
        return count($promoted);
    }

    /**
     * Record a guest's answers for each function (sub-event) they are eligible for.
     * Unticked functions count as "not attending". Party size follows the main response.
     * @param list<string> $selected sub-event uuids the guest ticked
     */
    public function submitSubEvents(array $event, array $settings, array $eg, string $mainStatus, int $plus, int $kids, array $selected, string $actor = 'guest', ?int $actorUserId = null): void
    {
        $selected = array_values(array_filter($selected, 'is_string'));
        foreach ($this->subEvents->eligibleFor((int) $event['id'], (int) $eg['id']) as $s) {
            if (!(int) $s['rsvp_open']) {
                continue;
            }
            $want = $mainStatus === 'declined' || !in_array($s['uuid'], $selected, true) ? 'declined' : $mainStatus;
            $party = $want === 'declined' ? [0, 0] : [$plus, $kids];
            $freed = false;
            Db::transaction(function () use ($event, $settings, $eg, $s, $want, $party, $actor, $actorUserId, &$freed) {
                Db::value('SELECT id FROM event_guests WHERE id = ? FOR UPDATE', [$eg['id']]);
                $final = $want;
                if ($want === 'attending' && $s['capacity'] !== null) {
                    Db::value('SELECT id FROM events WHERE id = ? FOR UPDATE', [$event['id']]);
                    $taken = $this->attendingHeadcount((int) $event['id'], (int) $s['id'], (int) $eg['id'], true);
                    if ($taken + 1 + $party[0] + $party[1] > (int) $s['capacity']) {
                        if (!(int) $settings['allow_waitlist']) {
                            throw new ValidationException(['functions' => [$s['name'] . ' is full.']]);
                        }
                        $final = 'waitlist';
                    }
                }
                $old = Db::one('SELECT * FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_scope = ?', [$eg['id'], $s['id']]);
                $now = utcnow();
                if ($old === null) {
                    $rid = Db::insert('rsvp_responses', [
                        'uuid' => uuid4(), 'event_id' => $event['id'], 'event_guest_id' => $eg['id'], 'sub_event_id' => $s['id'],
                        'status' => $final, 'plus_ones' => $party[0], 'children' => $party[1], 'responded_at' => $now, 'updated_at' => $now,
                    ]);
                } elseif ($old['status'] !== $final || (int) $old['plus_ones'] !== $party[0] || (int) $old['children'] !== $party[1]) {
                    $rid = (int) $old['id'];
                    Db::update('rsvp_responses', ['status' => $final, 'plus_ones' => $party[0], 'children' => $party[1], 'updated_at' => $now], ['id' => $rid]);
                    $freed = $old['status'] === 'attending';
                } else {
                    return;
                }
                Db::insert('rsvp_response_history', [
                    'response_id' => $rid, 'event_guest_id' => $eg['id'], 'old_status' => $old['status'] ?? null, 'new_status' => $final,
                    'old_plus_ones' => $old['plus_ones'] ?? null, 'new_plus_ones' => $party[0],
                    'old_children' => $old['children'] ?? null, 'new_children' => $party[1],
                    'changes_json' => json_out(['function' => $s['name']]), 'actor_type' => $actor, 'actor_user_id' => $actorUserId, 'created_at' => $now,
                ]);
            });
            if ($freed) {
                $this->promoteWaitlist($event, $settings, (int) $s['id']);
            }
        }
    }
    private function sendPromotionEmail(array $event, int $egId): void
    {
        $row = Db::one('SELECT eg.*, g.first_name, g.email FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE eg.id = ?', [$egId]);
        if (!$row || !$row['email'] || $row['anonymized_at']) {
            return;
        }
        $this->notifier->email($row['email'], $row['first_name'], 'A place opened up: ' . $event['title'], 'waitlist_promoted', [
            'name' => $row['first_name'], 'event' => $event, 'link' => $this->links->guest($row, 'em'),
            'when' => fmt_dt($event['start_at'], $event['timezone']), 'footer' => $this->guestFooter($row, $event),
        ], ['event_id' => $event['id'], 'event_guest_id' => $egId, 'transactional' => true, 'reply_to' => null]);
    }

    /** Queue the "your RSVP is confirmed" email. No-op without an email address. */
    public function sendConfirmation(array $event, array $eg, array $guest, array $response): void
    {
        if (empty($guest['email']) || !empty($guest['anonymized_at'])) {
            return;
        }
        \App\Core\Lang::set($guest['language'] ?: $event['language']);
        $this->notifier->email($guest['email'], $guest['first_name'], t('email.confirm_subject', ['event' => $event['title']]), 'rsvp_confirmation', [
            'name' => $guest['first_name'], 'event' => $event, 'response' => $response, 'link' => $this->links->guest($eg, 'em'),
            'when' => fmt_dt($event['start_at'], $event['timezone']), 'footer' => $this->guestFooter($eg, $event),
            'calendar' => $this->links->googleCalendar($event), 'map' => $this->links->map($event),
        ], ['event_id' => $event['id'], 'event_guest_id' => $eg['id'], 'transactional' => true]);
    }

    public function guestFooter(array $eg, array $event): string
    {
        return e(t('email.footer', ['event' => $event['title']])) . ' <a href="' . e($this->links->unsubscribe($eg)) . '">' . e(t('email.unsubscribe')) . '</a>';
    }

    public function history(int $eventGuestId): array
    {
        return Db::all(
            'SELECT h.*, u.name AS actor_name FROM rsvp_response_history h LEFT JOIN users u ON u.id = h.actor_user_id
             WHERE h.event_guest_id = ? ORDER BY h.id DESC LIMIT 100',
            [$eventGuestId]
        );
    }
}
