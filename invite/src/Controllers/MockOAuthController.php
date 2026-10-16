<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\OAuthService;

/**
 * Development-only identity provider (APP_ENV=local, OAUTH_MOCK=1). Implements just enough of authorize/token/userinfo,
 * including PKCE verification, to exercise the real OAuth client end to end. Never reachable otherwise.
 */
final class MockOAuthController extends Controller
{
    private function dir(): string
    {
        $d = BASE_PATH . '/storage/oauth-mock';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    private function guard(): void
    {
        if (!OAuthService::mockEnabled()) {
            throw new NotFoundException();
        }
    }

    public function authorize(Request $req): Response
    {
        $this->guard();
        return $this->view('auth/mock_oauth', ['q' => $req->query], 'layouts/guest');
    }

    public function approve(Request $req): Response
    {
        $this->guard();
        $code = bin2hex(random_bytes(16));
        $email = $req->string('email');
        file_put_contents($this->dir() . '/code-' . $code . '.json', json_encode([
            'challenge' => $req->string('code_challenge'), 'redirect_uri' => $req->string('redirect_uri'), 'created' => time(),
            'profile' => ['sub' => 'mock-' . hash('sha256', mb_strtolower($email)), 'email' => $email, 'name' => $req->string('name'), 'email_verified' => $req->bool('verified')],
        ]));
        $to = $req->string('redirect_uri');
        if (!str_starts_with($to, app_url('auth/'))) {
            throw new HttpException(400, 'Unexpected redirect_uri');
        }
        return new Response(303, '', ['Location' => $to . '?' . http_build_query(['code' => $code, 'state' => $req->string('state')])]);
    }

    public function token(Request $req): Response
    {
        $this->guard();
        $code = preg_replace('/[^a-f0-9]/', '', $req->string('code'));
        $file = $this->dir() . '/code-' . $code . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_file($file)) {
            unlink($file); // single use
        }
        $verifierOk = $data && hash_equals((string) $data['challenge'], base64url(hash('sha256', $req->string('code_verifier'), true)));
        if (!$data || !$verifierOk || $req->string('client_secret') !== 'mock-secret' || $req->string('redirect_uri') !== $data['redirect_uri'] || time() - $data['created'] > 300) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        $access = bin2hex(random_bytes(16));
        file_put_contents($this->dir() . '/token-' . $access . '.json', json_encode($data['profile']));
        return $this->json(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 300]);
    }

    public function userinfo(Request $req): Response
    {
        $this->guard();
        $t = preg_replace('/[^a-f0-9]/', '', (string) $req->bearer());
        $file = $this->dir() . '/token-' . $t . '.json';
        if ($t === '' || !is_file($file)) {
            return $this->json(['error' => 'invalid_token'], 401);
        }
        return $this->json(json_decode((string) file_get_contents($file), true));
    }
}