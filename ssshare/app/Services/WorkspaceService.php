<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;

/**
 * Team workspaces with role-based access control.
 *   owner  – everything, including deleting the workspace and changing the owner
 *   admin  – members, invitations, settings, branding, domains, SSO, audit export
 *   member – create shares in the workspace and manage their own
 *   viewer – open workspace-only shares and comment
 */
final class WorkspaceService
{
    public const ROLES = ['owner' => 4, 'admin' => 3, 'member' => 2, 'viewer' => 1];
    private static array $current = [];

    public static function current(?array $user): ?array
    {
        if ($user === null || empty($user['current_workspace_id'])) {
            return null;
        }
        $key = $user['id'] . ':' . $user['current_workspace_id'];
        if (!array_key_exists($key, self::$current)) {
            self::$current[$key] = Database::first(
                'SELECT w.*, m.role AS member_role FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id
                 WHERE w.id = ? AND m.user_id = ?',
                [$user['current_workspace_id'], $user['id']]
            );
        }
        return self::$current[$key];
    }

    public static function flush(): void
    {
        self::$current = [];
        PlanService::flush();
    }

    public static function role(int $workspaceId, int $userId): ?string
    {
        $role = Database::value('SELECT role FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$workspaceId, $userId]);
        return $role === null ? null : (string) $role;
    }

    public static function can(?string $role, string $minimum): bool
    {
        return $role !== null && self::ROLES[$role] >= self::ROLES[$minimum];
    }

    public static function requireRole(array $workspace, array $user, string $minimum): string
    {
        $role = self::role((int) $workspace['id'], (int) $user['id']);
        if (!self::can($role, $minimum)) {
            throw HttpException::forbidden('You need the ' . $minimum . ' role in this workspace to do that.');
        }
        return $role;
    }

    public static function forUser(array $user): array
    {
        return Database::select(
            'SELECT w.*, m.role AS member_role, (SELECT COUNT(*) FROM workspace_members x WHERE x.workspace_id = w.id) AS member_count
             FROM workspaces w JOIN workspace_members m ON m.workspace_id = w.id WHERE m.user_id = ? ORDER BY w.name',
            [$user['id']]
        );
    }

    public static function byPublicId(string $publicId): ?array
    {
        return Database::first('SELECT * FROM workspaces WHERE public_id = ?', [$publicId]);
    }

    public function create(array $user, string $name): array
    {
        PlanService::require($user, 'workspaces', 'Team workspaces');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw HttpException::validation(['name' => 'Workspace names can be 1 to 80 characters.']);
        }
        $now = db_time();
        $plan = PlanService::forUser($user);
        $id = Database::transaction(function () use ($user, $name, $now, $plan) {
            $id = Database::insert('workspaces', [
                'public_id' => Ids::publicId(12), 'name' => $name, 'owner_user_id' => $user['id'], 'plan_id' => $plan['id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            Database::insert('workspace_members', ['workspace_id' => $id, 'user_id' => $user['id'], 'role' => 'owner', 'created_at' => $now]);
            Database::update('users', ['current_workspace_id' => $id], 'id = ?', [$user['id']]);
            return $id;
        });
        $workspace = Database::first('SELECT * FROM workspaces WHERE id = ?', [$id]);
        self::audit($workspace, 'workspace.created', ['name' => $name]);
        self::flush();
        return $workspace;
    }

    public function switchTo(array $user, ?array $workspace): void
    {
        if ($workspace !== null && self::role((int) $workspace['id'], (int) $user['id']) === null) {
            throw HttpException::forbidden('You are not a member of that workspace.');
        }
        Database::update('users', ['current_workspace_id' => $workspace['id'] ?? null], 'id = ?', [$user['id']]);
        self::flush();
    }

    /** @return array{token:string, row:array} */
    public function invite(array $workspace, array $inviter, string $email, string $role): array
    {
        self::requireRole($workspace, $inviter, 'admin');
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['email' => 'Enter a valid email address.']);
        }
        if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
            throw HttpException::validation(['role' => 'Choose admin, member or viewer.']);
        }
        if (Database::value('SELECT 1 FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.email = ?', [$workspace['id'], $email]) !== null) {
            throw HttpException::validation(['email' => 'That person is already a member.']);
        }
        $token = Ids::token(24);
        $id = Database::insert('workspace_invitations', [
            'public_id' => Ids::publicId(12), 'workspace_id' => $workspace['id'], 'email' => $email, 'role' => $role,
            'token_hash' => Ids::hashToken($token), 'invited_by' => $inviter['id'],
            'expires_at' => db_time(now()->modify('+7 days')), 'created_at' => db_time(),
        ]);
        MailService::queue($email, 'workspace_invitation', [
            'inviter' => $inviter['display_name'], 'workspace' => $workspace['name'], 'role' => $role,
            'url' => url('workspace/invite/' . $token), 'expires' => now()->modify('+7 days')->format('F j, Y'),
        ]);
        self::audit($workspace, 'workspace.member_invited', ['email_hash' => hash('sha256', $email), 'role' => $role]);
        return ['token' => $token, 'row' => Database::first('SELECT * FROM workspace_invitations WHERE id = ?', [$id])];
    }

