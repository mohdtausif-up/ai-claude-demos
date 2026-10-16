<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Db;
use App\Services\Mailer;

/** Sends one queued email and records the outcome against the message recipient and guest. */
final class EmailJob implements JobHandler
{
    public function __construct(private Mailer $mailer, private \App\Services\WebhookService $webhooks)
    {
    }

    public function handle(array $p): void
    {
        $egId = (int) ($p['event_guest_id'] ?? 0);

        // Honour do-not-contact at send time, not only at queue time (the guest may have unsubscribed since).
        if ($egId > 0 && empty($p['transactional'])) {
            $dnc = Db::value('SELECT do_not_contact FROM event_guests WHERE id = ?', [$egId]);
            if ((int) $dnc === 1) {
                $this->markRecipient($p, 'skipped', 'Guest unsubscribed');
                return;
            }
        }

        $mid = $this->mailer->send([
            'to' => $p['to'],
            'to_name' => $p['to_name'] ?? '',
            'subject' => $p['subject'],
            'text' => $p['text'],
            'html' => $p['html'],
            'reply_to' => $p['reply_to'] ?? null,
            'list_unsubscribe' => $p['list_unsubscribe'] ?? null,
        ]);

        Db::insert('notification_logs', [
            'event_id' => $p['event_id'] ?? null,
            'event_guest_id' => $egId ?: null,
            'message_id' => $p['message_id'] ?? null,
            'channel' => 'email',
            'recipient' => $p['to'],
            'status' => 'sent',
            'provider_message_id' => $mid,
            'created_at' => utcnow(),
        ]);
        $this->markRecipient($p, 'sent', null);
        if (($p['kind'] ?? '') === 'invitation' && $egId > 0) {
            $row = Db::one('SELECT e.uuid AS event_uuid, e.workspace_id, eg.uuid, g.first_name, g.last_name, g.email FROM event_guests eg JOIN events e ON e.id = eg.event_id JOIN guests g ON g.id = eg.guest_id WHERE eg.id = ?', [$egId]);
            if ($row) {
                $this->webhooks->dispatch((int) $row['workspace_id'], 'invitation.sent', ['event_id' => $row['event_uuid'], 'guest_id' => $row['uuid'], 'first_name' => $row['first_name'], 'last_name' => $row['last_name'], 'email' => $row['email']]);
            }
        }
    }

    public function failed(array $p, string $error): void
    {
        Db::insert('notification_logs', [
            'event_id' => $p['event_id'] ?? null,
            'event_guest_id' => ($p['event_guest_id'] ?? null) ?: null,
            'message_id' => $p['message_id'] ?? null,
            'channel' => 'email',
            'recipient' => $p['to'] ?? '',
            'status' => 'failed',
            'error' => str_limit($error, 480),
            'created_at' => utcnow(),
        ]);
        $this->markRecipient($p, 'failed', $error);
    }

    private function markRecipient(array $p, string $status, ?string $error): void
    {
        $messageId = (int) ($p['message_id'] ?? 0);
        $egId = (int) ($p['event_guest_id'] ?? 0);
        if ($messageId === 0 || $egId === 0) {
            return;
        }
        Db::exec(
            'UPDATE message_recipients SET status = ?, error = ?, sent_at = ? WHERE message_id = ? AND event_guest_id = ?',
            [$status, $error !== null ? str_limit($error, 290) : null, $status === 'sent' ? utcnow() : null, $messageId, $egId]
        );
        if ($status === 'sent') {
            Db::exec(
                "UPDATE event_guests SET delivery_status = IF(delivery_status = 'bounced', 'bounced', 'sent'), delivery_error = NULL,
                        invite_sent_at = IF(?, COALESCE(invite_sent_at, ?), invite_sent_at) WHERE id = ?",
                [($p['kind'] ?? '') === 'invitation' ? 1 : 0, utcnow(), $egId]
            );
        } elseif ($status === 'failed') {
            Db::exec("UPDATE event_guests SET delivery_status = 'failed', delivery_error = ? WHERE id = ? AND delivery_status <> 'bounced'", [str_limit((string) $error, 290), $egId]);
        }
    }
}
