<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;

/**
 * Central authorization. Roles are per workspace; every ability check goes through here.
 * Non-members get a 404 (never reveal that an event exists); members lacking the ability get a 403.
 */
final class Gate
{
    public const ROLES = ['owner', 'admin', 'editor', 'guest_manager', 'checkin_staff', 'viewer', 'client_approver'];

    /** @var array<string,list<string>> */
    private const MATRIX = [
        'owner' => ['*'],
        'admin' => ['*'],
        'editor' => ['approval.request', 'event.view', 'event.create', 'event.edit', 'event.publish', 'event.clone', 'invitation.edit', 'invitation.view', 'guests.view', 'reports.view', 'comments.add'],
        'guest_manager' => ['event.view', 'guests.view', 'guests.manage', 'guests.export', 'rsvp.modify', 'messages.send', 'reports.view', 'checkin.view'],
        'checkin_staff' => ['event.view', 'checkin.perform', 'checkin.view'],
        'viewer' => ['event.view', 'invitation.view', 'guests.view', 'reports.view', 'checkin.view'],
        'client_approver' => ['event.view', 'invitation.view', 'comments.add', 'approval.decide'],
    ];

    /** @var array<string,string|null> */
    private static array $roleCache = [];

    public static function roleIn(int $userId, int $workspaceId): ?string
    {
        $k = "$userId:$workspaceId";
        if (!array_key_exists($k, self::$roleCache)) {
            self::$roleCache[$k] = Db::value('SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$workspaceId, $userId]) ?: null;
        }
        return self::$roleCache[$k];
    }

    public static function forget(): void
    {
        self::$roleCache = [];
    }

    public static function roleAllows(?string $role, string $ability): bool
    {
        if ($role === null) {
            return false;
        }
        $allowed = self::MATRIX[$role] ?? [];
        return in_array('*', $allowed, true) || in_array($ability, $allowed, true);
    }

    public function can(array $user, string $ability, int $workspaceId): bool
    {
        return self::roleAllows(self::roleIn((int) $user['id'], $workspaceId), $ability);
    }

    /** @throws NotFoundException|ForbiddenException */
    public function authorize(array $user, string $ability, int $workspaceId): void
    {
        $role = self::roleIn((int) $user['id'], $workspaceId);
        if ($role === null) {
            throw new NotFoundException();
        }
        if (!self::roleAllows($role, $ability)) {
            throw new ForbiddenException();
        }
    }

    /** Abilities a role holds, for UI gating (hide buttons the user cannot use). */
    public function abilitiesFor(array $user, int $workspaceId): array
    {
        $role = self::roleIn((int) $user['id'], $workspaceId);
        $all = ['event.view', 'event.create', 'event.edit', 'event.publish', 'event.delete', 'event.clone', 'invitation.edit', 'invitation.view', 'guests.view', 'guests.manage', 'guests.export', 'rsvp.modify', 'messages.send', 'checkin.perform', 'checkin.view', 'reports.view', 'collaborators.manage', 'billing.manage', 'comments.add', 'approval.request', 'approval.decide', 'audit.view'];
        $out = [];
        foreach ($all as $a) {
            $out[$a] = self::roleAllows($role, $a);
        }
        return $out;
    }
}
