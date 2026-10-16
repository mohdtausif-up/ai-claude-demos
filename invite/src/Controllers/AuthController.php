<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Requests\EmailOnlyRequest;
use App\Requests\LoginRequest;
use App\Requests\RegisterRequest;
use App\Requests\ResetPasswordRequest;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function __construct(private AuthService $auth, private UserRepository $users, private \App\Services\OAuthService $oauth)
    {
    }

    public function showRegister(Request $req): Response
    {
        return $this->guard() ?? $this->view('auth/register', ['providers' => $this->oauth->enabled()], 'layouts/guest');
    }

    public function register(Request $req): Response
    {
        $data = RegisterRequest::from($req)->validated();
        $user = $this->auth->register($data['name'], $data['email'], $data['password']);
        $this->auth->login($user);
        return $this->redirect('/app', 'Welcome to Invite.com! Check your inbox to confirm your email.');
    }

    public function showLogin(Request $req): Response
    {
        return $this->guard() ?? $this->view('auth/login', ['providers' => $this->oauth->enabled()], 'layouts/guest');
    }

    public function login(Request $req): Response
    {
        $data = LoginRequest::from($req)->validated();
        $user = $this->auth->attempt($data['email'], $data['password'], $req->ip(), $req->userAgent());
        if (!$user) {
            Session::flash('error', 'Incorrect email or password.');
            Session::flash('_old', ['email' => $data['email']]);
            return Response::redirect('/login');
        }
        if (!$this->auth->beginLogin($user)) {
            return Response::redirect('/login/mfa');
        }
        $to = Session::get('intended', '/app');
        Session::forget('intended');
        return Response::redirect(is_string($to) ? $to : '/app');
    }

    public function logout(Request $req): Response
    {
        $this->auth->logout();
        return Response::redirect('/');
    }

    public function showForgot(Request $req): Response
    {
        return $this->view('auth/forgot', [], 'layouts/guest');
    }

    public function forgot(Request $req): Response
    {
        $data = EmailOnlyRequest::from($req)->validated();
        $this->auth->sendPasswordReset($data['email']);
        return $this->redirect('/login', 'If that address has an account, a reset link is on its way.', 'info');
    }

    public function showReset(Request $req, array $p): Response
    {
        return $this->view('auth/reset', ['token' => $p['token']], 'layouts/guest');
    }

    public function reset(Request $req, array $p): Response
    {
        $data = ResetPasswordRequest::from($req)->validated();
        if (!$this->auth->resetPassword($p['token'], $data['password'])) {
            return $this->redirect('/forgot', 'That reset link has expired or was already used. Request a new one.', 'error');
        }
        return $this->redirect('/login', 'Password updated. You can sign in now.');
    }

    public function verify(Request $req, array $p): Response
    {
        $ok = $this->auth->verifyEmail($p['token']);
        return $this->redirect(Session::get('user_id') ? '/app' : '/login', $ok ? 'Email confirmed. Thank you!' : 'That confirmation link has expired or was already used.', $ok ? 'success' : 'error');
    }

    public function resendVerification(Request $req): Response
    {
        $user = $this->user($req);
        if (!$user['email_verified_at']) {
            $this->auth->sendVerification($user);
        }
        return $this->redirect('/app', 'Confirmation email sent.');
    }

    public function magicRequest(Request $req): Response
    {
        $data = EmailOnlyRequest::from($req)->validated();
        $this->auth->sendMagicLink($data['email']);
        return $this->redirect('/login', 'If that address has an account, a sign-in link is on its way. It works once for 15 minutes.', 'info');
    }

    public function showMagic(Request $req, array $p): Response
    {
        return $this->view('auth/magic', ['token' => $p['token']], 'layouts/guest');
    }

    public function magic(Request $req, array $p): Response
    {
        $userId = $this->auth->consumeToken($p['token'], 'magic_login');
        $user = $userId ? $this->users->find($userId) : null;
        if (!$user || $user['status'] !== 'active') {
            return $this->redirect('/login', 'That sign-in link has expired or was already used.', 'error');
        }
        $this->users->update((int) $user['id'], ['email_verified_at' => $user['email_verified_at'] ?? utcnow()]);
        return Response::redirect($this->auth->beginLogin($user) ? '/app' : '/login/mfa');
    }

    private function guard(): ?Response
    {
        return Session::get('user_id') ? Response::redirect('/app') : null;
    }
}
