<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ForbiddenException;

/**
 * Plans (PRD §16) and what each allows. Payments for plans are out of scope here: a platform admin assigns plans.
 * With no plan stored a workspace gets DEFAULT_PLAN (the dev stack sets "agency" so everything is enabled).
 */
final class PlanService
{
    public const PLANS = ['free' => 'Free', 'plus' => 'Plus', 'pro' => 'Pro', 'agency' => 'Planner / Agency'];
    private const ORDER = ['free' => 0, 'plus' => 1, 'pro' => 2, 'agency' => 3];
    /** Guests per event. null = unlimited. */
    private const GUEST_LIMIT = ['free' => 50, 'plus' => 250, 'pro' => null, 'agency' => null];
    /** feature => minimum plan */
    public const FEATURES = [
        'remove_branding' => 'plus', 'automations' => 'plus', 'album' => 'plus', 'checkin' => 'plus', 'polls' => 'plus',
        'subevents' => 'pro', 'seating' => 'pro', 'custom_domain' => 'pro', 'scheduled_reports' => 'pro', 'payments' => 'pro', 'sms' => 'pro',
        'api' => 'agency', 'webhooks' => 'agency', 'approval' => 'agency', 'brand_kit' => 'agency', 'custom_css' => 'agency', 'workspaces' => 'agency',
    ];

    public function planOf(array|int $workspace): string
    {
        $ws = is_array($workspace) ? $workspace : Db::one('SELECT * FROM workspaces WHERE id = ?', [$workspace]);
        $p = $ws['plan'] ?? null;
        if ($p === null || !isset(self::ORDER[$p])) {
            $p = (string) env('DEFAULT_PLAN', 'free');
        }
        return isset(self::ORDER[$p]) ? $p : 'free';
    }

    public function allows(array|int $workspace, string $feature): bool
    {
        $min = self::FEATURES[$feature] ?? 'agency';
        return self::ORDER[$this->planOf($workspace)] >= self::ORDER[$min];
    }

    /** @throws ForbiddenException with an upgrade hint */
    public function require(array|int $workspace, string $feature): void
    {
        if (!$this->allows($workspace, $feature)) {
            $min = self::FEATURES[$feature] ?? 'agency';
            throw new ForbiddenException('This feature needs the ' . self::PLANS[$min] . ' plan or higher. Ask the workspace owner or an administrator to upgrade.');
        }
    }

    public function guestLimit(array|int $workspace): ?int
    {
        return self::GUEST_LIMIT[$this->planOf($workspace)];
    }

    /** @throws ForbiddenException when adding $adding guests would exceed the plan's per-event limit */
    public function assertCanAddGuests(array $event, int $adding = 1): void
    {
        $limit = $this->guestLimit((int) $event['workspace_id']);
        if ($limit === null) {
            return;
        }
        $have = (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]);
        if ($have + $adding > $limit) {
            throw new ForbiddenException("This event is at its plan limit of $limit guests. Upgrade the workspace plan to invite more.");
        }
    }

    public function setPlan(int $workspaceId, ?string $plan): void
    {
        Db::update('workspaces', ['plan' => $plan !== null && isset(self::ORDER[$plan]) ? $plan : null], ['id' => $workspaceId]);
    }

    public function track(int $workspaceId, string $metric, int $qty = 1): void
    {
        Db::run('INSERT INTO usage_records (workspace_id, metric, period, qty) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$workspaceId, $metric, gmdate('Y-m'), $qty]);
    }

    /** @return array<string,int> metric => qty for the month */
    public function usage(int $workspaceId, ?string $period = null): array
    {
        $rows = Db::all('SELECT metric, qty FROM usage_records WHERE workspace_id = ? AND period = ?', [$workspaceId, $period ?? gmdate('Y-m')]);
        $out = array_column($rows, 'qty', 'metric');
        $out['events'] = (int) Db::value('SELECT COUNT(*) FROM events WHERE workspace_id = ? AND deleted_at IS NULL', [$workspaceId]);
        $out['guests'] = (int) Db::value('SELECT COUNT(*) FROM event_guests eg JOIN events e ON e.id = eg.event_id WHERE e.workspace_id = ? AND e.deleted_at IS NULL', [$workspaceId]);
        $out['storage_mb'] = (int) round((int) Db::value('SELECT COALESCE(SUM(bytes), 0) FROM media_assets WHERE workspace_id = ?', [$workspaceId]) / 1048576);
        return array_map('intval', $out);
    }
}