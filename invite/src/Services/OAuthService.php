<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use RuntimeException;

/**
 * "Sign in with Google / Microsoft" using the authorization-code flow with PKCE and a server-side token exchange.
 * Linking rules (account-takeover safe):
 *   - an existing identity (provider + subject) always signs in its user;
 *   - an existing user is linked by email only if the provider asserts email_verified=true;
 *   - otherwise an email clash is refused (the user signs in the normal way);
 *   - new accounts are created verified only when the provider verified the email.
 * A dev-only "mock" provider (OAUTH_MOCK=1 with APP_ENV=local) is served by this app so the flow can run without credentials.
 */
final class OAuthService
{
    public function __construct(
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
        private AuthService $auth,
        private AuditLogger $audit
    ) {
    }

    /** @return array{label:string,id:string,secret:string,auth:string,token:string,userinfo:string,scope:string}|null */
    public function config(string $provider): ?array
    {
        switch ($provider) {
            case 'google':
                $id = (string) env('GOOGLE_CLIENT_ID', '');
                return $id === '' ? null : ['label' => 'Google', 'id' => $id, 'secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
                    'auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
                    'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo', 'scope' => 'openid email profile'];
            case 'microsoft':
                $id = (string) env('MICROSOFT_CLIENT_ID', '');
                $t = rawurlencode((string) env('MICROSOFT_TENANT', 'common'));
                return $id === '' ? null : ['label' => 'Microsoft', 'id' => $id, 'secret' => (string) env('MICROSOFT_CLIENT_SECRET', ''),
                    'auth' => "https://login.microsoftonline.com/$t/oauth2/v2.0/authorize", 'token' => "https://login.microsoftonline.com/$t/oauth2/v2.0/token",
                    'userinfo' => 'https://graph.microsoft.com/oidc/userinfo', 'scope' => 'openid email profile'];
            case 'mock':
                if (!self::mockEnabled()) {
                    return null;
                }
                $b = rtrim((string) env('OAUTH_MOCK_URL', 'http://web'), '/') . '/_mock-oauth';
                return ['label' => 'Dev login', 'id' => 'mock-client', 'secret' => 'mock-secret', 'auth' => '/_mock-oauth/authorize', 'token' => $b . '/token', 'userinfo' => $b . '/userinfo', 'scope' => 'openid email profile'];
        }
        return null;
    }

    public static function mockEnabled(): bool
    {
        return env('APP_ENV') === 'local' && env('OAUTH_MOCK') === '1';
    }

    /** @return array<string,string> provider key => label for the sign-in buttons */
    public function enabled(): array
    {
        $out = [];
        foreach (['google', 'microsoft', 'mock'] as $p) {
            if ($c = $this->config($p)) {
                $out[$p] = $c['label'];
            }
        }
        return $out;
    }

    public function redirectUri(string $provider): string
    {
        return app_url('auth/' . $provider . '/callback');
    }

    /** Start: remember state + PKCE verifier in the session and return the provider URL. */
    public function authorizeUrl(string $provider): string
    {
        $c = $this->config($provider) ?? throw new RuntimeException('Provider not configured');
        $state = base64url(random_bytes(24));
        $verifier = base64url(random_bytes(48));
        Session::put('oauth_' . $provider, ['state' => $state, 'verifier' => $verifier, 'at' => time()]);
        return $c['auth'] . (str_contains($c['auth'], '?') ? '&' : '?') . http_build_query([
            'client_id' => $c['id'], 'redirect_uri' => $this->redirectUri($provider), 'response_type' => 'code', 'scope' => $c['scope'],
            'state' => $state, 'code_challenge' => base64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finish: verify state, exchange the code, read the profile, find/create/link the user.
     * @return array{user:?array,error:?string}
     */
    public function complete(string $provider, string $code, string $state): array
    {
        $c = $this->config($provider);
        $saved = Session::get('oauth_' . $provider);
        Session::forget('oauth_' . $provider);
        if (!$c || !is_array($saved) || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['at'] > 900) {
            return ['user' => null, 'error' => 'The sign-in attempt expired or was not started here. Please try again.'];
        }
        try {
            $token = $this->post($c['token'], [
                'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri($provider),
                'client_id' => $c['id'], 'client_secret' => $c['secret'], 'code_verifier' => $saved['verifier'],
            ]);
            $profile = $this->get($c['userinfo'], (string) ($token['access_token'] ?? ''));
        } catch (RuntimeException $e) {
            error_log('[oauth] ' . $provider . ': ' . $e->getMessage());
            return ['user' => null, 'error' => 'The provider did not accept the sign-in. Please try again or use another method.'];
        }
        $subject = (string) ($profile['sub'] ?? '');
        $email = mb_strtolower(trim((string) ($profile['email'] ?? '')));
        $verified = ($profile['email_verified'] ?? false) === true || ($profile['email_verified'] ?? '') === 'true';
        if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['user' => null, 'error' => 'The provider did not share an email address.'];
        }

        $uid = Db::value('SELECT user_id FROM oauth_identities WHERE provider = ? AND subject = ?', [$provider, $subject]);
        $user = $uid ? $this->users->find((int) $uid) : null;
        if (!$user) {
            $existing = $this->users->findByEmail($email);
            if ($existing) {
                if (!$verified) {
                    return ['user' => null, 'error' => 'An account with this email already exists. Sign in with your password or an email link instead.'];
                }
                $user = $existing;
                Db::insert('oauth_identities', ['user_id' => $user['id'], 'provider' => $provider, 'subject' => $subject, 'created_at' => utcnow()]);
                if (!$user['email_verified_at']) {
                    $this->users->update((int) $user['id'], ['email_verified_at' => utcnow()]);
                    $user = $this->users->find((int) $user['id']);
                }
                $this->audit->log('oauth.linked', ['actor_user_id' => (int) $user['id'], 'meta' => ['provider' => $provider]]);
            } else {
                $name = mb_substr(trim((string) ($profile['name'] ?? '')) ?: explode('@', $email)[0], 0, 100);
                $user = Db::transaction(function () use ($name, $email, $verified, $provider, $subject) {
                    $u = $this->users->create($name, $email, null, $verified);
                    $this->workspaces->create((int) $u['id'], $email, $name . "'s workspace");
                    $this->workspaces->linkPending((int) $u['id'], $email);
                    Db::insert('oauth_identities', ['user_id' => $u['id'], 'provider' => $provider, 'subject' => $subject, 'created_at' => utcnow()]);
                    return $u;
                });
                $this->audit->log('user.registered', ['actor_user_id' => (int) $user['id'], 'entity_type' => 'user', 'entity_uuid' => $user['uuid'], 'meta' => ['via' => $provider]]);
                if (!$verified) {
                    $this->auth->sendVerification($user);
                }
            }
        }
        if ($user['status'] !== 'active') {
            return ['user' => null, 'error' => 'This account is suspended.'];
        }
        return ['user' => $user, 'error' => null];
    }

    /** @return array<string,mixed> */
    private function post(string $url, array $fields): array
    {
        return $this->request($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    }

    /** @return array<string,mixed> */
    private function get(string $url, string $accessToken): array
    {
        if ($accessToken === '') {
            throw new RuntimeException('No access token');
        }
        return $this->request($url, [CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $accessToken]]);
    }

    private function request(string $url, array $opts): array
    {
        if (!str_starts_with($url, 'https://') && !(self::mockEnabled() && str_contains($url, '/_mock-oauth/'))) {
            throw new RuntimeException('Provider endpoints must use HTTPS');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            throw new RuntimeException("HTTP $code $err");
        }
        return $json;
    }
}