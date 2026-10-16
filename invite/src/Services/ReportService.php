<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Read-only share links for reports (no guest names) and scheduled email summaries. */
final class ReportService
{
    public function __construct(private StatsService $stats, private Notifier $notifier, private AuditLogger $audit)
    {
    }

    /** @return string the raw token, shown once */
    public function createShare(array $user, array $event, string $label, ?int $days): string
    {
        $raw = 'rs_' . base64url(random_bytes(24));
        Db::insert('report_shares', [
            'event_id' => $event['id'], 'token_hash' => TokenService::hash($raw), 'label' => $label !== '' ? mb_substr($label, 0, 100) : null,
            'created_by' => $user['id'], 'expires_at' => $days ? utcnow($days * 86400) : null, 'created_at' => utcnow(),
        ]);
        $this->audit->event($user, $event, 'report.share_created', ['label' => $label]);
        return $raw;
    }

    public function shares(int $eventId): array
    {
        return Db::all('SELECT id, label, expires_at, revoked_at, created_at FROM report_shares WHERE event_id = ? ORDER BY id DESC', [$eventId]);
    }

    public function revoke(array $user, array $event, int $id): void
    {
        Db::exec('UPDATE report_shares SET revoked_at = ? WHERE id = ? AND event_id = ? AND revoked_at IS NULL', [utcnow(), $id, $event['id']]);
        $this->audit->event($user, $event, 'report.share_revoked', ['share' => $id]);
    }

    /** @return array<string,mixed>|null the event row when the token is valid */
    public function eventForToken(string $raw): ?array
    {
        return Db::one(
            'SELECT e.* FROM report_shares s JOIN events e ON e.id = s.event_id
             WHERE s.token_hash = ? AND s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > ?) AND e.deleted_at IS NULL AND e.moderation_status = \'ok\'',
            [TokenService::hash($raw), utcnow()]
        );
    }

    /** Aggregates only: nothing that identifies a guest. */
    public function publicView(array $event): array
    {
        $settings = Db::one('SELECT * FROM event_settings WHERE event_id = ?', [$event['id']]) ?? [];
        return [
            'summary' => $this->stats->summary($event, $settings), 'meals' => $this->stats->mealDistribution((int) $event['id']),
            'trend' => $this->stats->trend((int) $event['id']),
        ];
    }

    // ---- scheduled emails ----------------------------------------------------------------------------------

    public function schedule(array $user, array $event, ?string $frequency): void
    {
        if ($frequency === null) {
            Db::exec('DELETE FROM scheduled_reports WHERE event_id = ? AND user_id = ?', [$event['id'], $user['id']]);
            return;
        }
        Db::run('INSERT INTO scheduled_reports (event_id, user_id, frequency, created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE frequency = VALUES(frequency)', [$event['id'], $user['id'], $frequency, utcnow()]);
    }

    public function scheduleFor(int $eventId, int $userId): ?array
    {
        return Db::one('SELECT * FROM scheduled_reports WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    }

    /** Scheduler: send due summaries; stop once the event is over. @return int emails queued */
    public function sendDue(?int $now = null): int
    {
        $now ??= time();
        $n = 0;
        $rows = Db::all(
            "SELECT sr.*, e.*, sr.id AS sr_id, u.email AS u_email, u.name AS u_name, u.status AS u_status FROM scheduled_reports sr
             JOIN events e ON e.id = sr.event_id JOIN users u ON u.id = sr.user_id WHERE e.deleted_at IS NULL AND e.status IN ('published','closed')"
        );
        foreach ($rows as $r) {
            $end = strtotime(($r['end_at'] ?: $r['start_at']) . ' UTC');
            $period = $r['frequency'] === 'daily' ? 86400 : 7 * 86400;
            $last = $r['last_sent_at'] ? strtotime($r['last_sent_at'] . ' UTC') : 0;
            if ($now > $end + 3 * 86400 || $now - $last < $period - 300 || $r['u_status'] !== 'active') {
                continue;
            }
            // Only members who can still see reports receive them.
            $user = Db::one('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
            if (!$user || !Gate::roleAllows(Gate::roleIn((int) $user['id'], (int) $r['workspace_id']), 'reports.view')) {
                continue;
            }
            Gate::forget();
            $settings = Db::one('SELECT * FROM event_settings WHERE event_id = ?', [$r['event_id']]) ?? [];
            $s = $this->stats->summary($r, $settings);
            $this->notifier->email($r['u_email'], $r['u_name'], 'RSVP summary: ' . $r['title'], 'report_summary', [
                'event' => $r, 'summary' => $s, 'link' => app_url('app/events/' . $r['uuid'] . '/reports'),
            ], ['event_id' => $r['event_id'], 'transactional' => true]);
            Db::exec('UPDATE scheduled_reports SET last_sent_at = ? WHERE id = ?', [utcnow(), $r['sr_id']]);
            $n++;
        }
        return $n;
    }
}