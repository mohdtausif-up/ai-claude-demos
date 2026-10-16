<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Compound expiration engine. Rules are OR-combined: a link ends as soon as any rule
 * is satisfied. Every access decision is made while holding a row lock on the link, so
 * burn-after-view and view limits are atomic under concurrent requests.
 */
final class ExpirationService
{
    public const PRESETS = [
        '1m' => 60, '5m' => 300, '10m' => 600, '30m' => 1800,
        '1h' => 3600, '6h' => 21600, '12h' => 43200,
        '1d' => 86400, '3d' => 259200, '7d' => 604800, '30d' => 2592000,
        'never' => null,
    ];

    public const PRESET_LABELS = [
        '1m' => '1 minute', '5m' => '5 minutes', '10m' => '10 minutes', '30m' => '30 minutes',
        '1h' => '1 hour', '6h' => '6 hours', '12h' => '12 hours',
        '1d' => '1 day', '3d' => '3 days', '7d' => '7 days', '30d' => '30 days',
        'never' => 'Never (members)',
    ];

    public const INACTIVITY_PRESETS = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000];

    public const DEFAULT_PRESET = '1d';

    /** Sandbox (test-key) shares live at most an hour, whatever was requested. */
    public static function sandboxOptions(array $options): array
    {
        $seconds = self::PRESETS[(string) ($options['expires_in'] ?? '')] ?? null;
        if ($seconds === null || $seconds > 3600) {
            $options['expires_in'] = '1h';
        }
        if (!empty($options['expires_at']) && (strtotime((string) $options['expires_at']) ?: PHP_INT_MAX) > now()->getTimestamp() + 3600) {
            $options['expires_at'] = null;
        }
        return $options;
    }

    /**
     * Validates user input into a normalised policy.
     *
     * @return array{expires_at:?DateTimeImmutable, burn_after_view:bool, view_limit:?int,
     *               view_limit_type:string, download_limit:?int, inactive_after_seconds:?int}
     */
    public function buildPolicy(array $input, bool $member, int|null|false $maxSeconds = false): array
    {
        $errors = [];
        $now = now();
        // false: defaults (guests 30 days; members 365 days or never). null: unlimited. int: plan/workspace cap.
        $neverAllowed = $member && $maxSeconds !== false ? $maxSeconds === null : $member;
        $capMessage = null;
        if ($maxSeconds === false) {
            $maxSeconds = (int) config($member ? 'app.expiration.member_max_seconds' : 'app.expiration.guest_max_seconds');
        } elseif ($maxSeconds === null) {
            $maxSeconds = PHP_INT_MAX;
        } elseif ($member) {
            $capMessage = 'Your plan or workspace policy allows shares up to ' . self::humanDuration($maxSeconds) . '.';
        }
        $expiresAt = null;

        $customAt = $input['expires_at'] ?? null;
        $expiresIn = $input['expires_in'] ?? null;
        if (is_string($customAt) && trim($customAt) !== '') {
            try {
                $expiresAt = (new DateTimeImmutable($customAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
                if ($expiresAt < $now->modify('+59 seconds')) {
                    $errors['expires_at'] = 'Choose a time at least one minute from now.';
                }
            } catch (Throwable) {
                $errors['expires_at'] = 'Enter a valid date and time.';
            }
        } else {
            $expiresIn = $expiresIn === null || $expiresIn === '' ? self::DEFAULT_PRESET : (string) $expiresIn;
            if (array_key_exists($expiresIn, self::PRESETS)) {
                $seconds = self::PRESETS[$expiresIn];
                if ($seconds === null && !$neverAllowed) {
                    $errors['expires_in'] = $member
                        ? ($capMessage ?? 'Shares must expire.') . ' Choose an expiry.'
                        : 'Sign in to keep shares without an expiry.';
                }
            } elseif (ctype_digit($expiresIn) && (int) $expiresIn >= 60) {
                $seconds = (int) $expiresIn;
            } else {
                $errors['expires_in'] = 'Choose an expiry between 1 minute and 30 days.';
                $seconds = null;
            }
            if (!isset($errors['expires_in']) && $seconds !== null) {
                $expiresAt = $now->modify("+{$seconds} seconds");
            }
        }
        if ($expiresAt !== null && !isset($errors['expires_at']) && $expiresAt->getTimestamp() - $now->getTimestamp() > $maxSeconds) {
            $errors[isset($customAt) && $customAt !== '' ? 'expires_at' : 'expires_in'] = $member
                ? ($capMessage ?? 'Shares can last up to 365 days.')
                : 'Guest shares can last up to 30 days. Sign in for longer retention.';
        }

        $burn = filter_var($input['burn_after_view'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $viewLimit = self::optionalInt($input['view_limit'] ?? null, 1, 999, 'view_limit', 'Choose between 1 and 999 views.', $errors);
        $type = ($input['view_limit_type'] ?? 'unique') === 'total' ? 'total' : 'unique';
        $downloadLimit = self::optionalInt($input['download_limit'] ?? null, 1, 999, 'download_limit', 'Choose between 1 and 999 downloads.', $errors);

        $inactive = $input['inactive_after'] ?? null;
        $inactiveSeconds = null;
        if ($inactive !== null && $inactive !== '') {
            $inactiveSeconds = self::INACTIVITY_PRESETS[$inactive] ?? (ctype_digit((string) $inactive) ? (int) $inactive : null);
            if ($inactiveSeconds === null || $inactiveSeconds < 60 || $inactiveSeconds > min($maxSeconds, 31536000)) {
                $errors['inactive_after'] = 'Choose a valid inactivity period.';
                $inactiveSeconds = null;
            }
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        if ($burn) {
            $viewLimit = null;
        }

        return [
            'expires_at' => $expiresAt,
            'burn_after_view' => $burn,
            'view_limit' => $viewLimit,
            'view_limit_type' => $type,
            'download_limit' => $downloadLimit,
            'inactive_after_seconds' => $inactiveSeconds,
        ];
    }

    private static function optionalInt(mixed $value, int $min, int $max, string $field, string $message, array &$errors): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < $min || $int > $max) {
            $errors[$field] = $message;
            return null;
        }
        return $int;
    }

    /** Writes rules (source of truth) and the denormalised columns used for indexing. */
    public function persistRules(int $linkId, array $policy): void
    {
        $now = db_time();
        Database::execute('DELETE FROM expiration_rules WHERE share_link_id = ?', [$linkId]);
        $rules = [];
        if ($policy['expires_at'] !== null) {
            $rules[] = ['rule_type' => 'expires_at', 'threshold_datetime' => db_time($policy['expires_at'])];
        }
        if ($policy['burn_after_view']) {
            $rules[] = ['rule_type' => 'burn_after_view', 'threshold_integer' => 1];
        }
        if ($policy['view_limit'] !== null) {
            $rules[] = [
                'rule_type' => $policy['view_limit_type'] === 'total' ? 'max_total_views' : 'max_unique_views',
                'threshold_integer' => $policy['view_limit'],
            ];
        }
        if ($policy['download_limit'] !== null) {
            $rules[] = ['rule_type' => 'max_downloads', 'threshold_integer' => $policy['download_limit']];
        }
        if ($policy['inactive_after_seconds'] !== null) {
            $rules[] = ['rule_type' => 'inactivity', 'threshold_integer' => $policy['inactive_after_seconds']];
        }
        $downloadUntil = $policy['download_until'] ?? null;
        if ($downloadUntil !== null) {
            // Not an end condition: downloads stop, browser viewing continues.
            $rules[] = ['rule_type' => 'download_until', 'threshold_datetime' => db_time($downloadUntil)];
        }
        foreach ($rules as $priority => $rule) {
            Database::insert('expiration_rules', $rule + ['share_link_id' => $linkId, 'operator' => 'or', 'priority' => $priority, 'created_at' => $now]);
        }

        Database::update('share_links', [
            'expires_at' => $policy['expires_at'] !== null ? db_time($policy['expires_at']) : null,
            'burn_after_view' => $policy['burn_after_view'] ? 1 : 0,
            'view_limit' => $policy['view_limit'],
            'view_limit_type' => $policy['view_limit_type'],
            'download_limit' => $policy['download_limit'],
            'inactive_after_seconds' => $policy['inactive_after_seconds'],
            'download_until' => $downloadUntil !== null ? db_time($downloadUntil) : null,
            'updated_at' => $now,
        ], 'id = ?', [$linkId]);
    }

    /** Rebuilds a policy array from the stored rules. */
    public function policyFor(int $linkId): array
    {
        $policy = [
            'expires_at' => null, 'burn_after_view' => false, 'view_limit' => null,
            'view_limit_type' => 'unique', 'download_limit' => null, 'inactive_after_seconds' => null,
            'download_until' => null,
        ];
        foreach (Database::select('SELECT * FROM expiration_rules WHERE share_link_id = ? ORDER BY priority', [$linkId]) as $rule) {
            match ($rule['rule_type']) {
                'expires_at' => $policy['expires_at'] = new DateTimeImmutable($rule['threshold_datetime'], new DateTimeZone('UTC')),
                'burn_after_view' => $policy['burn_after_view'] = true,
                'max_unique_views' => [$policy['view_limit'], $policy['view_limit_type']] = [(int) $rule['threshold_integer'], 'unique'],
                'max_total_views' => [$policy['view_limit'], $policy['view_limit_type']] = [(int) $rule['threshold_integer'], 'total'],
                'max_downloads' => $policy['download_limit'] = (int) $rule['threshold_integer'],
                'inactivity' => $policy['inactive_after_seconds'] = (int) $rule['threshold_integer'],
                'download_until' => $policy['download_until'] = new DateTimeImmutable($rule['threshold_datetime'], new DateTimeZone('UTC')),
            };
        }
        return $policy;
    }

    /** "This image will be deleted after 10 unique views or at 6:00 PM on October 7, 2026 (UTC), whichever occurs first." */
    public static function summary(array $policy, string $contentType = 'image'): string
    {
        $noun = match ($contentType) {
            'text' => 'text', 'video' => 'recording', 'encrypted' => 'encrypted file', 'collection' => 'collection', default => 'image',
        };
        $conditions = [];
        if ($policy['burn_after_view']) {
            $conditions[] = 'after it is viewed once';
        } elseif ($policy['view_limit'] !== null) {
            $n = $policy['view_limit'];
            $conditions[] = $policy['view_limit_type'] === 'total'
                ? sprintf('after %d %s', $n, $n === 1 ? 'view' : 'views')
                : sprintf('after %d unique %s', $n, $n === 1 ? 'view' : 'views');
        }
        if ($policy['download_limit'] !== null) {
            $n = $policy['download_limit'];
            $conditions[] = sprintf('after %d %s', $n, $n === 1 ? 'download' : 'downloads');
        }
        if ($policy['inactive_after_seconds'] !== null) {
            $conditions[] = 'after ' . self::humanDuration($policy['inactive_after_seconds']) . ' without views';
        }
        if ($policy['expires_at'] instanceof DateTimeImmutable) {
            $conditions[] = 'at ' . $policy['expires_at']->format('g:i A \o\n F j, Y') . ' (UTC)';
        }
        $suffix = '';
        if (($policy['download_until'] ?? null) instanceof DateTimeImmutable) {
            $suffix = ' Downloads stop at ' . $policy['download_until']->format('g:i A \o\n F j, Y') . ' (UTC).';
        }

        if ($conditions === []) {
            return "This $noun will not expire automatically. You can delete it at any time." . $suffix;
        }
        if (count($conditions) === 1) {
            return "This $noun will be deleted {$conditions[0]}." . $suffix;
        }
        $last = array_pop($conditions);
        return "This $noun will be deleted " . implode(', ', $conditions) . " or $last, whichever occurs first." . $suffix;
    }

    public static function humanDuration(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $unit => $label) {
            if ($seconds >= $unit && $seconds % $unit === 0) {
                $n = intdiv($seconds, $unit);
                return $n . ' ' . $label . ($n === 1 ? '' : 's');
            }
        }
        return $seconds . ' seconds';
    }

    /** Reason a link should end without any access happening (time or inactivity), else null. */
    public static function passiveEndReason(array $link, ?DateTimeImmutable $at = null): ?string
    {
        $now = ($at ?? now())->getTimestamp();
        if ($link['expires_at'] !== null && strtotime($link['expires_at'] . ' UTC') <= $now) {
            return 'time';
        }
        if ($link['inactive_after_seconds'] !== null) {
            $last = strtotime(($link['last_accessed_at'] ?? $link['created_at']) . ' UTC');
            if ($last + (int) $link['inactive_after_seconds'] <= $now) {
                return 'inactivity';
            }
        }
        return null;
    }

    /**
     * Atomically records a view or download. Returns whether access is allowed and,
     * when this access exhausted the link, that it has been consumed.
     *
     * @return array{allowed:bool, reason:?string, ended:bool, unique:bool, link:array}
     */
    public function access(int $linkId, string $kind, Request $request): array
    {
        $viewer = AnalyticsService::viewerHash($request);

        return Database::transaction(function () use ($linkId, $kind, $request, $viewer) {
            $link = Database::first('SELECT * FROM share_links WHERE id = ? FOR UPDATE', [$linkId]);
            if ($link === null) {
                return ['allowed' => false, 'reason' => 'missing', 'ended' => false, 'unique' => false, 'link' => []];
            }
            $deny = function (string $reason) use ($link, $request, $viewer, $kind) {
                if ($kind === 'view') {
                    AnalyticsService::recordView((int) $link['id'], 'denied', $request, $viewer);
                }
                return ['allowed' => false, 'reason' => $reason, 'ended' => false, 'unique' => false, 'link' => $link];
            };

            $uploadStatus = Database::value('SELECT status FROM uploads WHERE id = ?', [$link['upload_id']]);
            if ($uploadStatus !== 'active') {
                return $deny('upload_' . $uploadStatus);
            }
            if ($link['status'] !== 'active') {
                return $deny($link['status']);
            }
            $now = now();
            if ($link['starts_at'] !== null && strtotime($link['starts_at'] . ' UTC') > $now->getTimestamp()) {
                return $deny('not_started');
            }
            $passive = self::passiveEndReason($link, $now);
            if ($passive !== null) {
                $this->endLink($link, 'expired', $passive);
                return $deny('expired');
            }

            $unique = false;
            $endReason = null;
            if ($kind === 'view') {
                $windowStart = db_time($now->modify('-' . (int) config('app.expiration.unique_view_window_seconds') . ' seconds'));
                $seen = Database::value(
                    "SELECT 1 FROM view_events WHERE share_link_id = ? AND viewer_hash = ? AND outcome = 'ok' AND created_at >= ? LIMIT 1",
                    [$link['id'], $viewer, $windowStart]
                );
                $unique = $seen === null;
                $link['view_count'] = (int) $link['view_count'] + 1;
                $link['unique_view_count'] = (int) $link['unique_view_count'] + ($unique ? 1 : 0);
                $counted = $link['view_limit_type'] === 'total' ? $link['view_count'] : $link['unique_view_count'];
                if ((int) $link['burn_after_view'] === 1) {
                    $endReason = 'burn_after_view';
                } elseif ($link['view_limit'] !== null && $counted >= (int) $link['view_limit']) {
                    $endReason = 'view_limit';
                }
                AnalyticsService::recordView((int) $link['id'], 'ok', $request, $viewer, $unique);
            } else {
                if ((int) $link['disable_download'] === 1) {
                    return $deny('download_disabled');
                }
                if ($link['download_until'] !== null && strtotime($link['download_until'] . ' UTC') <= $now->getTimestamp()) {
                    return $deny('download_window_closed');
                }
                $link['download_count'] = (int) $link['download_count'] + 1;
                if ($link['download_limit'] !== null && $link['download_count'] >= (int) $link['download_limit']) {
                    $endReason = 'download_limit';
                }
                AnalyticsService::recordDownload((int) $link['id'], $request, $viewer);
            }

            $link['last_accessed_at'] = db_time($now);
            Database::update('share_links', [
                'view_count' => $link['view_count'],
                'unique_view_count' => $link['unique_view_count'],
                'download_count' => $link['download_count'],
                'last_accessed_at' => $link['last_accessed_at'],
            ], 'id = ?', [$link['id']]);

            if ($endReason === null && $kind === 'view' && $link['recipient_email'] !== null && $this->allRecipientsViewed((int) $link['upload_id'])) {
                // "Delete after all invited recipients view it": end every link of the upload.
                foreach (Database::select("SELECT * FROM share_links WHERE upload_id = ? AND status IN ('active', 'paused') AND id <> ? FOR UPDATE", [$link['upload_id'], $link['id']]) as $other) {
                    $this->endLink($other, 'consumed', 'recipients_viewed');
                }
                $endReason = 'recipients_viewed';
            }

            if ($endReason !== null) {
                $this->endLink($link, 'consumed', $endReason, (int) config('app.expiration.consume_grace_seconds'));
                $link['status'] = 'consumed';
                $link['end_reason'] = $endReason;
                $link['ended_at'] = db_time($now);
            }

            return ['allowed' => true, 'reason' => null, 'ended' => $endReason !== null, 'unique' => $unique, 'link' => $link];
        });
    }

    /**
     * Ends a link and, if the upload has no other live links, marks it for physical deletion.
     * Must be called inside a transaction holding the link row lock (or from the sweeper).
     */
    public function endLink(array $link, string $status, string $reason, int $graceSeconds = 0): void
    {
        $now = db_time();
        Database::update('share_links', [
            'status' => $status,
            'end_reason' => $reason,
            'ended_at' => $now,
            'revoked_at' => $status === 'revoked' ? $now : $link['revoked_at'],
            'updated_at' => $now,
        ], 'id = ?', [$link['id']]);

        $live = (int) Database::value(
            "SELECT COUNT(*) FROM share_links WHERE upload_id = ? AND id <> ? AND status IN ('active', 'paused')",
            [$link['upload_id'], $link['id']]
        );
        if ($live === 0) {
            $uploadStatus = $status === 'revoked' ? 'revoked' : 'expired';
            Database::execute(
                "UPDATE uploads SET status = ?, updated_at = ? WHERE id = ? AND status = 'active'",
                [$uploadStatus, $now, $link['upload_id']]
            );
            (new DeletionService())->queue((int) $link['upload_id'], $status === 'revoked' ? $reason : $status . ':' . $reason, $graceSeconds);
        }
        AuditLogService::record('link.' . $status, 'share_link', $link['public_id'], ['reason' => $reason], isset($_SERVER['REMOTE_ADDR']) ? null : 'system');
        $upload = Database::first('SELECT * FROM uploads WHERE id = ?', [$link['upload_id']]);
        if ($upload !== null) {
            EventService::dispatch('link.' . $status, $upload, ['link_id' => $link['public_id'], 'reason' => $reason]);
        }
    }

    private function allRecipientsViewed(int $uploadId): bool
    {
        if ((int) Database::value('SELECT expire_when_recipients_viewed FROM uploads WHERE id = ?', [$uploadId]) !== 1) {
            return false;
        }
        return (int) Database::value(
            'SELECT COUNT(*) FROM share_links WHERE upload_id = ? AND recipient_email IS NOT NULL AND view_count = 0',
            [$uploadId]
        ) === 0;
    }

    /** Ends links whose time or inactivity rule has passed. Run by the scheduler every minute. */
    public function sweep(int $limit = 500): int
    {
        $now = db_time();
        $ids = Database::select(
            "SELECT id FROM share_links
             WHERE (status IN ('active', 'paused') AND expires_at IS NOT NULL AND expires_at <= ?)
                OR (status = 'active' AND inactive_after_seconds IS NOT NULL
                    AND COALESCE(last_accessed_at, created_at) + INTERVAL inactive_after_seconds SECOND <= ?)
             ORDER BY id LIMIT $limit",
            [$now, $now]
        );
        $ended = 0;
        foreach ($ids as $row) {
            Database::transaction(function () use ($row, &$ended) {
                $link = Database::first('SELECT * FROM share_links WHERE id = ? FOR UPDATE', [$row['id']]);
                if ($link === null || !in_array($link['status'], ['active', 'paused'], true)) {
                    return;
                }
                $reason = self::passiveEndReason($link);
                if ($reason !== null) {
                    $this->endLink($link, 'expired', $reason);
                    $ended++;
                }
            });
        }
        return $ended;
    }

    /** Owner-facing remaining allowance, e.g. ['views' => 7, 'downloads' => null]. */
    public static function remaining(array $link): array
    {
        $views = null;
        if ((int) $link['burn_after_view'] === 1) {
            $views = $link['status'] === 'active' ? 1 : 0;
        } elseif ($link['view_limit'] !== null) {
            $used = $link['view_limit_type'] === 'total' ? $link['view_count'] : $link['unique_view_count'];
            $views = max(0, (int) $link['view_limit'] - (int) $used);
        }
        $downloads = $link['download_limit'] !== null ? max(0, (int) $link['download_limit'] - (int) $link['download_count']) : null;
        return ['views' => $views, 'downloads' => $downloads];
    }
}
