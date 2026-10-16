<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\QrService;
use App\Services\RateLimiter;
use App\Services\TotpService;

/** Sign-in second step (public) and enrolment/management (authenticated). */
final class MfaController extends Controller
{
    public function __construct(
        private TotpService $totp,
        private UserRepository $users,
        private AuthService $auth,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private QrService $qr
    ) {
    }

    // ---- Sign-in challenge ------------------------------------------------------------------------------------

    public function challenge(Request $req): Response
    {
        if (!Session::get('mfa_pending')) {
            return Response::redirect('/login');
        }
        return $this->view('auth/mfa', [], 'layouts/guest');
    }

    public function verify(Request $req): Response
    {
        $uid = Session::get('mfa_pending');
        if (!is_int($uid) || (int) Session::get('mfa_pending_until', 0) < time()) {
            Session::forget('mfa_pending');
            return $this->redirect('/login', 'Your sign-in expired. Please start again.', 'error');
        }
        $this->limiter->enforce('mfa:' . $uid, 8, 600);
        $code = $req->string('code');
        $ok = $this->totp->verify($uid, $code) || $this->totp->useRecovery($uid, $code);
        if (!$ok) {
            $this->audit->log('mfa.failed', ['actor_user_id' => $uid]);
            return $this->redirect('/login/mfa', 'That code is not valid. Check your authenticator app and try again.', 'error');
        }
        $user = $this->users->find($uid);
        Session::forget('mfa_pending');
        Session::forget('mfa_pending_until');
        $this->auth->login($user);
        $to = Session::get('intended', '/app');
        Session::forget('intended');
        return Response::redirect(is_string($to) ? $to : '/app');
    }

    // ---- Management ---------------------------------------------------------------------------------------------

    public function setup(Request $req): Response
    {
        $user = $this->user($req);
        if ($this->totp->isEnabled((int) $user['id'])) {
            return $this->redirect('/app/settings', 'Two-step verification is already on.', 'info');
        }
        $secret = $this->totp->pendingSecret((int) $user['id']) ?? $this->totp->begin((int) $user['id']);
        $uri = $this->totp->uri($secret, $user['email']);
        return $this->view('settings/mfa', ['secret' => implode(' ', str_split($secret, 4)), 'qr' => $this->qr->svg($uri, 'M', 2, 'QR code for your authenticator app'), 'codes' => null]);
    }

    public function enable(Request $req): Response
    {
        $user = $this->user($req);
        $this->limiter->enforce('mfa-setup:' . $user['id'], 10, 600);
        $codes = $this->totp->confirm((int) $user['id'], $req->string('code'));
        if ($codes === null) {
            throw (new ValidationException(['code' => ['That code did not match. Make sure your phone clock is correct and try the next code.']]))->redirectTo('/app/settings/mfa');
        }
        $this->audit->log('mfa.enabled', ['actor_user_id' => (int) $user['id']]);
        return $this->view('settings/mfa', ['secret' => null, 'qr' => null, 'codes' => $codes]);
    }

    public function recovery(Request $req): Response
    {
        $user = $this->user($req);
        $this->requireStrong($req, $user);
        $codes = $this->totp->regenerateRecovery((int) $user['id']);
        return $this->view('settings/mfa', ['secret' => null, 'qr' => null, 'codes' => $codes]);
    }

    public function disable(Request $req): Response
    {
        $user = $this->user($req);
        $this->requireStrong($req, $user);
        $this->totp->disable((int) $user['id']);
        $this->audit->log('mfa.disabled', ['actor_user_id' => (int) $user['id']]);
        return $this->redirect('/app/settings', 'Two-step verification turned off.');
    }

    /** Sensitive changes need the current password (when set) and a current code. */
    private function requireStrong(Request $req, array $user): void
    {
        $this->limiter->enforce('mfa-manage:' . $user['id'], 8, 600);
        if (!empty($user['password_hash']) && !password_verify($req->string('password'), $user['password_hash'])) {
            throw (new ValidationException(['password' => ['Your password is not correct.']]))->redirectTo('/app/settings');
        }
        $code = $req->string('code');
        if (!$this->totp->verify((int) $user['id'], $code) && !$this->totp->useRecovery((int) $user['id'], $code)) {
            throw (new ValidationException(['code' => ['That verification code is not valid.']]))->redirectTo('/app/settings');
        }
    }
}