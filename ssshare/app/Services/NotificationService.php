<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/** In-app notifications with optional email, respecting each user's email preference. */
final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, ?string $body, ?string $url, ?string $emailTemplate = null, array $vars = []): void
    {
        Database::insert('notifications', [
            'user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 200),
            'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'url' => $url, 'created_at' => db_time(),
        ]);
        if ($emailTemplate !== null) {
            $user = Database::first('SELECT email, email_notifications FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
            if ($user !== null && (int) $user['email_notifications'] === 1) {
                MailService::queue($user['email'], $emailTemplate, $vars + ['url' => $url !== null ? url(ltrim($url, '/')) : '']);
            }
        }
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function recent(int $userId, int $limit = 30): array
    {
        return Database::select("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT $limit", [$userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Database::execute('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [db_time(), $userId]);
    }
}
