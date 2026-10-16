<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class UserRepository
{
    public function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return Db::one('SELECT * FROM users WHERE uuid = ?', [$uuid]);
    }

    public function create(string $name, string $email, ?string $passwordHash, bool $verified = false, bool $admin = false): array
    {
        $now = utcnow();
        $id = Db::insert('users', [
            'uuid' => uuid4(),
            'email' => mb_strtolower($email),
            'password_hash' => $passwordHash,
            'name' => $name,
            'email_verified_at' => $verified ? $now : null,
            'is_platform_admin' => $admin ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::insert('user_profiles', ['user_id' => $id]);
        return $this->find($id);
    }

    public function update(int $id, array $data): void
    {
        Db::update('users', $data + ['updated_at' => utcnow()], ['id' => $id]);
    }

    public function recentLogins(int $userId, int $limit = 10): array
    {
        return Db::all('SELECT ip, user_agent, success, created_at FROM login_history WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$userId]);
    }
}
