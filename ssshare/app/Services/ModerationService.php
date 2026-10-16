<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\RateLimiter;

final class ModerationService
{
    public const REASONS = [
        'illegal' => 'Illegal content',
        'csam' => 'Child sexual abuse material',
        'copyright' => 'Copyright infringement',
        'harassment' => 'Harassment or hate',
        'personal_data' => 'Someone\'s private information',
        'malware' => 'Malware or phishing',
        'spam' => 'Spam',
        'other' => 'Something else',
    ];

    public function report(array $link, string $reason, ?string $details, ?string $email): string
    {
        RateLimiter::enforce('report:' . ip_hash(), 10, 3600, 'You have sent several reports recently. Please try again later.');
        $errors = [];
        if (!isset(self::REASONS[$reason])) {
            $errors['reason'] = 'Choose a reason.';
        }
        $details = $details !== null ? trim($details) : null;
        if ($details !== null && mb_strlen($details) > 2000) {
            $errors['details'] = 'Keep details under 2000 characters.';
        }
        $email = $email !== null && trim($email) !== '' ? trim($email) : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email or leave it blank.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $publicId = Ids::publicId(12);
        Database::insert('abuse_reports', [
            'public_id' => $publicId,
            'upload_id' => $link['upload_id'],
            'share_link_id' => $link['id'],
            'reason' => $reason,
            'details' => $details !== '' ? $details : null,
            'reporter_email' => $email,
            'reporter_ip_hash' => ip_hash(),
            'created_at' => db_time(),
        ]);
        AuditLogService::record('abuse.reported', 'abuse_report', $publicId, ['reason' => $reason, 'link' => $link['public_id']], 'guest');
        return $publicId;
    }

    public function takedown(array $report, array $admin, bool $blockHash, ?string $note): void
    {
        $upload = Database::first('SELECT * FROM uploads WHERE id = ?', [$report['upload_id']]);
        Database::transaction(function () use ($report, $admin, $blockHash, $note, $upload) {
            $now = db_time();
            if ($upload !== null && $blockHash) {
                $hashes = Database::select('SELECT DISTINCT source_sha256 FROM upload_files WHERE upload_id = ? AND source_sha256 IS NOT NULL', [$upload['id']]);
                foreach ($hashes as $h) {
                    Database::execute(
                        'INSERT IGNORE INTO blocked_hashes (sha256, reason, created_by, created_at) VALUES (?, ?, ?, ?)',
                        [$h['source_sha256'], 'report ' . $report['public_id'] . ': ' . $report['reason'], $admin['id'], $now]
                    );
                }
            }
            Database::update('abuse_reports', ['status' => 'actioned', 'resolved_by' => $admin['id'], 'resolved_at' => $now], 'id = ?', [$report['id']]);
            // Other open reports for the same upload are resolved by the same action.
            if ($upload !== null) {
                Database::execute(
                    "UPDATE abuse_reports SET status = 'actioned', resolved_by = ?, resolved_at = ? WHERE upload_id = ? AND status = 'open'",
                    [$admin['id'], $now, $upload['id']]
                );
            }
            Database::insert('moderation_actions', [
                'abuse_report_id' => $report['id'], 'upload_id' => $upload['id'] ?? null, 'admin_user_id' => $admin['id'],
                'action' => 'takedown', 'note' => $note, 'created_at' => $now,
            ]);
            if ($upload !== null) {
                Database::update('uploads', ['delete_reason' => 'takedown'], 'id = ?', [$upload['id']]);
            }
        });
        if ($upload !== null) {
            (new DeletionService())->deleteNow($upload, 'takedown', 'blocked');
        }
        AuditLogService::record('admin.report.takedown', 'abuse_report', $report['public_id'], [
            'upload' => $upload['uuid'] ?? null, 'blocked_hash' => $blockHash, 'note' => $note,
        ], 'admin', (int) $admin['id']);
    }

    public function dismiss(array $report, array $admin, ?string $note): void
    {
        Database::update('abuse_reports', ['status' => 'dismissed', 'resolved_by' => $admin['id'], 'resolved_at' => db_time()], 'id = ?', [$report['id']]);
        Database::insert('moderation_actions', [
            'abuse_report_id' => $report['id'], 'upload_id' => $report['upload_id'], 'admin_user_id' => $admin['id'],
            'action' => 'dismiss', 'note' => $note, 'created_at' => db_time(),
        ]);
        AuditLogService::record('admin.report.dismissed', 'abuse_report', $report['public_id'], ['note' => $note], 'admin', (int) $admin['id']);
    }
}
