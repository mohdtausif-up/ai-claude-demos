<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Request;

/**
 * Privacy-conscious analytics: coarse device/browser buckets and referrer domains only.
 * No fingerprinting; the viewer hash rotates daily and cannot be reversed to an IP.
 */
final class AnalyticsService
{
    public static function viewerHash(Request $request): string
    {
        $day = now()->format('Y-m-d');
        return hash_hmac('sha256', implode('|', ['viewer', client_ip(), self::browser($request->userAgent()), $day]), config('app.key'));
    }

    public static function device(string $ua): string
    {
        $ua = strtolower($ua);
        return match (true) {
            $ua === '' => 'unknown',
            (bool) preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python-requests/', $ua) => 'bot',
            (bool) preg_match('/ipad|tablet|kindle|silk/', $ua) => 'tablet',
            (bool) preg_match('/mobi|iphone|android/', $ua) => 'mobile',
            default => 'desktop',
        };
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains(strtolower($ua), 'curl') => 'curl',
            default => 'Other',
        };
    }

    public static function referrerDomain(Request $request): ?string
    {
        $host = parse_url((string) $request->header('referer', ''), PHP_URL_HOST);
        if (!is_string($host) || $host === '' || $host === parse_url(config('app.url'), PHP_URL_HOST)) {
            return null;
        }
        return substr(strtolower($host), 0, 255);
    }

    /**
     * Coarse country from a CDN/edge header (e.g. CF-IPCountry). Only read when the operator
     * configures the header, because clients can forge it when no trusted edge sets it.
     */
    public static function country(Request $request): ?string
    {
        $header = (string) config('app.analytics.country_header', '');
        if ($header === '') {
            return null;
        }
        $code = strtoupper(trim((string) $request->header(strtolower($header), '')));
        return preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, ['XX', 'T1'], true) ? $code : null;
    }

    public static function recordView(int $linkId, string $outcome, Request $request, string $viewerHash, bool $unique = false): void
    {
        $ua = $request->userAgent();
        $detailed = self::detailed($linkId, $request);
        Database::insert('view_events', [
            'share_link_id' => $linkId,
            'outcome' => $outcome,
            'viewer_hash' => $viewerHash,
            'is_unique' => $unique ? 1 : 0,
            'referrer_domain' => $detailed ? self::referrerDomain($request) : null,
            'device' => $detailed ? self::device($ua) : null,
            'browser' => $detailed ? self::browser($ua) : null,
            'country' => $detailed ? self::country($request) : null,
            'created_at' => db_time(),
        ]);
    }

    public static function recordDownload(int $linkId, Request $request, string $viewerHash): void
    {
        $ua = $request->userAgent();
        $detailed = self::detailed($linkId, $request);
        Database::insert('download_events', [
            'share_link_id' => $linkId,
            'viewer_hash' => $viewerHash,
            'device' => $detailed ? self::device($ua) : null,
            'browser' => $detailed ? self::browser($ua) : null,
            'created_at' => db_time(),
        ]);
    }

    /**
     * Detailed analytics (referrer, device, browser, country) are skipped when the owner opted out or the
     * viewer sends Global Privacy Control / Do Not Track. Counts needed for view limits remain.
     */
    private static function detailed(int $linkId, Request $request): bool
    {
        if ($request->header('sec-gpc') === '1' || $request->header('dnt') === '1') {
            return false;
        }
        static $optOut = [];
        $optOut[$linkId] ??= (int) Database::value(
            'SELECT COALESCE(u.analytics_opt_out, 0) FROM share_links l JOIN uploads up ON up.id = l.upload_id LEFT JOIN users u ON u.id = up.owner_user_id WHERE l.id = ?',
            [$linkId]
        ) === 1;
        return !$optOut[$linkId];
    }

    /** Daily views for the owner's timeline chart (last $days days, zero-filled). */
    public static function timeline(int $uploadId, int $days = 14): array
    {
        $rows = Database::select(
            "SELECT DATE(e.created_at) AS day, COUNT(*) AS views, SUM(e.is_unique) AS uniques
             FROM view_events e JOIN share_links l ON l.id = e.share_link_id
             WHERE l.upload_id = ? AND e.outcome = 'ok' AND e.created_at >= ? GROUP BY DATE(e.created_at)",
            [$uploadId, db_time(now()->modify('-' . ($days - 1) . ' days')->setTime(0, 0))]
        );
        $byDay = array_column($rows, null, 'day');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->modify("-$i days")->format('Y-m-d');
            $series[] = ['day' => $day, 'views' => (int) ($byDay[$day]['views'] ?? 0), 'uniques' => (int) ($byDay[$day]['uniques'] ?? 0)];
        }
        return $series;
    }

    /** Summary for the owner's management page. */
    public static function summary(int $linkId): array
    {
        $group = static fn (string $column) => Database::select(
            "SELECT $column AS label, COUNT(*) AS total FROM view_events
             WHERE share_link_id = ? AND outcome = 'ok' AND $column IS NOT NULL
             GROUP BY $column ORDER BY total DESC LIMIT 5",
            [$linkId]
        );

        return [
            'referrers' => $group('referrer_domain'),
            'devices' => $group('device'),
            'browsers' => $group('browser'),
            'countries' => $group('country'),
            'denied' => (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'denied'", [$linkId]),
            'password_failures' => (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'password_failed'", [$linkId]),
            'link_copied' => (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'link_copied'", [$linkId]),
            'qr_shown' => (int) Database::value("SELECT COUNT(*) FROM view_events WHERE share_link_id = ? AND outcome = 'qr_shown'", [$linkId]),
        ];
    }
}
