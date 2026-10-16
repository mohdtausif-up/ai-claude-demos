<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Append-only trail for sensitive actions. Never put secrets or tokens in $meta. */
final class AuditLogger
{
    /**
     * @param array{workspace_id?:int|null,event_id?:int|null,actor_user_id?:int|null,actor_type?:string,entity_type?:string,entity_uuid?:string,meta?:array} $ctx
     */
    public function log(string $action, array $ctx = []): void
    {
        Db::insert('audit_logs', [
            'workspace_id' => $ctx['workspace_id'] ?? null,
            'event_id' => $ctx['event_id'] ?? null,
            'actor_user_id' => $ctx['actor_user_id'] ?? null,
            'actor_type' => $ctx['actor_type'] ?? 'user',
            'action' => $action,
            'entity_type' => $ctx['entity_type'] ?? null,
            'entity_uuid' => $ctx['entity_uuid'] ?? null,
            'meta' => isset($ctx['meta']) ? json_out($ctx['meta']) : null,
            'ip' => PHP_SAPI === 'cli' ? null : client_ip(),
            'user_agent' => PHP_SAPI === 'cli' ? null : mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => utcnow(),
        ]);
    }

    /** Convenience for the common "user did X to event Y" case. */
    public function event(array $user, array $event, string $action, array $meta = []): void
    {
        $this->log($action, [
            'workspace_id' => (int) $event['workspace_id'],
            'event_id' => (int) $event['id'],
            'actor_user_id' => (int) $user['id'],
            'entity_type' => 'event',
            'entity_uuid' => $event['uuid'],
            'meta' => $meta ?: null,
        ]);
    }
}
