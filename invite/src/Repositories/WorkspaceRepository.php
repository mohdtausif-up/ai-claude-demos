<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class WorkspaceRepository
{
    public function create(int $ownerId, string $ownerEmail, string $name): array
    {
        $now = utcnow();
        $id = Db::insert('workspaces', ['uuid' => uuid4(), 'name' => $name, 'owner_id' => $ownerId, 'created_at' => $now, 'updated_at' => $now]);
        Db::insert('workspace_members', ['workspace_id' => $id, 'user_id' => $ownerId, 'email' => mb_strtolower($ownerEmail), 'role' => 'owner', 'created_at' => $now]);
        return $this->find($id);
    }

    public function find(int $id): ?array
    {
        return Db::one('SELECT * FROM workspaces WHERE id = ?', [$id]);
    }

    /** Workspaces the user belongs to, with their role. */
    public function forUser(int $userId): array
    {
        return Db::all(
            'SELECT w.*, m.role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? ORDER BY (m.role = \'owner\') DESC, w.id',
            [$userId]
        );
    }

    public function defaultFor(int $userId): ?array
    {
        return $this->forUser($userId)[0] ?? null;
    }

    public function members(int $workspaceId): array
    {
        return Db::all(
            'SELECT m.id, m.email, m.role, m.user_id, m.created_at, u.name FROM workspace_members m LEFT JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? ORDER BY m.id',
            [$workspaceId]
        );
    }

    public function addMember(int $workspaceId, string $email, string $role): void
    {
        $email = mb_strtolower($email);
        $userId = Db::value('SELECT id FROM users WHERE email = ?', [$email]);
        Db::run(
            'INSERT INTO workspace_members (workspace_id, user_id, email, role, created_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)',
            [$workspaceId, $userId, $email, $role, utcnow()]
        );
    }

    public function removeMember(int $workspaceId, int $memberId): void
    {
        Db::exec("DELETE FROM workspace_members WHERE id = ? AND workspace_id = ? AND role <> 'owner'", [$memberId, $workspaceId]);
    }

    /** Attach pending collaborator invitations to a newly registered user. */
    public function linkPending(int $userId, string $email): void
    {
        Db::exec('UPDATE workspace_members SET user_id = ? WHERE email = ? AND user_id IS NULL', [$userId, mb_strtolower($email)]);
    }
}
