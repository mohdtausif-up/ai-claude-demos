<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;

/** Approval workflow: reviewers record pending / approved / changes requested. */
final class ReviewService
{
    public const DECISIONS = ['pending' => 'Pending', 'approved' => 'Approved', 'changes_requested' => 'Changes requested'];

    public function requestReview(array $upload, bool $expireOnApproval): void
    {
        Database::update('uploads', [
            'review_status' => 'pending',
            'expire_on_review_complete' => $expireOnApproval ? 1 : 0,
            'updated_at' => db_time(),
        ], 'id = ?', [$upload['id']]);
        AuditLogService::record('review.requested', 'upload', $upload['uuid'], ['expire_on_approval' => $expireOnApproval]);
    }

    public function decide(array $upload, array $actor, string $decision, ?string $note): array
    {
        if (!isset(self::DECISIONS[$decision])) {
            throw HttpException::validation(['decision' => 'Choose a decision.']);
        }
        if ($upload['review_status'] === 'none') {
            throw new HttpException(409, 'invalid_state', 'This share is not open for review.');
        }
        if ($actor['user_id'] === null && (int) $upload['anonymous_comments'] !== 1) {
            throw new HttpException(401, 'login_required', 'Sign in to review.');
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 1000) : null;
        Database::insert('review_decisions', [
            'upload_id' => $upload['id'], 'upload_version_id' => $upload['current_version_id'],
            'reviewer_user_id' => $actor['user_id'], 'reviewer_name' => $actor['name'],
            'decision' => $decision, 'note' => $note ?: null, 'created_at' => db_time(),
        ]);
        Database::update('uploads', ['review_status' => $decision, 'updated_at' => db_time()], 'id = ?', [$upload['id']]);

        $label = strtolower(self::DECISIONS[$decision]);
        if ($upload['owner_user_id'] !== null && (int) $upload['owner_user_id'] !== $actor['user_id']) {
            NotificationService::notify((int) $upload['owner_user_id'], 'review', $actor['name'] . ' ' . $label . ': ' . ($upload['title'] ?? 'your share'), $note, '/manage/' . $upload['uuid'], 'review_decision', [
                'reviewer' => $actor['name'], 'decision' => $label, 'title' => $upload['title'] ?? 'your share', 'note' => $note ?? '',
            ]);
        }
        EventService::dispatch('review.decided', $upload, ['decision' => $decision, 'reviewer' => $actor['name']]);
        AuditLogService::record('review.decided', 'upload', $upload['uuid'], ['decision' => $decision]);

        // "Delete at the end of a review": approval ends every link.
        if ($decision === 'approved' && (int) $upload['expire_on_review_complete'] === 1) {
            $expiration = new ExpirationService();
            Database::transaction(function () use ($upload, $expiration) {
                foreach (Database::select("SELECT * FROM share_links WHERE upload_id = ? AND status IN ('active', 'paused') FOR UPDATE", [$upload['id']]) as $link) {
                    $expiration->endLink($link, 'expired', 'review_complete', (int) config('app.expiration.consume_grace_seconds'));
                }
            });
        }
        return Database::first('SELECT * FROM uploads WHERE id = ?', [$upload['id']]);
    }

    public static function history(int $uploadId): array
    {
        return Database::select(
            'SELECT d.*, v.version_number FROM review_decisions d LEFT JOIN upload_versions v ON v.id = d.upload_version_id WHERE d.upload_id = ? ORDER BY d.id DESC',
            [$uploadId]
        );
    }
}
