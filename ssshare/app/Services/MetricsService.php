<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use Throwable;

/** Launch metrics (PRD §18), computed from first-party data only. */
final class MetricsService
{
    public static function recordAttempt(string $via, string $kind, ?array $user, ?array $upload, bool $ok, ?string $errorCode, int $processingMs, array $options): int
    {
        try {
            $default = ($options['expires_in'] ?? null) === null || in_array($options['expires_in'], ['', ExpirationService::DEFAULT_PRESET], true);
            $extraRules = !empty($options['expires_at']) || !empty($options['view_limit']) || !empty($options['burn_after_view'])
                || !empty($options['download_limit']) || !empty($options['inactive_after']);
            return Database::insert('upload_attempts', [
                'via' => $via,
                'kind' => substr($kind, 0, 16),
                'user_id' => $user['id'] ?? null,
                'upload_id' => $upload['id'] ?? null,
                'ok' => $ok ? 1 : 0,
                'error_code' => $errorCode !== null ? substr($errorCode, 0, 48) : null,
                'processing_ms' => max(0, $processingMs),
                'non_default_expiry' => !$default || $extraRules ? 1 : 0,
                'password' => !empty($options['password']) ? 1 : 0,
                'created_at' => db_time(),
            ]);
        } catch (Throwable) {
            return 0; // metrics never break uploads
        }
    }

    /** The browser reports paste/drop-to-link time for its own successful attempt. */
    public static function recordClientTiming(int $attemptId, int $ms, ?int $userId): void
    {
        if ($ms < 0 || $ms > 600000) {
            throw HttpException::validation(['ms' => 'Invalid timing.']);
        }
        Database::execute(
            'UPDATE upload_attempts SET client_ms = ? WHERE id = ? AND client_ms IS NULL AND ok = 1 AND created_at >= ? AND (user_id <=> ?)',
            [$ms, $attemptId, db_time(now()->modify('-10 minutes')), $userId]
        );
    }

    public static function recordNps(?int $userId, int $score, ?string $comment): void
    {
        if ($score < 0 || $score > 10) {
            throw HttpException::validation(['score' => 'Choose a score from 0 to 10.']);
        }
        Database::insert('nps_responses', ['user_id' => $userId, 'score' => $score, 'comment' => $comment !== null ? mb_substr(trim($comment), 0, 1000) ?: null : null, 'created_at' => db_time()]);
    }

