<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AccountService;
use App\Services\BillingService;
use App\Services\LinkGate;
use App\Services\PlanService;
use App\Services\SessionService;
use App\Services\SsoService;
use App\Services\StorageService;
use App\Services\TotpService;
use App\Services\UploadService;
use App\Services\WebhookService;
use App\Services\WorkspaceService;
use App\Support\Auth;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\Session;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use Tests\TestCase;

/** Phase 3: workspaces, roles, policies, domains, SSO, billing, 2FA, sessions, privacy rights. */
final class Phase3Test extends TestCase
{
    private function workspace(array $owner, string $name = 'Acme'): array
    {
        return (new WorkspaceService())->create($owner, $name);
    }

    public function testWorkspacesRolesAndInvitations(): void
    {
        $this->assertHttpError('plan_required', fn () => (new WorkspaceService())->create($this->user(), 'Nope'), 402);
        $owner = $this->planUser('business');
        $ws = $this->workspace($owner);
        $this->assertSame('owner', WorkspaceService::role((int) $ws['id'], (int) $owner['id']));

        $service = new WorkspaceService();
        $invite = $service->invite($ws, $owner, 'Member@Example.test', 'member');
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE to_email = 'member@example.test' AND template = 'workspace_invitation'"));
        $stranger = $this->user();
        $this->assertHttpError('forbidden', fn () => $service->accept($invite['token'], $stranger), 403);
        $member = (new \App\Services\UserService())->register('member@example.test', 'a long enough password', 'Member');
        $service->accept($invite['token'], $member);
        $this->assertSame('member', WorkspaceService::role((int) $ws['id'], (int) $member['id']));
        $this->assertHttpError('not_found', fn () => $service->accept($invite['token'], $member), 404);

        $member = $this->fresh($member);
        $this->assertHttpError('forbidden', fn () => $service->invite($ws, $member, 'x@example.test', 'member'), 403);
        $this->assertTrue(PlanService::has($member, 'sso'), 'Members get the workspace plan');
        $service->setRole($ws, $owner, (int) $member['id'], 'admin');
        $this->assertHttpError('invalid_state', fn () => $service->setRole($ws, $this->fresh($member), (int) $owner['id'], 'viewer'), 409);
        $service->removeMember($ws, $owner, (int) $member['id']);
        $this->assertSame(null, WorkspaceService::role((int) $ws['id'], (int) $member['id']));
        $this->assertHttpError('invalid_state', fn () => $service->removeMember($ws, $owner, (int) $owner['id']), 409);
        $this->assertTrue((int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE workspace_id = ? AND action LIKE 'workspace.%'", [$ws['id']]) >= 5, 'Workspace actions are audited');
    }

    public function testRetentionPolicyAndRegions(): void
    {
        $owner = $this->planUser('business');
        $ws = $this->workspace($owner);
        $owner = $this->fresh($owner);
        (new WorkspaceService())->updateSettings($ws, $owner, ['retention_max' => '7d', 'retention_default' => '1d']);
        $owner = $this->fresh($owner);
        $this->assertSame(604800, UploadService::maxLifetime($owner));
        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createText('x', null, ['expires_in' => '30d'], $owner));
        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createText('x', null, ['expires_in' => 'never'], $owner));
        $ok = (new UploadService())->createText('x', null, [], $owner);
        $this->assertSame((int) $ws['id'], (int) $ok['upload']['workspace_id']);
        $hours = (strtotime($this->link($ok)['expires_at'] . ' UTC') - now()->getTimestamp()) / 3600;
        $this->assertTrue($hours > 23.9 && $hours <= 24, 'Workspace default expiry applied');
        $this->assertHttpError('validation_failed', fn () => (new WorkspaceService())->updateSettings($ws, $owner, ['storage_region' => 'mars']));
        $this->assertHttpError('validation_failed', fn () => (new WorkspaceService())->updateSettings($ws, $owner, ['require_sso' => '1']));
    }

