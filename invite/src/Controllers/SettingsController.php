<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Services\ApiTokenService;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Gate;
use App\Services\RateLimiter;
use App\Services\SessionTracker;

/** Account settings: profile, password, active sessions, login history and API tokens. */
final class SettingsController extends Controller
{
    public function __construct(
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
        private SessionTracker $sessions,
        private ApiTokenService $tokens,
        private AuditLogger $audit,
        private Gate $gate,
        private RateLimiter $limiter,
        private \App\Services\TotpService $totp
    ) {
    }

    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $uid = (int) $user['id'];
        $workspaces = array_values(array_filter($this->workspaces->forUser($uid), fn ($w) => $this->gate->can($user, 'event.view', (int) $w['id'])));
        return $this->view('settings/index', [
            'sessions' => $this->sessions->list($uid), 'logins' => $this->users->recentLogins($uid, 10),
            'tokens' => $this->tokens->list($uid), 'workspaces' => $workspaces,
            'newToken' => Session::peek('new_token'), 'mfaOn' => $this->totp->isEnabled($uid), 'mfaRecovery' => $this->totp->recoveryLeft($uid), 'hasPassword' => !empty($user['password_hash']),
        ]);
    }

    public function profile(Request $req): Response
    {
        $user = $this->user($req);
        $d = Validator::validate($req->all(), ['name' => 'required|string|min:2|max:100']);
        $this->users->update((int) $user['id'], ['name' => $d['name']]);
        return $this->redirect('/app/settings', 'Profile updated.');
    }

    public function password(Request $req): Response
    {
        $user = $this->user($req);
        $this->limiter->enforce('pw:' . $user['id'], 8, 900);
        $d = Validator::validate($req->all(), ['current_password' => 'nullable|string|max:200', 'new_password' => 'required|string|min:10|max:200']);
        if (!empty($user['password_hash']) && !password_verify((string) ($d['current_password'] ?? ''), $user['password_hash'])) {
            throw new ValidationException(['current_password' => ['Your current password is not correct.']]);
        }
        $this->users->update((int) $user['id'], ['password_hash' => AuthService::hashPassword($d['new_password'])]);
        $n = $this->sessions->revokeOthers((int) $user['id']);
        $this->audit->log('user.password_changed', ['actor_user_id' => (int) $user['id'], 'entity_type' => 'user', 'entity_uuid' => $user['uuid']]);
        return $this->redirect('/app/settings', 'Password changed' . ($n ? " and $n other device(s) signed out." : '.'));
    }

    public function signOutOthers(Request $req): Response
    {
        $user = $this->user($req);
        $n = $this->sessions->revokeOthers((int) $user['id']);
        $this->audit->log('user.sessions_revoked', ['actor_user_id' => (int) $user['id'], 'meta' => ['count' => $n]]);
        return $this->redirect('/app/settings', "Signed out of $n other device" . ($n === 1 ? '' : 's') . '.');
    }

    public function createToken(Request $req): Response
    {
        $user = $this->user($req);
        $d = Validator::validate($req->all(), ['name' => 'required|string|min:2|max:100', 'workspace' => 'required|uuid', 'days' => 'nullable|int|min:1|max:365']);
        $ws = null;
        foreach ($this->workspaces->forUser((int) $user['id']) as $w) {
            if ($w['uuid'] === $d['workspace']) {
                $ws = $w;
            }
        }
        if (!$ws || !in_array($ws['role'], ['owner', 'admin'], true)) {
            throw new ValidationException(['workspace' => ['Only workspace owners and admins can create API tokens.']]);
        }
        $res = $this->tokens->create((int) $user['id'], (int) $ws['id'], $d['name'], $d['days'] ?: null);
        $this->audit->log('api_token.created', ['workspace_id' => (int) $ws['id'], 'actor_user_id' => (int) $user['id'], 'meta' => ['name' => $d['name']]]);
        Session::flash('new_token', $res['token']);
        return Response::redirect('/app/settings#tokens');
    }

    public function revokeToken(Request $req, array $p): Response
    {
        $user = $this->user($req);
        $this->tokens->revoke((int) $user['id'], (int) $p['id']);
        $this->audit->log('api_token.revoked', ['actor_user_id' => (int) $user['id'], 'meta' => ['id' => (int) $p['id']]]);
        return $this->redirect('/app/settings#tokens', 'Token revoked.');
    }
}