    public static function npsDue(int $userId): bool
    {
        $created = Database::value('SELECT created_at FROM users WHERE id = ?', [$userId]);
        $last = Database::value('SELECT MAX(created_at) FROM nps_responses WHERE user_id = ?', [$userId]);
        return $created !== null && strtotime($created . ' UTC') < now()->getTimestamp() - 7 * 86400
            && ($last === null || strtotime($last . ' UTC') < now()->getTimestamp() - 90 * 86400);
    }

    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    private static function pct(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /** @return array<string, array{label:string, value:?float, unit:string, target:?string, note?:string}> */
    public static function dashboard(int $days = 30): array
    {
        $since = db_time(now()->modify("-$days days"));
        $a = Database::first(
            'SELECT COUNT(*) AS total, SUM(ok) AS ok, SUM(ok = 1 AND non_default_expiry = 1) AS custom, SUM(ok = 1 AND password = 1) AS pw,
                    SUM(via = "api" OR via = "mcp") AS api FROM upload_attempts WHERE created_at >= ?',
            [$since]
        );
        $okCount = (int) $a['ok'];
        $client = array_map('intval', array_column(Database::select('SELECT client_ms FROM upload_attempts WHERE client_ms IS NOT NULL AND created_at >= ?', [$since]), 'client_ms'));
        $processing = array_map('intval', array_column(Database::select('SELECT processing_ms FROM upload_attempts WHERE ok = 1 AND created_at >= ?', [$since]), 'processing_ms'));
        $links = Database::first('SELECT COUNT(*) AS total, SUM(view_count > 0) AS opened FROM share_links WHERE created_at >= ?', [$since]);
        $jobs = Database::first("SELECT COUNT(*) AS total, SUM(status = 'done') AS done FROM deletion_jobs WHERE created_at >= ? AND run_after <= ?", [$since, db_time(now()->modify('-10 minutes'))]);
        $cohort = static function (int $age) use ($since): ?float {
            $row = Database::first(
                'SELECT COUNT(*) AS n, SUM(EXISTS (SELECT 1 FROM user_sessions s WHERE s.user_id = u.id AND s.last_seen_at >= u.created_at + INTERVAL ? DAY)
                        OR EXISTS (SELECT 1 FROM uploads x WHERE x.owner_user_id = u.id AND x.created_at >= u.created_at + INTERVAL ? DAY)) AS kept
                 FROM users u WHERE u.created_at <= ? - INTERVAL ? DAY',
                [$age, $age, db_time(), $age]
            );
            return self::pct((int) $row['kept'], (int) $row['n']);
        };
        $users = (int) Database::value('SELECT COUNT(*) FROM users');
        $paid = (int) Database::value("SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status IN ('active', 'trialing', 'past_due')");
        $activeUsers = (int) Database::value('SELECT COUNT(DISTINCT owner_user_id) FROM uploads WHERE created_at >= ?', [$since]);
        $storage = (int) Database::value("SELECT COALESCE(SUM(storage_bytes), 0) FROM uploads WHERE status <> 'deleted' AND owner_user_id IS NOT NULL");
        $reports = Database::first('SELECT COUNT(*) AS total, SUM(status = "dismissed") AS dismissed FROM abuse_reports WHERE created_at >= ?', [$since]);
        $nps = Database::first('SELECT COUNT(*) AS n, SUM(score >= 9) AS promoters, SUM(score <= 6) AS detractors FROM nps_responses WHERE created_at >= ?', [$since]);
        $apiUsers = (int) Database::value('SELECT COUNT(DISTINCT user_id) FROM api_keys WHERE last_used_at >= ?', [$since]);

        return [
            'upload_success' => ['label' => 'Upload completion rate', 'value' => self::pct($okCount, (int) $a['total']), 'unit' => '%', 'target' => '≥ 95%'],
            'paste_to_link' => ['label' => 'Median paste-to-link time', 'value' => ($m = self::median($client)) !== null ? round($m / 1000, 2) : null, 'unit' => 's', 'target' => '< 5 s'],
            'processing' => ['label' => 'Median server processing time', 'value' => ($p = self::median($processing)) !== null ? round($p) : null, 'unit' => 'ms', 'target' => null],
            'failure_rate' => ['label' => 'Upload failure rate', 'value' => self::pct((int) $a['total'] - $okCount, (int) $a['total']), 'unit' => '%', 'target' => null],
            'open_rate' => ['label' => 'Share-link open rate', 'value' => self::pct((int) $links['opened'], (int) $links['total']), 'unit' => '%', 'target' => null],
            'custom_expiry' => ['label' => 'Shares using a non-default expiry rule', 'value' => self::pct((int) $a['custom'], $okCount), 'unit' => '%', 'target' => '≥ 30%'],
            'passwords' => ['label' => 'Shares using passwords', 'value' => self::pct((int) $a['pw'], $okCount), 'unit' => '%', 'target' => null],
            'deletion_success' => ['label' => 'Automatic-deletion success rate', 'value' => self::pct((int) $jobs['done'], (int) $jobs['total']), 'unit' => '%', 'target' => '≥ 99.99%'],
            'retention_7' => ['label' => '7-day registered-user retention', 'value' => $cohort(7), 'unit' => '%', 'target' => null],
            'retention_30' => ['label' => '30-day registered-user retention', 'value' => $cohort(30), 'unit' => '%', 'target' => '≥ 20%'],
            'conversion' => ['label' => 'Free-to-paid conversion', 'value' => self::pct($paid, $users), 'unit' => '%', 'target' => null],
            'storage_per_user' => ['label' => 'Storage per active user', 'value' => $activeUsers > 0 ? (float) intdiv($storage, $activeUsers) : null, 'unit' => 'bytes', 'target' => null],
            'api_adoption' => ['label' => 'Accounts using the API or MCP', 'value' => self::pct($apiUsers, $users), 'unit' => '%', 'target' => null],
            'api_share' => ['label' => 'Uploads via API or MCP', 'value' => self::pct((int) $a['api'], (int) $a['total']), 'unit' => '%', 'target' => null],
            'abuse_rate' => ['label' => 'Abuse reports per 1,000 uploads', 'value' => $okCount > 0 ? round((int) $reports['total'] / $okCount * 1000, 2) : null, 'unit' => '', 'target' => null],
            'false_positive' => ['label' => 'Reports dismissed (false-positive rate)', 'value' => self::pct((int) $reports['dismissed'], (int) $reports['total']), 'unit' => '%', 'target' => null],
            'nps' => ['label' => 'Net Promoter Score', 'value' => (int) $nps['n'] > 0 ? round(((int) $nps['promoters'] - (int) $nps['detractors']) / (int) $nps['n'] * 100) : null, 'unit' => '', 'target' => null, 'note' => (int) $nps['n'] . ' responses'],
            'support' => ['label' => 'Support tickets per 1,000 uploads', 'value' => null, 'unit' => '', 'target' => null, 'note' => 'Connect your help desk; SSShare has no ticket data.'],
        ];
    }
}