    public static function invitation(string $token): ?array
    {
        return Database::first(
            'SELECT i.*, w.name AS workspace_name FROM workspace_invitations i JOIN workspaces w ON w.id = i.workspace_id
             WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?',
            [Ids::hashToken($token), db_time()]
        );
    }

    public function accept(string $token, array $user): array
    {
        $invite = self::invitation($token) ?? throw HttpException::notFound('This invitation is invalid or has expired.');
        if (strtolower($user['email']) !== $invite['email']) {
            throw HttpException::forbidden('This invitation was sent to a different email address. Sign in with ' . $invite['email'] . '.');
        }
        Database::transaction(function () use ($invite, $user) {
            Database::execute(
                'INSERT IGNORE INTO workspace_members (workspace_id, user_id, role, created_at) VALUES (?, ?, ?, ?)',
                [$invite['workspace_id'], $user['id'], $invite['role'], db_time()]
            );
            Database::update('workspace_invitations', ['accepted_at' => db_time()], 'id = ?', [$invite['id']]);
            Database::update('users', ['current_workspace_id' => $invite['workspace_id']], 'id = ?', [$user['id']]);
        });
        $workspace = Database::first('SELECT * FROM workspaces WHERE id = ?', [$invite['workspace_id']]);
        self::audit($workspace, 'workspace.member_joined', ['user' => $user['uuid'], 'role' => $invite['role']]);
        self::flush();
        return $workspace;
    }

