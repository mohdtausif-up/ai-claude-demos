<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Auth;
use App\Support\Database;

/** Append-only security and administration events (enforced by DB triggers). */
final class AuditLogService
{
    public static function record(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?string $actorType = null,
        ?int $actorUserId = null,
    ): void {
        $user = Auth::user();
        $inRequest = isset($_SERVER['REMOTE_ADDR']);
        $actorUserId ??= $user['id'] ?? null;
        $actorType ??= match (true) {
            !$inRequest && $user === null => 'system',
            ($user['role'] ?? null) === 'admin' && str_starts_with($action, 'admin.') => 'admin',
            $user !== null => 'user',
            default => 'guest',
        };

        // Scope events to a workspace so workspace admins can export their own audit trail.
        $workspaceId = $metadata['workspace_id'] ?? $user['current_workspace_id'] ?? null;

        Database::insert('audit_logs', [
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'workspace_id' => $workspaceId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_hash' => $inRequest ? ip_hash() : null,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'created_at' => db_time(),
        ]);
    }
}
