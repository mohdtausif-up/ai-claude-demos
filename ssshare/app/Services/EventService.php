<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Ids;
use Throwable;

/** Domain events fan out to webhook subscriptions as queued, signed deliveries. */
final class EventService
{
    public const EVENTS = [
        'upload.created' => 'A share was created',
        'upload.replaced' => 'A new version was uploaded',
        'upload.purged' => 'Content was physically deleted',
        'link.expired' => 'A link expired (time or inactivity)',
        'link.consumed' => 'A link reached its view or download limit',
        'link.revoked' => 'A link was revoked',
        'comment.created' => 'A comment was posted',
        'review.decided' => 'A reviewer approved or requested changes',
    ];

    public static function dispatch(string $event, array $upload, array $data = []): void
    {
        try {
            $hooks = Database::select(
                'SELECT * FROM webhooks WHERE active = 1 AND (user_id = ? OR (workspace_id IS NOT NULL AND workspace_id = ?))',
                [$upload['owner_user_id'] ?? 0, $upload['workspace_id'] ?? 0]
            );
            if ($hooks === []) {
                return;
            }
            $payload = [
                'id' => 'evt_' . Ids::publicId(20),
                'type' => $event,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', now()->getTimestamp()),
                'data' => [
                    'upload' => [
                        'id' => $upload['uuid'],
                        'type' => $upload['content_type'],
                        'title' => $upload['title'],
                        'status' => $upload['status'],
                    ],
                ] + $data,
            ];
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach ($hooks as $hook) {
                $events = json_decode($hook['events'], true) ?: [];
                if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
                    continue;
                }
                Database::insert('webhook_deliveries', [
                    'public_id' => Ids::publicId(14),
                    'webhook_id' => $hook['id'],
                    'event' => $event,
                    'payload' => $json,
                    'next_attempt_at' => db_time(),
                    'created_at' => db_time(),
                ]);
            }
        } catch (Throwable $e) {
            // Event delivery must never break the action that triggered it.
            error_log('Event dispatch failed: ' . $e->getMessage());
        }
    }
}
