<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;
use App\Repositories\GuestRepository;

final class GuestService
{
    public function __construct(
        private GuestRepository $guests,
        private RsvpService $rsvp,
        private AuditLogger $audit,
        private QuestionService $questions,
        private RetentionService $retention,
        private WebhookService $webhooks
    ) {
    }

    /**
     * Add a person to an event's guest list.
     * @param array<string,mixed> $d validated GuestRequest data
     * @return array{eg:array,created:bool}
     * @throws ValidationException when the same email is already on this event's list
     */
    public function add(array $event, array $d, string $source = 'manual'): array
    {
        $email = !empty($d['email']) ? mb_strtolower((string) $d['email']) : null;
        if ($email && $this->guests->findByEmailInEvent((int) $event['id'], $email)) {
            throw new ValidationException(['email' => ['This email is already on the guest list.']], $d);
        }
        \App\Core\Container::get(PlanService::class)->assertCanAddGuests($event);
        $egId = Db::transaction(function () use ($event, $d, $email, $source) {
            $now = utcnow();
            $guestId = $email
                ? (int) Db::value('SELECT id FROM guests WHERE workspace_id = ? AND email = ? AND anonymized_at IS NULL LIMIT 1', [$event['workspace_id'], $email])
                : 0;
            if (!$guestId) {
                $guestId = Db::insert('guests', [
                    'uuid' => uuid4(), 'workspace_id' => $event['workspace_id'],
                    'first_name' => $d['first_name'], 'last_name' => $d['last_name'] ?? null,
                    'email' => $email, 'phone' => $d['phone'] ?? null, 'language' => $d['language'] ?: $event['language'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $egId = Db::insert('event_guests', [
                'uuid' => uuid4(), 'event_id' => $event['id'], 'guest_id' => $guestId,
                'group_id' => $this->groupId((int) $event['id'], $d['group_name'] ?? null),
                'source' => $source,
                'plus_one_limit' => $d['plus_one_limit'] ?? null, 'child_limit' => $d['child_limit'] ?? null,
                'is_vip' => !empty($d['is_vip']) ? 1 : 0, 'do_not_contact' => !empty($d['do_not_contact']) ? 1 : 0,
                'host_notes' => $d['host_notes'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->setTags($egId, (string) ($d['tags'] ?? ''));
            return $egId;
        });
        $eg = $this->guests->findById($egId);
        $this->webhooks->dispatch((int) $event['workspace_id'], 'guest.created', WebhookService::guestPayload($event, $eg, ['source' => $source]));
        return ['eg' => $eg, 'created' => true];
    }

    public function update(array $event, array $eg, array $d): array
    {
        $email = !empty($d['email']) ? mb_strtolower((string) $d['email']) : null;
        if ($email && $email !== mb_strtolower((string) $eg['email'])) {
            $other = $this->guests->findByEmailInEvent((int) $event['id'], $email);
            if ($other && (int) $other['id'] !== (int) $eg['id']) {
                throw new ValidationException(['email' => ['Another guest on this list already uses that email.']], $d);
            }
        }
        Db::transaction(function () use ($event, $eg, $d, $email) {
            Db::update('guests', [
                'first_name' => $d['first_name'], 'last_name' => $d['last_name'] ?? null, 'email' => $email,
                'phone' => $d['phone'] ?? null, 'language' => $d['language'] ?: $event['language'], 'updated_at' => utcnow(),
            ], ['id' => $eg['guest_id']]);
            Db::update('event_guests', [
                'group_id' => $this->groupId((int) $event['id'], $d['group_name'] ?? null),
                'plus_one_limit' => $d['plus_one_limit'] ?? null, 'child_limit' => $d['child_limit'] ?? null,
                'is_vip' => !empty($d['is_vip']) ? 1 : 0, 'do_not_contact' => !empty($d['do_not_contact']) ? 1 : 0,
                'host_notes' => $d['host_notes'] ?? null, 'updated_at' => utcnow(),
            ], ['id' => $eg['id']]);
            $this->setTags((int) $eg['id'], (string) ($d['tags'] ?? ''));
        });
        return $this->guests->findById((int) $eg['id']);
    }

    public function remove(array $event, array $eg): void
    {
        Db::exec('DELETE FROM event_guests WHERE id = ? AND event_id = ?', [$eg['id'], $event['id']]);
    }

    private function groupId(int $eventId, ?string $name): ?int
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }
        $id = Db::value('SELECT id FROM guest_groups WHERE event_id = ? AND name = ?', [$eventId, $name]);
        return $id ? (int) $id : Db::insert('guest_groups', ['uuid' => uuid4(), 'event_id' => $eventId, 'name' => $name, 'created_at' => utcnow()]);
    }

    public function setTags(int $egId, string $csv): void
    {
        $tags = array_values(array_unique(array_filter(array_map(
            static fn ($t) => mb_substr(trim($t), 0, 50),
            preg_split('/[,;]/', $csv) ?: []
        ))));
        Db::exec('DELETE FROM guest_tags WHERE event_guest_id = ?', [$egId]);
        foreach (array_slice($tags, 0, 20) as $t) {
            Db::insert('guest_tags', ['event_guest_id' => $egId, 'tag' => $t]);
        }
    }

    /**
     * Apply one action to many guests of this event. Returns how many were affected.
     * @param list<string> $uuids event_guest uuids
     */
    public function bulk(array $user, array $event, array $settings, string $action, array $uuids, string $value = ''): int
    {
        $uuids = array_values(array_filter($uuids, 'is_uuid'));
        if (!$uuids) {
            return 0;
        }
        $rows = Db::all(
            'SELECT id FROM event_guests WHERE event_id = ? AND uuid IN (' . Db::placeholders($uuids) . ')',
            [$event['id'], ...$uuids]
        );
        $ids = array_map('intval', array_column($rows, 'id'));
        $n = 0;
        foreach ($ids as $id) {
            $eg = $this->guests->findById($id);
            switch ($action) {
                case 'status':
                    if (in_array($value, ['attending', 'declined', 'maybe'], true)) {
                        $cur = ['plus_ones' => (int) ($eg['plus_ones'] ?? 0), 'children' => (int) ($eg['children'] ?? 0)];
                        $this->rsvp->submit($event, $settings, $eg, ['status' => $value] + $cur, 'host', (int) $user['id'], true);
                        $n++;
                    }
                    break;
                case 'tag':
                    if (trim($value) !== '') {
                        Db::run('INSERT IGNORE INTO guest_tags (event_guest_id, tag) VALUES (?, ?)', [$id, mb_substr(trim($value), 0, 50)]);
                        $n++;
                    }
                    break;
                case 'group':
                    Db::update('event_guests', ['group_id' => $this->groupId((int) $event['id'], $value), 'updated_at' => utcnow()], ['id' => $id]);
                    $n++;
                    break;
                case 'vip':
                    Db::update('event_guests', ['is_vip' => $value === '0' ? 0 : 1, 'updated_at' => utcnow()], ['id' => $id]);
                    $n++;
                    break;
                case 'delete':
                    $this->remove($event, $eg);
                    $n++;
                    break;
            }
        }
        $this->audit->event($user, $event, 'guests.bulk_' . $action, ['count' => $n, 'value' => $action === 'delete' ? null : $value]);
        return $n;
    }

    /** Host edits one guest's RSVP (status, party size, answers). Recorded in history with the host as actor. */
    public function hostRespond(array $user, array $event, array $settings, array $eg, array $input): array
    {
        $result = $this->rsvp->submit($event, $settings, $eg, $input, 'host', (int) $user['id'], true);
        $this->audit->event($user, $event, 'rsvp.host_edited', ['guest' => $eg['uuid'], 'status' => $result['status']]);
        return $result;
    }

    /** New personal link and QR code; the old ones stop working immediately. */
    public function rotateLink(array $user, array $event, array $eg): void
    {
        Db::transaction(function () use ($eg) {
            Db::exec('UPDATE event_guests SET token_version = token_version + 1, updated_at = ? WHERE id = ?', [utcnow(), $eg['id']]);
            Db::exec('UPDATE qr_tokens SET revoked_at = ? WHERE event_guest_id = ? AND revoked_at IS NULL', [utcnow(), $eg['id']]);
        });
        $this->audit->event($user, $event, 'guest.link_rotated', ['guest' => $eg['uuid']]);
    }

    /**
     * Erase a guest's personal data (host-initiated or guest "delete my data"). The RSVP stays as an anonymous
     * headcount so event totals remain correct; names, contact details, free-text answers and notes are removed.
     */
    public function anonymize(?array $user, array $event, array $eg): void
    {
        Db::transaction(function () use ($eg) {
            $this->retention->anonymizeGuestRow((int) $eg['guest_id']);
            Db::exec('UPDATE event_guests SET anonymized_at = ?, do_not_contact = 1, host_notes = NULL, delivery_error = NULL, token_version = token_version + 1, updated_at = ? WHERE id = ?', [utcnow(), utcnow(), $eg['id']]);
            Db::exec('DELETE FROM guest_tags WHERE event_guest_id = ?', [$eg['id']]);
            Db::exec('UPDATE qr_tokens SET revoked_at = ? WHERE event_guest_id = ? AND revoked_at IS NULL', [utcnow(), $eg['id']]);
            if (!empty($eg['response_id'])) {
                Db::exec('UPDATE rsvp_companions SET name = NULL WHERE response_id = ?', [$eg['response_id']]);
                Db::exec('UPDATE rsvp_responses SET comment = NULL WHERE id = ?', [$eg['response_id']]);
                // Free-text answers can contain personal details; choice answers stay for catering totals.
                Db::exec(
                    "DELETE a FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id
                     WHERE a.response_id = ? AND q.type IN ('short_text','long_text','dietary','song_request','date','number')",
                    [$eg['response_id']]
                );
            }
            Db::exec("UPDATE rsvp_response_history SET changes_json = NULL WHERE event_guest_id = ?", [$eg['id']]);
        });
        $this->audit->log('guest.anonymized', [
            'workspace_id' => (int) $event['workspace_id'], 'event_id' => (int) $event['id'],
            'actor_user_id' => $user ? (int) $user['id'] : null, 'actor_type' => $user ? 'user' : 'guest',
            'entity_type' => 'event_guest', 'entity_uuid' => $eg['uuid'],
        ]);
    }

    /** Guest unsubscribed from emails. */
    public function unsubscribe(array $eg): void
    {
        Db::exec('UPDATE event_guests SET do_not_contact = 1, updated_at = ? WHERE id = ?', [utcnow(), $eg['id']]);
    }

    /**
     * CSV of the guest list for the given filters. Cells are protected against spreadsheet formula injection.
     */
    public function exportCsv(array $event, array $filters): string
    {
        $result = $this->guests->search((int) $event['id'], $filters, false);
        $questions = $this->questions->forEvent((int) $event['id'], null, false);
        $responseIds = array_values(array_filter(array_map(static fn ($r) => $r['response_id'] ? (int) $r['response_id'] : null, $result['rows'])));
        $answers = $this->guests->answersFor($responseIds);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens non-Latin names correctly
        $head = ['First name', 'Last name', 'Email', 'Phone', 'Group', 'Tags', 'VIP', 'RSVP status', 'Plus-ones', 'Children', 'Headcount (attending)', 'Responded at (UTC)', 'Invitation sent (UTC)', 'Delivery', 'Opened', 'Host notes'];
        foreach ($questions as $q) {
            $head[] = $q['label'];
        }
        fputcsv($fh, array_map('csv_safe', $head));
        foreach ($result['rows'] as $r) {
            $status = $r['rsvp_status'] ?? 'pending';
            $row = [
                $r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['group_name'], $r['tags'], $r['is_vip'] ? 'yes' : '',
                $status, $r['plus_ones'] ?? '', $r['children'] ?? '',
                $status === 'attending' ? 1 + (int) $r['plus_ones'] + (int) $r['children'] : 0,
                $r['responded_at'], $r['invite_sent_at'], $r['delivery_status'], $r['first_opened_at'] ? 'yes' : 'no', $r['host_notes'],
            ];
            foreach ($questions as $q) {
                $a = $r['response_id'] ? ($answers[(int) $r['response_id']][(int) $q['id']] ?? '') : '';
                if ($a !== '' && $a[0] === '[') {
                    $d = json_decode($a, true);
                    $a = is_array($d) ? implode('; ', $d) : $a;
                }
                $row[] = $a;
            }
            fputcsv($fh, array_map('csv_safe', $row));
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
