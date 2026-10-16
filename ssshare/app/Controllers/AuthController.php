<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogService;
use App\Services\SessionService;
use App\Services\SsoService;
use App\Services\TotpService;
use App\Services\UserService;
use App\Services\WorkspaceService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\RateLimiter;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $request, array $params): Response
    {
        return $this->view('auth/login', [
            'title' => 'Sign in',
            'errors' => Session::pullFlash('errors', []),
            'old' => Session::pullFlash('old', []),
            'next' => $this->safeNext($request->query['next'] ?? null, '/dashboard'),
        ]);
    }

    public function login(Request $request, array $params): Response
    {
        $next = $this->safeNext($request->post['next'] ?? null, '/dashboard');
        return $this->form($request, function () use ($request, $next) {
            $email = (string) ($request->post['email'] ?? '');
            $user = (new UserService())->authenticate($email, (string) ($request->post['password'] ?? ''));
            if (SsoService::passwordLoginBlocked($user)) {
                throw new HttpException(422, 'sso_required', 'Your workspace requires single sign-on.', ['email' => 'Your workspace requires single sign-on. Use “Sign in with SSO”.']);
            }
            if ($user['totp_enabled_at'] !== null) {
                // Second step: the session is only upgraded after the code is verified.
                Session::regenerate();
                Session::put('pending_2fa', ['user_id' => (int) $user['id'], 'next' => $next, 'exp' => now()->getTimestamp() + 300]);
                return Response::redirect('/login/2fa');
            }
            return $this->complete($request, $user, 'password', $next);
        }, '/login?next=' . rawurlencode($next));
    }

    private function complete(Request $request, array $user, string $method, string $next): Response
    {
        Auth::login($user);
        SessionService::record($user, $request, $method);
        return Response::redirect($next);
    }

    public function showTwoFactor(Request $request, array $params): Response
    {
        if (!is_array(Session::get('pending_2fa'))) {
            return Response::redirect('/login');
        }
        return $this->view('auth/two_factor', ['title' => 'Two-factor authentication', 'errors' => Session::pullFlash('errors', [])]);
    }

    public function twoFactor(Request $request, array $params): Response
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending) || $pending['exp'] < now()->getTimestamp()) {
            Session::forget('pending_2fa');
            Session::flash('errors', ['_form' => 'Your sign-in expired. Start again.']);
            return Response::redirect('/login');
        }
        RateLimiter::enforce('2fa:' . $pending['user_id'], 8, 900, 'Too many attempts. Try again in 15 minutes.');
        $user = Database::first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$pending['user_id']]);
        if ($user === null || !(new TotpService())->check($user, (string) ($request->post['code'] ?? ''))) {
            AuditLogService::record('user.2fa_failed', 'user', $user['uuid'] ?? null, [], 'guest');
            Session::flash('errors', ['code' => 'That code is not valid.']);
            return Response::redirect('/login/2fa');
        }
        Session::forget('pending_2fa');
        return $this->complete($request, $user, 'password+2fa', $pending['next']);
    }

    public function showRegister(Request $request, array $params): Response
    {
        return $this->view('auth/register', [
            'title' => 'Create account',
            'errors' => Session::pullFlash('errors', []),
            'old' => Session::pullFlash('old', []),
        ]);
    }

    public function register(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request) {
            $user = (new UserService())->register(
                (string) ($request->post['email'] ?? ''),
                (string) ($request->post['password'] ?? ''),
                (string) ($request->post['display_name'] ?? ''),
            );
            $free = \App\Services\PlanService::byCode('free');
            Database::update('users', ['plan_id' => $free['id']], 'id = ?', [$user['id']]);
            $this->flash('Welcome to SSShare! Your shares will now appear in your dashboard.');
            return $this->complete($request, $user, 'password', $this->safeNext($request->post['next'] ?? null, '/dashboard'));
        }, '/register');
    }

    public function logout(Request $request, array $params): Response
    {
        if (Auth::check()) {
            AuditLogService::record('user.logout', 'user', Auth::user()['uuid']);
            SessionService::end();
        }
        Auth::logout();
        return Response::redirect('/');
    }

    // ------------------------------------------------------------------ SSO

    public function showSso(Request $request, array $params): Response
    {
        return $this->view('auth/sso', ['title' => 'Single sign-on', 'errors' => Session::pullFlash('errors', [])]);
    }

    public function sso(Request $request, array $params): Response
    {
        $workspace = SsoService::workspaceForEmail((string) ($request->post['email'] ?? ''));
        if ($workspace === null) {
            Session::flash('errors', ['email' => 'No single sign-on is set up for that email domain.']);
            return Response::redirect('/sso');
        }
        return Response::redirect((new SsoService())->start($workspace), 302);
    }

    public function ssoStart(Request $request, array $params): Response
    {
        $workspace = WorkspaceService::byPublicId($params['id']) ?? throw HttpException::notFound();
        return Response::redirect((new SsoService())->start($workspace), 302);
    }

    public function oidcCallback(Request $request, array $params): Response
    {
        if (isset($request->query['error'])) {
            Session::flash('errors', ['email' => 'Sign-in was cancelled or denied by your identity provider.']);
            return Response::redirect('/sso');
        }
        $service = new SsoService();
        $identity = $service->oidcCallback((string) ($request->query['code'] ?? ''), (string) ($request->query['state'] ?? ''));
        return $this->complete($request, $service->provision($identity), 'sso', '/dashboard');
    }

    public function samlAcs(Request $request, array $params): Response
    {
        $service = new SsoService();
        $identity = $service->samlAcs((string) ($request->post['SAMLResponse'] ?? ''), (string) ($request->post['RelayState'] ?? ''));
        return $this->complete($request, $service->provision($identity), 'sso', '/dashboard');
    }

    public function samlMetadata(Request $request, array $params): Response
    {
        $workspace = WorkspaceService::byPublicId($params['id']) ?? throw HttpException::notFound();
        return new Response(SsoService::spMetadata($workspace), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }
}
