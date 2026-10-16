<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\Lang;
use App\Core\ValidationException;

/** Email invitations, reminders and updates (queued), plus WhatsApp share text. */
final class MessageService
{
    public const KINDS = ['invitation' => 'Invitation', 'reminder' => 'Reminder', 'update' => 'Update', 'thank_you' => 'Thank you'];
    public const SEGMENTS = [
        'not_invited' => 'Guests not yet invited',
        'pending' => 'Guests who have not responded',
        'active' => 'Guests who are attending or maybe',
        'maybe' => 'Guests who said maybe',
        'attending' => 'Guests who are attending',
        'declined' => 'Guests who declined',
        'all' => 'Everyone on the list',
        'selected' => 'Selected guests',
    ];
    private const DAILY_LIMIT = 2000;

    public function __construct(
        private Notifier $notifier,
        private LinkService $links,
        private RsvpService $rsvp,
        private AuditLogger $audit
    ) {
    }

    /** Placeholders available in subject/body. */
    public static function placeholders(): array
    {
        return ['{first_name}', '{event_title}', '{event_date}', '{event_place}', '{link}', '{host}'];
    }

    public function defaultTemplate(array $event, string $kind): array
    {
        $host = $event['host_names'] ?: 'your host';
        return match ($kind) {
            'reminder' => [
                'subject' => 'Reminder: please RSVP to {event_title}',
                'body' => "Hi {first_name},\n\nA quick reminder to let us know whether you can join us for {event_title} on {event_date}.\n\nIt takes less than a minute: {link}\n\nThank you,\n{host}",
            ],
            'update' => [
                'subject' => 'Update about {event_title}',
                'body' => "Hi {first_name},\n\nThere is an update about {event_title}.\n\n[Write your update here]\n\nDetails and your RSVP: {link}\n\n{host}",
            ],
            'thank_you' => [
                'subject' => 'Thank you for celebrating with us',
                'body' => "Hi {first_name},\n\nThank you for being part of {event_title}. It meant a great deal to us.\n\nWith love,\n{host}",
            ],
            default => [
                'subject' => "You're invited: {event_title}",
                'body' => "Hi {first_name},\n\nYou are invited to {event_title}.\n\nWhen: {event_date}\nWhere: {event_place}\n\nPlease let us know if you can make it: {link}\n\nWarm wishes,\n{host}",
            ],
        };
    }

    /** @param array<string,mixed> $row guest row (BASE_SELECT) */
    public function render(string $text, array $event, array $row, ?string $channel = 'em'): string
    {
        Lang::set($row['language'] ?? $event['language']);
        $map = [
            '{first_name}' => $row['first_name'] ?? '',
            '{event_title}' => $event['title'],
            '{event_date}' => fmt_dt($event['start_at'], $event['timezone']),
            '{event_place}' => trim(($event['venue_name'] ?? '') . ($event['venue_address'] ? ', ' . $event['venue_address'] : '')) ?: 'see the invitation',
            '{link}' => isset($row['uuid']) ? $this->links->guest($row, $channel) : $this->links->event($event, $channel),
            '{host}' => $event['host_names'] ?: 'The hosts',
        ];
        return strtr($text, $map);
    }

    /** Guests matching a segment who can be emailed. @return array{recipients:list<array>,skipped:array<string,int>} */
    public function resolveSegment(int $eventId, string $segment, array $selectedUuids = []): array
    {
        $where = ['eg.event_id = ?', 'eg.anonymized_at IS NULL'];
        $params = [$eventId];
        switch ($segment) {
            case 'not_invited':
                $where[] = 'eg.invite_sent_at IS NULL';
                break;
            case 'pending':
                $where[] = 'r.id IS NULL';
                break;
            case 'active':
                $where[] = "r.status IN ('attending','maybe')";
                break;
            case 'maybe':
            case 'attending':
            case 'declined':
                $where[] = 'r.status = ?';
                $params[] = $segment;
                break;
            case 'selected':
                $selectedUuids = array_values(array_filter($selectedUuids, 'is_uuid'));
                if (!$selectedUuids) {
                    return ['recipients' => [], 'skipped' => ['no_selection' => 1]];
                }
                $where[] = 'eg.uuid IN (' . Db::placeholders($selectedUuids) . ')';
                array_push($params, ...$selectedUuids);
                break;
            case 'all':
                break;
            default:
                throw new ValidationException(['segment' => ['Choose who should receive this message.']]);
        }
        $rows = Db::all(
            'SELECT eg.*, g.first_name, g.last_name, g.email, g.phone, g.language
             FROM event_guests eg JOIN guests g ON g.id = eg.guest_id
             LEFT JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0
             WHERE ' . implode(' AND ', $where) . ' ORDER BY eg.id',
            $params
        );
        $skipped = ['no_email' => 0, 'unsubscribed' => 0];
        $ok = [];
        foreach ($rows as $r) {
            if ($r['do_not_contact']) {
                $skipped['unsubscribed']++;
            } elseif (empty($r['email'])) {
                $skipped['no_email']++;
            } else {
                $ok[] = $r;
            }
        }
        return ['recipients' => $ok, 'skipped' => $skipped];
    }