    public function setRole(array $workspace, array $actor, int $userId, string $role): void
    {
        $actorRole = self::requireRole($workspace, $actor, 'admin');
        $targetRole = self::role((int) $workspace['id'], $userId) ?? throw HttpException::notFound('Member not found.');
        if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
            throw HttpException::validation(['role' => 'Choose admin, member or viewer.']);
        }
        if ($targetRole === 'owner') {
            throw new HttpException(409, 'invalid_state', 'The owner role cannot be changed here.');
        }
        if ($targetRole === 'admin' && $actorRole !== 'owner') {
            throw HttpException::forbidden('Only the owner can change another admin.');
        }
        Database::update('workspace_members', ['role' => $role], 'workspace_id = ? AND user_id = ?', [$workspace['id'], $userId]);
        self::audit($workspace, 'workspace.role_changed', ['user_id' => $userId, 'from' => $targetRole, 'to' => $role]);
    }

    public function removeMember(array $workspace, array $actor, int $userId): void
    {
        $self = (int) $actor['id'] === $userId;
        $actorRole = $self ? self::role((int) $workspace['id'], $userId) : self::requireRole($workspace, $actor, 'admin');
        $targetRole = self::role((int) $workspace['id'], $userId) ?? throw HttpException::notFound('Member not found.');
        if ($targetRole === 'owner') {
            throw new HttpException(409, 'invalid_state', 'The owner cannot leave or be removed.');
        }
        if ($targetRole === 'admin' && !$self && $actorRole !== 'owner') {
            throw HttpException::forbidden('Only the owner can remove an admin.');
        }
        Database::transaction(function () use ($workspace, $userId) {
            Database::execute('DELETE FROM workspace_members WHERE workspace_id = ? AND user_id = ?', [$workspace['id'], $userId]);
            Database::execute('UPDATE users SET current_workspace_id = NULL WHERE id = ? AND current_workspace_id = ?', [$userId, $workspace['id']]);
        });
        self::audit($workspace, $self ? 'workspace.member_left' : 'workspace.member_removed', ['user_id' => $userId]);
        self::flush();
    }

    public function updateSettings(array $workspace, array $actor, array $input): array
    {
        self::requireRole($workspace, $actor, 'admin');
        $changes = [];
        $errors = [];
        if (isset($input['name'])) {
            $name = trim((string) $input['name']);
            $name === '' || mb_strlen($name) > 80 ? $errors['name'] = 'Names can be 1 to 80 characters.' : $changes['name'] = $name;
        }
        if (array_key_exists('brand_name', $input)) {
            PlanService::require($actor, 'branding', 'Custom branding');
            $changes['brand_name'] = trim((string) $input['brand_name']) ?: null;
        }
        if (array_key_exists('brand_color', $input)) {
            PlanService::require($actor, 'branding', 'Custom branding');
            $color = trim((string) $input['brand_color']);
            if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $errors['brand_color'] = 'Use a hex colour like #625bf6.';
            } else {
                $changes['brand_color'] = $color !== '' ? strtolower($color) : null;
            }
        }
        if (array_key_exists('retention_max', $input)) {
            PlanService::require($actor, 'retention_policies', 'Retention policies');
            $value = (string) $input['retention_max'];
            $seconds = $value === '' ? null : (ExpirationService::PRESETS[$value] ?? null);
            if ($value !== '' && $seconds === null) {
                $errors['retention_max'] = 'Choose a maximum retention period.';
            } else {
                $changes['retention_max_seconds'] = $seconds;
            }
        }
        if (array_key_exists('retention_default', $input)) {
            $value = (string) $input['retention_default'];
            if ($value !== '' && (!array_key_exists($value, ExpirationService::PRESETS) || $value === 'never')) {
                $errors['retention_default'] = 'Choose a default expiry.';
            } else {
                $changes['retention_default'] = $value !== '' ? $value : null;
            }
        }
        if (isset($input['storage_region'])) {
            PlanService::require($actor, 'regional_storage', 'Regional storage');
            $region = (string) $input['storage_region'];
            if (!array_key_exists($region, StorageService::regions())) {
                $errors['storage_region'] = 'That storage region is not available.';
            } else {
                $changes['storage_region'] = $region;
            }
        }
        foreach (['allow_anonymous_comments', 'ai_enabled', 'require_sso'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $changes[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (($changes['require_sso'] ?? 0) === 1 && empty($workspace['sso_config'])) {
            $errors['require_sso'] = 'Configure single sign-on before requiring it.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($changes !== []) {
            Database::update('workspaces', $changes + ['updated_at' => db_time()], 'id = ?', [$workspace['id']]);
            self::audit($workspace, 'workspace.settings_changed', ['fields' => array_keys($changes)]);
        }
        self::flush();
        return Database::first('SELECT * FROM workspaces WHERE id = ?', [$workspace['id']]);
    }

    public function setLogo(array $workspace, array $actor, string $bytes): void
    {
        self::requireRole($workspace, $actor, 'admin');
        PlanService::require($actor, 'branding', 'Custom branding');
        $processed = (new ImageProcessingService())->process($bytes, 2 * 1024 * 1024);
        $key = 'ws/' . $workspace['public_id'] . '/logo-' . bin2hex(random_bytes(4)) . '.' . $processed['ext'];
        StorageService::driver()->put($key, $processed['bytes']);
        if ($workspace['brand_logo_key']) {
            StorageService::driver()->delete($workspace['brand_logo_key']);
        }
        Database::update('workspaces', ['brand_logo_key' => $key, 'updated_at' => db_time()], 'id = ?', [$workspace['id']]);
        self::audit($workspace, 'workspace.logo_changed');
    }

    /** Starts custom-domain setup: the owner must publish a TXT record to prove control. */
    public function setDomain(array $workspace, array $actor, ?string $domain): array
    {
        self::requireRole($workspace, $actor, 'admin');
        PlanService::require($actor, 'custom_domains', 'Custom domains');
        $domain = $domain !== null ? strtolower(trim($domain)) : '';
        if ($domain === '') {
            Database::update('workspaces', ['custom_domain' => null, 'domain_token' => null, 'domain_verified_at' => null], 'id = ?', [$workspace['id']]);
            self::audit($workspace, 'workspace.domain_removed');
            return Database::first('SELECT * FROM workspaces WHERE id = ?', [$workspace['id']]);
        }
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        if (!preg_match('/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $domain) || $domain === $appHost) {
            throw HttpException::validation(['domain' => 'Enter a domain such as share.example.com.']);
        }
        if (Database::value('SELECT 1 FROM workspaces WHERE custom_domain = ? AND id <> ?', [$domain, $workspace['id']]) !== null) {
            throw HttpException::validation(['domain' => 'That domain is already connected to another workspace.']);
        }
        Database::update('workspaces', [
            'custom_domain' => $domain, 'domain_token' => 'ssshare-verify=' . Ids::token(16), 'domain_verified_at' => null, 'updated_at' => db_time(),
        ], 'id = ?', [$workspace['id']]);
        self::audit($workspace, 'workspace.domain_set', ['domain' => $domain]);
        return Database::first('SELECT * FROM workspaces WHERE id = ?', [$workspace['id']]);
    }

    /** Checks the TXT record at _ssshare.{domain}. $resolver is injectable for tests. */
    public function verifyDomain(array $workspace, ?callable $resolver = null): bool
    {
        if (!$workspace['custom_domain'] || !$workspace['domain_token']) {
            return false;
        }
        $resolver ??= static fn (string $host) => array_column(@dns_get_record($host, DNS_TXT) ?: [], 'txt');
        $records = $resolver('_ssshare.' . $workspace['custom_domain']);
        if (!in_array($workspace['domain_token'], $records, true)) {
            return false;
        }
        Database::update('workspaces', ['domain_verified_at' => db_time()], 'id = ?', [$workspace['id']]);
        self::audit($workspace, 'workspace.domain_verified', ['domain' => $workspace['custom_domain']]);
        return true;
    }

    public static function byVerifiedDomain(string $host): ?array
    {
        return Database::first('SELECT * FROM workspaces WHERE custom_domain = ? AND domain_verified_at IS NOT NULL', [strtolower($host)]);
    }

    public static function members(array $workspace): array
    {
        return Database::select(
            'SELECT u.id, u.uuid, u.email, u.display_name, m.role, m.created_at FROM workspace_members m JOIN users u ON u.id = m.user_id
             WHERE m.workspace_id = ? ORDER BY m.role, u.display_name',
            [$workspace['id']]
        );
    }

    public static function audit(array $workspace, string $action, array $meta = []): void
    {
        AuditLogService::record($action, 'workspace', $workspace['public_id'], $meta + ['workspace_id' => (int) $workspace['id']]);
    }
}
