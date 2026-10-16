<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;

/** Plan entitlements: quotas and feature gates resolved for a user (via their workspace or own plan). */
final class PlanService
{
    private static array $cache = [];

    public static function all(): array
    {
        return Database::select('SELECT * FROM plans ORDER BY sort_order');
    }

    public static function byCode(string $code): ?array
    {
        return Database::first('SELECT * FROM plans WHERE code = ?', [$code]);
    }

    /** The effective plan: the best of the user's own plan and their current workspace's plan. */
    public static function forUser(?array $user): array
    {
        if ($user === null) {
            return self::byCode('free');
        }
        $key = (int) $user['id'];
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $candidates = Database::select(
            'SELECT p.* FROM plans p WHERE p.id = ?
             UNION SELECT p.* FROM plans p JOIN workspaces w ON w.plan_id = p.id
                   JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? AND w.id = ?',
            [$user['plan_id'] ?? 0, $user['id'], $user['current_workspace_id'] ?? 0]
        );
        usort($candidates, static fn ($a, $b) => $b['sort_order'] <=> $a['sort_order']);
        $plan = $candidates[0] ?? self::byCode('free');
        $plan['features'] = is_array($plan['features']) ? $plan['features'] : (json_decode($plan['features'], true) ?: []);
        return self::$cache[$key] = $plan;
    }

    public static function has(?array $user, string $feature): bool
    {
        $plan = self::forUser($user);
        $features = is_array($plan['features']) ? $plan['features'] : (json_decode($plan['features'], true) ?: []);
        return in_array($feature, $features, true);
    }

    public static function require(?array $user, string $feature, string $label): void
    {
        if (!self::has($user, $feature)) {
            throw new HttpException(402, 'plan_required', "$label is not included in your plan. Upgrade to use it.");
        }
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Applies a plan change and the quota that comes with it. */
    public static function assignToUser(int $userId, string $code): void
    {
        $plan = self::byCode($code) ?? throw HttpException::notFound('Unknown plan');
        Database::update('users', ['plan_id' => $plan['id'], 'storage_quota_bytes' => $plan['storage_bytes'], 'updated_at' => db_time()], 'id = ?', [$userId]);
        self::flush();
    }
}