    public function testWorkspaceOnlyLinksAndDrafts(): void
    {
        $owner = $this->planUser('business');
        $ws = $this->workspace($owner);
        $owner = $this->fresh($owner);
        $result = (new UploadService())->createText('internal', null, ['audience' => 'workspace'], $owner);
        $link = $this->link($result);
        Auth::setUser(null);
        $this->assertSame('workspace_login', LinkGate::check($link, $this->upload($result)));
        Auth::setUser($this->user());
        $this->assertSame('workspace_denied', LinkGate::check($link, $this->upload($result)));
        Auth::setUser($owner);
        $this->assertSame(null, LinkGate::check($link, $this->upload($result)));

        $draft = (new UploadService())->createText('draft', null, ['visibility' => 'private'], $owner);
        Auth::setUser($this->user());
        $this->assertSame('draft', LinkGate::check($this->link($draft), $this->upload($draft)));
        $this->assertSame(null, SharePresenterDirect::of($draft, $this));
    }

    public function testEmailAllowlistNeedsAVerifiedCode(): void
    {
        $pro = $this->planUser('pro');
        $result = (new UploadService())->createText('for alice', null, ['audience' => 'emails', 'audience_list' => 'alice@example.test'], $pro);
        $link = $this->link($result);
        $upload = $this->upload($result);
        Auth::setUser(null);
        $this->assertSame('verify_email', LinkGate::check($link, $upload));

        LinkGate::requestCode($link, $upload, 'mallory@example.test');
        $this->assertSame(0, (int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE to_email = 'mallory@example.test'"), 'No code for addresses not on the list');
        LinkGate::requestCode($link, $upload, 'alice@example.test');
        $mail = Database::first("SELECT * FROM mail_outbox WHERE to_email = 'alice@example.test' ORDER BY id DESC LIMIT 1");
        preg_match('/\b(\d{6})\b/', $mail['body'], $m);
        $this->assertFalse(LinkGate::verifyCode($link, '000000') && $m[1] !== '000000');
        $this->assertTrue(LinkGate::verifyCode($link, $m[1]));
        $this->assertSame(null, LinkGate::check($link, $upload));
        $this->assertFalse(LinkGate::verifyCode($link, $m[1]), 'Codes are single use');

        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createText('x', null, ['audience' => 'emails', 'audience_list' => 'a@b.c'], $this->user()), 422);
    }

    public function testRecipientLinksAndDeleteWhenAllViewed(): void
    {
        $pro = $this->planUser('pro');
        $result = (new UploadService())->createText('for two people', null, ['recipients' => 'a@example.test, b@example.test', 'expire_when_all_viewed' => '1'], $pro);
        $links = Database::select('SELECT * FROM share_links WHERE upload_id = ? AND recipient_email IS NOT NULL ORDER BY recipient_email', [$result['upload']['id']]);
        $this->assertSame(2, count($links));
        $this->assertSame('emails', $links[0]['audience']);
        $this->assertSame(2, (int) Database::value("SELECT COUNT(*) FROM mail_outbox WHERE template = 'share_link' AND to_email IN ('a@example.test', 'b@example.test')"));
        $exp = new \App\Services\ExpirationService();
        $exp->access((int) $links[0]['id'], 'view', $this->request());
        $this->assertSame('active', $this->upload($result)['status']);
        $exp->access((int) $links[1]['id'], 'view', $this->request());
        $this->assertSame('expired', $this->upload($result)['status'], 'Deleted after the last recipient viewed it');
        $this->assertSame(0, (int) Database::value("SELECT COUNT(*) FROM share_links WHERE upload_id = ? AND status = 'active'", [$result['upload']['id']]));
    }

    public function testMaxConcurrentSessions(): void
    {
        $pro = $this->planUser('pro');
        $result = (new UploadService())->createText('limited', null, ['max_sessions' => 1], $pro);
        $link = $this->link($result);
        Auth::setUser(null);
        Session::useId('sessionone');
        $this->assertSame(null, LinkGate::check($link, $this->upload($result)));
        $this->assertSame(null, LinkGate::check($link, $this->upload($result)), 'The same viewer may return');
        Session::useId('sessiontwo');
        $this->assertSame('too_many_sessions', LinkGate::check($link, $this->upload($result)));
        Clock::travel(1801);
        $this->assertSame(null, LinkGate::check($link, $this->upload($result)), 'Idle sessions free their slot');
        Session::useId(null);
    }

    public function testCustomDomainVerification(): void
    {
        $owner = $this->planUser('business');
        $ws = $this->workspace($owner);
        $owner = $this->fresh($owner);
        $service = new WorkspaceService();
        $ws = $service->setDomain($ws, $owner, 'share.example.test');
        $this->assertFalse($service->verifyDomain($ws, static fn () => ['something-else']));
        $this->assertTrue($service->verifyDomain($ws, static fn (string $host) => $host === '_ssshare.share.example.test' ? [$ws['domain_token']] : []));
        $result = (new UploadService())->createText('branded', null, [], $owner);
        $this->assertSame('https://share.example.test/s/' . $result['link']['public_id'], \App\Services\SharePresenter::shareUrl($result['link']));
        $other = $this->workspace($this->planUser('business'), 'Other');
        $this->assertHttpError('validation_failed', fn () => $service->setDomain($other, Database::first('SELECT * FROM users WHERE id = ?', [$other['owner_user_id']]), 'share.example.test'));
    }

    // ------------------------------------------------------------------ SSO

    /** @return array{0:\OpenSSLAsymmetricKey, 1:string} private key and certificate PEM */
    private function keypair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'idp.example.test'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 30), $cert);
        return [$key, $cert];
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function testOidcLoginWithVerifiedIdToken(): void
    {
        $owner = $this->planUser('business');
        $ws = $this->workspace($owner);
        $owner = $this->fresh($owner);
        $issuer = 'https://idp.example.test';
        (new SsoService())->configure($ws, $owner, ['protocol' => 'oidc', 'domains' => 'corp.test', 'issuer' => $issuer, 'client_id' => 'ssshare', 'client_secret' => 'shh']);
        $ws = WorkspaceService::byPublicId($ws['public_id']);
        $this->assertNotContains('shh', (string) $ws['sso_config'], 'Client secret encrypted');

        [$key] = $this->keypair();
        $details = openssl_pkey_get_details($key);
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => self::b64url($details['rsa']['n']), 'e' => self::b64url($details['rsa']['e'])]]];
        $nonce = null;
        $makeToken = function (array $claims) use ($key): string {
            $h = self::b64u(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
            $p = self::b64u(json_encode($claims));
            openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
            return "$h.$p." . self::b64u($sig);
        };
        $claims = static fn (string $nonce) => ['iss' => $issuer, 'aud' => 'ssshare', 'sub' => 'u-42', 'email' => 'dev@corp.test', 'email_verified' => true, 'name' => 'Dev Person', 'exp' => time() + 300, 'nonce' => $nonce];
        $tokenFor = null;
        SsoService::useHttp(function (string $method, string $url, array $form) use ($issuer, $jwks, &$tokenFor) {
            return match (true) {
                str_ends_with($url, '/.well-known/openid-configuration') => [200, json_encode(['authorization_endpoint' => "$issuer/auth", 'token_endpoint' => "$issuer/token", 'jwks_uri' => "$issuer/jwks"])],
                str_ends_with($url, '/jwks') => [200, json_encode($jwks)],
                str_ends_with($url, '/token') => [200, json_encode(['id_token' => ($tokenFor)($form)])],
                default => [404, ''],
            };
        });
        try {
            $redirect = (new SsoService())->start($ws);
            parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
            $this->assertSame('S256', $q['code_challenge_method'], 'PKCE');
            $pending = Session::get('sso');
            $tokenFor = function (array $form) use ($makeToken, $claims, $pending) {
                // The verifier must match the challenge that was sent.
                return $makeToken($claims($pending['nonce']));
            };
            $identity = (new SsoService())->oidcCallback('code123', $q['state']);
            $this->assertSame('dev@corp.test', $identity['email']);
            $user = (new SsoService())->provision($identity);
            $this->assertSame('member', WorkspaceService::role((int) $ws['id'], (int) $user['id']));
            $this->assertSame('oidc|' . $issuer . '|u-42', $user['sso_subject']);

            // Replayed state, forged signature and wrong nonce are rejected.
            $this->assertHttpError('forbidden', fn () => (new SsoService())->oidcCallback('code123', $q['state']), 403);
            $meta = ['jwks_uri' => "$issuer/jwks"];
            $cfg = ['issuer' => $issuer, 'client_id' => 'ssshare'];
            [$other] = $this->keypair();
            $forged = (function () use ($other, $claims) {
                $h = self::b64u(json_encode(['alg' => 'RS256', 'kid' => 'k1']));
                $p = self::b64u(json_encode($claims('n')));
                openssl_sign("$h.$p", $sig, $other, OPENSSL_ALGO_SHA256);
                return "$h.$p." . self::b64u($sig);
            })();
            $this->assertHttpError('forbidden', fn () => (new SsoService())->verifyIdToken($forged, $meta, $cfg, 'n'), 403);
            $this->assertHttpError('forbidden', fn () => (new SsoService())->verifyIdToken($makeToken($claims('n')), $meta, $cfg, 'other-nonce'), 403);
            $none = self::b64u('{"alg":"none"}') . '.' . self::b64u(json_encode($claims('n'))) . '.';
            $this->assertHttpError('forbidden', fn () => (new SsoService())->verifyIdToken($none, $meta, $cfg, 'n'), 403);
            $this->assertHttpError('forbidden', fn () => (new SsoService())->provision(['email' => 'x@evil.test', 'name' => 'x', 'subject' => 's', 'workspace' => $ws]), 403);
        } finally {
            SsoService::useHttp(null);
        }
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Builds a SAML Response with a signed assertion, as an IdP would. */
    private function samlResponse($key, string $acs, string $audience, ?string $inResponseTo, string $email = 'sam@corp.test', ?string $assertionId = null): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $later = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $assertionId ??= '_a' . bin2hex(random_bytes(8));
        $irt = $inResponseTo ? ' InResponseTo="' . $inResponseTo . '"' : '';
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r' . bin2hex(random_bytes(8)) . '" Version="2.0" IssueInstant="' . $now . '">'
            . '<saml:Issuer>https://idp.corp.test</saml:Issuer><samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . '<saml:Assertion ID="' . $assertionId . '" Version="2.0" IssueInstant="' . $now . '"><saml:Issuer>https://idp.corp.test</saml:Issuer>'
            . '<saml:Subject><saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">' . $email . '</saml:NameID>'
            . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData Recipient="' . $acs . '" NotOnOrAfter="' . $later . '"' . $irt . '/></saml:SubjectConfirmation></saml:Subject>'
            . '<saml:Conditions NotBefore="' . $now . '" NotOnOrAfter="' . $later . '"><saml:AudienceRestriction><saml:Audience>' . $audience . '</saml:Audience></saml:AudienceRestriction></saml:Conditions>'
            . '<saml:AttributeStatement><saml:Attribute Name="displayName"><saml:AttributeValue>Sam Corp</saml:AttributeValue></saml:Attribute></saml:AttributeStatement>'
            . '</saml:Assertion></samlp:Response>';
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $assertion = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Assertion')->item(0);
        $digest = base64_encode(hash('sha256', $assertion->C14N(true, false), true));
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $sig = $doc->createElementNS($ds, 'ds:Signature');
        $signedInfo = $doc->createElementNS($ds, 'ds:SignedInfo');
        $signedInfo->appendChild($doc->createElementNS($ds, 'ds:CanonicalizationMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $signedInfo->appendChild($doc->createElementNS($ds, 'ds:SignatureMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $ref = $signedInfo->appendChild($doc->createElementNS($ds, 'ds:Reference'));
        $ref->setAttribute('URI', '#' . $assertionId);
        $transforms = $ref->appendChild($doc->createElementNS($ds, 'ds:Transforms'));
        $transforms->appendChild($doc->createElementNS($ds, 'ds:Transform'))->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($doc->createElementNS($ds, 'ds:Transform'))->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        $ref->appendChild($doc->createElementNS($ds, 'ds:DigestMethod'))->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $ref->appendChild($doc->createElementNS($ds, 'ds:DigestValue', $digest));
        $sig->appendChild($signedInfo);
        $issuer = $assertion->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
        $assertion->insertBefore($sig, $issuer->nextSibling);
        openssl_sign($signedInfo->C14N(true, false), $value, $key, OPENSSL_ALGO_SHA256);
        $sig->appendChild($doc->createElementNS($ds, 'ds:SignatureValue', base64_encode($value)));
        return $doc->saveXML($doc->documentElement);
    }

    public function testSamlAssertionsAreVerified(): void
    {
        [$key, $cert] = $this->keypair();
        $config = ['idp_entity_id' => 'https://idp.corp.test', 'idp_certificate' => $cert];
        $acs = 'http://localhost/sso/saml/acs';
        $aud = 'http://localhost/sso/ws/metadata';

        $ok = SsoService::validateSamlResponse($this->samlResponse($key, $acs, $aud, '_req1'), $config, $aud, $acs, '_req1');
        $this->assertSame('sam@corp.test', $ok['email']);
        $this->assertSame('Sam Corp', $ok['name']);

        $fail = fn (string $xml, ?string $req = '_req1') => $this->assertHttpError('forbidden', fn () => SsoService::validateSamlResponse($xml, $config, $aud, $acs, $req), 403);
        // Tampered email after signing.
        $fail(str_replace('sam@corp.test', 'admin@corp.test', $this->samlResponse($key, $acs, $aud, '_req1')));
        // Signed by a different key.
        [$otherKey] = $this->keypair();
        $fail($this->samlResponse($otherKey, $acs, $aud, '_req1'));
        // Wrong audience, recipient or request id.
        $fail($this->samlResponse($key, $acs, 'https://other.example/sp', '_req1'));
        $fail($this->samlResponse($key, 'https://evil.example/acs', $aud, '_req1'));
        $fail($this->samlResponse($key, $acs, $aud, '_other'));
        // Replay of the same assertion.
        $id = '_replay' . bin2hex(random_bytes(4));
        SsoService::validateSamlResponse($this->samlResponse($key, $acs, $aud, '_req1', 'sam@corp.test', $id), $config, $aud, $acs, '_req1');
        $fail($this->samlResponse($key, $acs, $aud, '_req1', 'sam@corp.test', $id));
        // Signature wrapping: a second, unsigned assertion injected next to the signed one.
        $signed = $this->samlResponse($key, $acs, $aud, '_req1');
        $evil = preg_replace('#(<saml:Assertion )#', '<saml:Assertion ID="_evil" Version="2.0" IssueInstant="' . gmdate('Y-m-d\TH:i:s\Z') . '"><saml:Issuer>https://idp.corp.test</saml:Issuer><saml:Subject><saml:NameID>admin@corp.test</saml:NameID></saml:Subject></saml:Assertion>$1', $signed, 1);
        $fail($evil);
        // XXE / DTD payloads are refused outright.
        $fail('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . $signed);
        // Expired.
        Clock::travel(3600);
        $fail($this->samlResponse($key, $acs, $aud, '_req1'));
    }

    // ------------------------------------------------------------------ billing

    public function testBillingWebhooksAreSignedIdempotentAndChangePlans(): void
    {
        $user = $this->user();
        $billing = new BillingService();
        $secret = (string) config('app.billing.webhook_secret');
        $send = static function (array $event, ?string $sig = null) use ($billing, $secret) {
            $body = json_encode($event);
            return $billing->handleWebhook($body, $sig ?? WebhookService::sign($secret, time(), $body));
        };
        $event = ['id' => 'evt_' . bin2hex(random_bytes(6)), 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'subscription' => 'sub_' . bin2hex(random_bytes(6)), 'client_reference_id' => $user['uuid'], 'metadata' => ['plan' => 'pro'], 'current_period_end' => time() + 2592000,
        ]]];
        $this->assertHttpError('invalid_signature', fn () => $send($event, 't=' . time() . ',v1=' . str_repeat('0', 64)), 400);
        $this->assertSame('processed', $send($event));
        $this->assertSame('duplicate', $send($event), 'Replays are ignored');
        PlanService::flush();
        $user = $this->fresh($user);
        $this->assertSame('pro', PlanService::forUser($user)['code']);
        $this->assertSame(107374182400, (int) $user['storage_quota_bytes']);

        $ref = $event['data']['object']['subscription'];
        $send(['id' => 'evt_f' . bin2hex(random_bytes(4)), 'type' => 'invoice.payment_failed', 'data' => ['object' => ['subscription' => $ref]]]);
        $this->assertSame('past_due', Database::value('SELECT status FROM subscriptions WHERE provider_ref = ?', [$ref]));
        $send(['id' => 'evt_c' . bin2hex(random_bytes(4)), 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => $ref]]]);
        PlanService::flush();
        $this->assertSame('free', PlanService::forUser($this->fresh($user))['code']);

        $buyer = $this->user();
        $billing->completeTestCheckout($buyer, 'plus');
        PlanService::flush();
        $this->assertSame('plus', PlanService::forUser($this->fresh($buyer))['code']);
        $billing->cancel($this->fresh($buyer));
        PlanService::flush();
        $this->assertSame('free', PlanService::forUser($this->fresh($buyer))['code']);
    }

    // ------------------------------------------------------------------ 2FA & sessions

    public function testTotpMatchesRfc6238AndRecoveryCodesWork(): void
    {
        // RFC 6238 test secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', TotpService::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', TotpService::code($secret, intdiv(1111111109, 30)));

        $user = $this->user();
        $totp = new TotpService();
        $setup = $totp->begin($user);
        $user = $this->fresh($user);
        $this->assertHttpError('validation_failed', fn () => $totp->confirm($user, '000000'));
        $code = TotpService::code($setup, intdiv(now()->getTimestamp(), 30));
        $recovery = $totp->confirm($user, $code);
        $this->assertSame(10, count($recovery));
        $user = $this->fresh($user);
        $this->assertTrue(str_starts_with((string) $user['totp_secret'], 'enc:v1:'), 'TOTP seed encrypted at rest');
        $this->assertFalse($totp->check($user, $code), 'A code cannot be reused');
        Clock::travel(30);
        $this->assertTrue($totp->check($user, TotpService::code($setup, intdiv(now()->getTimestamp(), 30))));
        $this->assertTrue($totp->check($user, $recovery[0]));
        $this->assertFalse($totp->check($this->fresh($user), $recovery[0]), 'Recovery codes are single use');
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $enc = Crypto::encrypt('secret value', 'x');
        $this->assertSame('secret value', Crypto::decrypt($enc, 'x'));
        $this->assertThrows(fn () => Crypto::decrypt($enc, 'y'), 'Purpose separation');
        $tampered = substr($enc, 0, -4) . (substr($enc, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
        $this->assertThrows(fn () => Crypto::decrypt($tampered, 'x'));
    }

    public function testAccountExportAndDeletion(): void
    {
        $user = $this->user();
        $kept = (new UploadService())->createImage($this->png(), 'mine.png', [], $user);
        $other = $this->user();
        $comment = (new \App\Services\CommentService())->add($this->upload((new UploadService())->createText('theirs', null, [], $other)), \App\Services\CommentService::actor($user, $this->request(), null), 'my comment');

        $export = (new AccountService())->requestExport($user);
        (new AccountService())->buildPending();
        $export = Database::first('SELECT * FROM data_exports WHERE id = ?', [$export['id']]);
        $this->assertSame('ready', $export['status']);
        $zipPath = tempnam(sys_get_temp_dir(), 'z');
        file_put_contents($zipPath, StorageService::driver()->get($export['object_key']));
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $this->assertTrue($zip->locateName('account.json') !== false && $zip->locateName('uploads.json') !== false);
        $this->assertTrue($zip->locateName('content/' . $kept['upload']['uuid'] . '.png') !== false, 'Files of active shares are included');
        $zip->close();

        $this->assertHttpError('validation_failed', fn () => (new AccountService())->delete($user, 'wrong'));
        (new AccountService())->delete($user, 'correct horse battery');
        $this->assertSame(null, Database::first('SELECT id FROM users WHERE id = ?', [$user['id']]));
        $this->assertFalse(StorageService::driver()->exists($kept['file']['object_key']), 'Content physically deleted');
        $this->assertFalse(StorageService::driver()->exists($export['object_key']), 'Exports deleted too');
        $this->assertSame('Deleted user', Database::value('SELECT author_name FROM comments WHERE id = ?', [$comment['id']]));
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.deleted' AND target_id = ?", [$user['uuid']]));
    }

    public function testRevokedSessionsAreRejected(): void
    {
        $user = $this->user();
        Session::useId('devicea');
        SessionService::record($user, $this->request(), 'password');
        Session::useId('deviceb');
        SessionService::record($user, $this->request(), 'password');
        $this->assertSame(2, count(SessionService::forUser((int) $user['id'])));
        SessionService::revokeOthers((int) $user['id']);
        $this->assertTrue(SessionService::validate((int) $user['id']), 'Current session stays');
        Session::useId('devicea');
        $this->assertFalse(SessionService::validate((int) $user['id']), 'Other device is signed out');
        Session::useId(null);
    }
}

/** @internal helper to read a direct URL without a public SharePresenter dependency in the test name */
final class SharePresenterDirect
{
    public static function of(array $result, TestCase $t): ?string
    {
        $link = Database::first('SELECT * FROM share_links WHERE id = ?', [$result['link']['id']]);
        $upload = Database::first('SELECT * FROM uploads WHERE id = ?', [$result['upload']['id']]);
        return \App\Services\SharePresenter::directUrl($link, $upload, $result['file']);
    }
}