    /**
     * Queue one email per recipient. Returns the message row.
     * @throws ValidationException|ForbiddenException
     */
    public function send(array $user, array $event, array $d): array
    {
        if (!$user['email_verified_at']) {
            throw new ForbiddenException('Please confirm your email address before sending messages.');
        }
        if ($event['moderation_status'] !== 'ok') {
            throw new ForbiddenException('This event is suspended.');
        }
        if (!isset(self::KINDS[$d['kind']])) {
            throw new ValidationException(['kind' => ['Unknown message type.']]);
        }
        $resolved = $this->resolveSegment((int) $event['id'], $d['segment'], $d['selected'] ?? []);
        $recipients = $resolved['recipients'];
        if (!$recipients) {
            throw new ValidationException(['segment' => ['Nobody in this group can be emailed (missing email address or unsubscribed).']]);
        }
        $sentToday = (int) Db::value(
            'SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id JOIN events e ON e.id = m.event_id WHERE e.workspace_id = ? AND m.created_at > ?',
            [$event['workspace_id'], utcnow(-86400)]
        );
        if ($sentToday + count($recipients) > self::DAILY_LIMIT) {
            throw new ValidationException(['segment' => ['Daily sending limit reached for this workspace. Try again tomorrow or contact support.']]);
        }

        $at = !empty($d['scheduled_utc']) ? $d['scheduled_utc'] : (!empty($d['scheduled_at']) ? to_utc($d['scheduled_at'], $event['timezone']) : null);
        if ($at !== null && $at <= utcnow()) {
            $at = null;
        }
        $messageId = Db::insert('messages', [
            'uuid' => uuid4(), 'event_id' => $event['id'], 'sender_id' => $user['id'], 'kind' => $d['kind'], 'channel' => 'email',
            'subject' => mb_substr($d['subject'], 0, 200), 'body' => $d['body'], 'segment' => $d['segment'],
            'scheduled_at' => $at, 'created_at' => utcnow(),
        ]);
        foreach ($recipients as $r) {
            Db::insert('message_recipients', ['message_id' => $messageId, 'event_guest_id' => $r['id'], 'status' => 'queued']);
            if ($d['kind'] === 'invitation' || $r['delivery_status'] === 'none') {
                Db::exec("UPDATE event_guests SET delivery_status = 'queued', updated_at = ? WHERE id = ? AND delivery_status IN ('none','failed')", [utcnow(), $r['id']]);
            }
            $this->queueOne($event, $r, $d['kind'], $d['subject'], $d['body'], $messageId, $at, $user);
        }
        $this->audit->event($user, $event, 'message.sent', ['kind' => $d['kind'], 'segment' => $d['segment'], 'recipients' => count($recipients), 'scheduled' => $at]);
        $msg = Db::one('SELECT * FROM messages WHERE id = ?', [$messageId]);
        $msg['recipient_count'] = count($recipients);
        $msg['skipped'] = $resolved['skipped'];
        return $msg;
    }

    private function queueOne(array $event, array $row, string $kind, string $subject, string $body, ?int $messageId, ?string $at, array $sender): void
    {
        $text = $this->render($body, $event, $row);
        $this->notifier->email(
            $row['email'],
            trim($row['first_name'] . ' ' . ($row['last_name'] ?? '')),
            $this->render($subject, $event, $row),
            'guest_message',
            [
                'event' => $event, 'text' => $text, 'link' => $this->links->guest($row, 'em'), 'name' => $row['first_name'],
                'kind' => $kind, 'accent' => '#4338ca', 'footer' => $this->rsvp->guestFooter($row, $event),
                'map' => $this->links->map($event), 'when' => fmt_dt($event['start_at'], $event['timezone']),
                'cta' => t($kind === 'invitation' || $kind === 'reminder' ? 'email.cta_rsvp' : 'email.cta_view'),
            ],
            [
                'event_id' => $event['id'], 'event_guest_id' => $row['id'], 'message_id' => $messageId, 'kind' => $kind,
                'list_unsubscribe' => $this->links->unsubscribe($row), 'reply_to' => $sender['email'] ?? null,
            ],
            0,
            $at
        );
    }

