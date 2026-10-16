<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\OAuthService;
use App\Services\RateLimiter;

final class OAuthController extends Controller
{
    public function __construct(private OAuthService $oauth, private AuthService $auth, private RateLimiter $limiter)
    {
    }

    public function start(Request $req, array $p): Response
    {
        if (!$this->oauth->config($p['provider'])) {
            throw new NotFoundException();
        }
        $this->limiter->enforce('oauth:' . $req->ip(), 30, 600);
        $url = $this->oauth->authorizeUrl($p['provider']);
        return new Response(303, '', ['Location' => $url]); // provider URL is external by design
    }

    public function callback(Request $req, array $p): Response
    {
        if (!$this->oauth->config($p['provider'])) {
            throw new NotFoundException();
        }
        $this->limiter->enforce('oauth:' . $req->ip(), 30, 600);
        if (($req->query['error'] ?? '') !== '') {
            return $this->redirect('/login', 'Sign-in was cancelled.', 'info');
        }
        $res = $this->oauth->complete($p['provider'], (string) ($req->query['code'] ?? ''), (string) ($req->query['state'] ?? ''));
        if (!$res['user']) {
            return $this->redirect('/login', $res['error'] ?? 'Sign-in failed.', 'error');
        }
        if (!$this->auth->beginLogin($res['user'])) {
            return Response::redirect('/login/mfa');
        }
        $to = Session::get('intended', '/app');
        Session::forget('intended');
        return Response::redirect(is_string($to) ? $to : '/app');
    }
}