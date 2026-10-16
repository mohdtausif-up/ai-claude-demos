<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/** Sends each invited recipient their own link and reports per-recipient activity. */
final class RecipientService
{
    public static function notify(array $upload, ?array $sender, ?string $message = null): void
    {
        $summary = ExpirationService::summary((new ExpirationService())->policyFor(
            (int) Database::value('SELECT id FROM share_links WHERE upload_id = ? ORDER BY id LIMIT 1', [$upload['id']])
        ), $upload['content_type']);
        foreach (Database::select('SELECT * FROM share_links WHERE upload_id = ? AND recipient_email IS NOT NULL', [$upload['id']]) as $link) {
            MailService::queue($link['recipient_email'], 'share_link', [
                'sender' => $sender['display_name'] ?? 'Someone',
                'type' => $upload['content_type'],
                'url' => SharePresenter::shareUrl($link),
                'summary' => $summary,
                'message' => $message ?? '',
            ]);
        }
    }

    /** Recipient activity for the owner: who has opened their link. */
    public static function activity(int $uploadId): array
    {
        return Database::select(
            'SELECT public_id, recipient_email, status, view_count, last_accessed_at FROM share_links
             WHERE upload_id = ? AND recipient_email IS NOT NULL ORDER BY recipient_email',
            [$uploadId]
        );
    }
}