    /** Send a sample to the host so they can check how it looks. Not recorded against any guest. */
    public function sendTest(array $user, array $event, array $d): void
    {
        $row = ['first_name' => explode(' ', $user['name'])[0], 'uuid' => null, 'language' => $event['language'], 'email' => $user['email'], 'id' => 0, 'token_version' => 1];
        $sample = $row + ['uuid' => uuid4()];
        $text = $this->render($d['body'], $event, $sample);
        $this->notifier->email($user['email'], $user['name'], '[Test] ' . $this->render($d['subject'], $event, $sample), 'guest_message', [
            'event' => $event, 'text' => $text, 'link' => $this->links->event($event, 'em'), 'name' => $row['first_name'], 'kind' => $d['kind'], 'accent' => '#4338ca',
            'footer' => 'This is a test email. It was sent only to you.', 'map' => $this->links->map($event),
            'when' => fmt_dt($event['start_at'], $event['timezone']), 'cta' => t('email.cta_rsvp'),
        ], ['event_id' => $event['id'], 'transactional' => true]);
    }

    /** WhatsApp share text for one guest (their personal link) or the public link. */
    public function whatsappText(array $event, ?array $row, ?string $template = null): string
    {
        $tpl = $template ?: "Hi {first_name}! You're invited to {event_title} on {event_date}. Please RSVP here: {link}";
        return $this->render($tpl, $event, $row ?? ['first_name' => 'there', 'language' => $event['language']], 'wa');
    }

    /** Record that the host opened the WhatsApp share flow for a guest. */
    public function markShared(array $eg, string $channel): void
    {
        Db::exec(
            "UPDATE event_guests SET last_channel = ?, invite_sent_at = COALESCE(invite_sent_at, ?),
                    delivery_status = IF(delivery_status IN ('none','failed'), 'sent', delivery_status), updated_at = ? WHERE id = ?",
            [$channel, utcnow(), utcnow(), $eg['id']]
        );
    }

    public function history(int $eventId): array
    {
        return Db::all(
            "SELECT m.*, u.name AS sender_name,
                    COUNT(mr.id) AS total,
                    SUM(mr.status = 'sent') AS sent, SUM(mr.status = 'queued') AS queued,
                    SUM(mr.status = 'failed') AS failed, SUM(mr.status = 'bounced') AS bounced, SUM(mr.status = 'skipped') AS skipped
             FROM messages m LEFT JOIN users u ON u.id = m.sender_id LEFT JOIN message_recipients mr ON mr.message_id = m.id
             WHERE m.event_id = ? GROUP BY m.id ORDER BY m.id DESC LIMIT 50",
            [$eventId]
        );
    }

    /** Guests whose last delivery failed or bounced, for the host's attention. */
    public function deliveryProblems(int $eventId): array
    {
        return Db::all(
            "SELECT eg.uuid, eg.delivery_status, eg.delivery_error, g.first_name, g.last_name, g.email
             FROM event_guests eg JOIN guests g ON g.id = eg.guest_id
             WHERE eg.event_id = ? AND eg.delivery_status IN ('failed','bounced') ORDER BY eg.updated_at DESC LIMIT 100",
            [$eventId]
        );
    }

    /** Provider bounce webhook: mark the guest/recipient as bounced. */
    public function recordBounce(string $providerMessageId, string $reason): bool
    {
        $log = Db::one("SELECT event_guest_id, message_id FROM notification_logs WHERE provider_message_id = ? AND status = 'sent' ORDER BY id DESC LIMIT 1", [$providerMessageId]);
        if (!$log || !$log['event_guest_id']) {
            return false;
        }
        Db::exec("UPDATE event_guests SET delivery_status = 'bounced', delivery_error = ?, updated_at = ? WHERE id = ?", [mb_substr($reason, 0, 290), utcnow(), $log['event_guest_id']]);
        if ($log['message_id']) {
            Db::exec("UPDATE message_recipients SET status = 'bounced', error = ? WHERE message_id = ? AND event_guest_id = ?", [mb_substr($reason, 0, 290), $log['message_id'], $log['event_guest_id']]);
        }
        return true;
    }
}
