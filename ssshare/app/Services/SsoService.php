<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Crypto;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Session;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Workspace single sign-on.
 *   OIDC – authorization code flow with PKCE; RS256 ID tokens verified against the IdP's JWKS.
 *   SAML – SP-initiated (HTTP-Redirect AuthnRequest, HTTP-POST Response). The XML signature is
 *          verified with the configured IdP certificate (never one embedded in the message), and
 *          attributes are only read from inside the signed element to prevent signature wrapping.
 */
final class SsoService
{
    /** @var null|callable(string $method, string $url, array $form): array{0:int, 1:string} */
    private static $http = null;

    public static function useHttp(?callable $http): void
    {
        self::$http = $http;
    }

    // ------------------------------------------------------------------ configuration

    public function configure(array $workspace, array $actor, array $input): void
    {
        WorkspaceService::requireRole($workspace, $actor, 'admin');
        PlanService::require($actor, 'sso', 'Single sign-on');
        $protocol = $input['protocol'] ?? '';
        $domains = array_values(array_unique(array_map(static fn ($d) => strtolower(ltrim($d, '@')), AccessRules::parseList($input['domains'] ?? ''))));
        $errors = [];
        foreach ($domains as $d) {
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
                $errors['domains'] = "“$d” is not a valid domain.";
            }
        }
        if ($domains === []) {
            $errors['domains'] = 'Add the email domain(s) that sign in with this provider.';
        }
        $config = ['protocol' => $protocol, 'domains' => $domains, 'default_role' => in_array($input['default_role'] ?? 'member', ['member', 'viewer'], true) ? $input['default_role'] ?? 'member' : 'member'];
        if ($protocol === 'oidc') {
            $issuer = rtrim(trim((string) ($input['issuer'] ?? '')), '/');
            if (!filter_var($issuer, FILTER_VALIDATE_URL)) {
                $errors['issuer'] = 'Enter the issuer URL.';
            }
            if (trim((string) ($input['client_id'] ?? '')) === '') {
                $errors['client_id'] = 'Enter the client ID.';
            }
            $existing = $workspace['sso_config'] ? json_decode(Crypto::decrypt($workspace['sso_config'], 'sso'), true) : [];
            $secret = (string) ($input['client_secret'] ?? '') ?: ($existing['oidc']['client_secret'] ?? '');
            if ($secret === '') {
                $errors['client_secret'] = 'Enter the client secret.';
            }
            $config['oidc'] = ['issuer' => $issuer, 'client_id' => trim((string) $input['client_id']), 'client_secret' => $secret];
        } elseif ($protocol === 'saml') {
            $cert = trim((string) ($input['idp_certificate'] ?? ''));
            if (!str_contains($cert, 'BEGIN CERTIFICATE')) {
                $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $cert), 64, "\n") . "-----END CERTIFICATE-----\n";
            }
            if (openssl_x509_read($cert) === false) {
                $errors['idp_certificate'] = 'Paste the IdP signing certificate (PEM or base64).';
            }
            if (!filter_var($input['idp_sso_url'] ?? '', FILTER_VALIDATE_URL)) {
                $errors['idp_sso_url'] = 'Enter the IdP single sign-on URL.';
            }
            if (trim((string) ($input['idp_entity_id'] ?? '')) === '') {
                $errors['idp_entity_id'] = 'Enter the IdP entity ID.';
            }
            $config['saml'] = ['idp_entity_id' => trim((string) $input['idp_entity_id']), 'idp_sso_url' => $input['idp_sso_url'], 'idp_certificate' => $cert];
        } else {
            $errors['protocol'] = 'Choose OIDC or SAML.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        Database::update('workspaces', [
            'sso_config' => Crypto::encrypt(json_encode($config), 'sso'),
            'sso_domains' => implode(',', $domains),
            'updated_at' => db_time(),
        ], 'id = ?', [$workspace['id']]);
        WorkspaceService::audit($workspace, 'workspace.sso_configured', ['protocol' => $protocol, 'domains' => $domains]);
    }

    public static function config(array $workspace): ?array
    {
        return $workspace['sso_config'] ? json_decode(Crypto::decrypt($workspace['sso_config'], 'sso'), true) : null;
    }

    public static function workspaceForEmail(string $email): ?array
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '') {
            return null;
        }
        return Database::first("SELECT * FROM workspaces WHERE sso_config IS NOT NULL AND FIND_IN_SET(?, sso_domains) > 0 LIMIT 1", [$domain]);
    }

    /** Members of a workspace that requires SSO must use it (the owner keeps a password for recovery). */
    public static function passwordLoginBlocked(array $user): bool
    {
        return Database::value(
            "SELECT 1 FROM workspace_members m JOIN workspaces w ON w.id = m.workspace_id
             WHERE m.user_id = ? AND w.require_sso = 1 AND m.role <> 'owner' LIMIT 1",
            [$user['id']]
        ) !== null;
    }

    public static function spEntityId(array $workspace): string
    {
        return url('sso/' . $workspace['public_id'] . '/metadata');
    }

    public static function acsUrl(): string
    {
        return url('sso/saml/acs');
    }

    public static function oidcRedirectUri(): string
    {
        return url('sso/oidc/callback');
    }

    // ------------------------------------------------------------------ start

    /** Returns the IdP URL to redirect the browser to. */
    public function start(array $workspace): string
    {
        $config = self::config($workspace) ?? throw HttpException::notFound('Single sign-on is not configured for this workspace.');
        if ($config['protocol'] === 'oidc') {
            $meta = $this->discover($config['oidc']['issuer']);
            $state = Ids::token(24);
            $nonce = Ids::token(24);
            $verifier = Ids::token(32);
            Session::put('sso', ['ws' => $workspace['public_id'], 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'exp' => now()->getTimestamp() + 600]);
            return $meta['authorization_endpoint'] . (str_contains($meta['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
                'response_type' => 'code',
                'client_id' => $config['oidc']['client_id'],
                'redirect_uri' => self::oidcRedirectUri(),
                'scope' => 'openid email profile',
                'state' => $state,
                'nonce' => $nonce,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
            ]);
        }
        $requestId = '_' . bin2hex(random_bytes(16));
        // The IdP POSTs back cross-site, where SameSite=Lax session cookies are not sent,
        // so the pending request travels in a signed RelayState instead of the session.
        $relay = self::signRelay($workspace['public_id'], $requestId, now()->getTimestamp() + 600);
        $xml = sprintf(
            '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="%s" Version="2.0" IssueInstant="%s" Destination="%s" AssertionConsumerServiceURL="%s" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"><saml:Issuer>%s</saml:Issuer><samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/></samlp:AuthnRequest>',
            $requestId, gmdate('Y-m-d\TH:i:s\Z'), htmlspecialchars($config['saml']['idp_sso_url'], ENT_XML1), htmlspecialchars(self::acsUrl(), ENT_XML1), htmlspecialchars(self::spEntityId($workspace), ENT_XML1)
        );
        $url = $config['saml']['idp_sso_url'];
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query(['SAMLRequest' => base64_encode(gzdeflate($xml)), 'RelayState' => $relay]);
    }

    public static function signRelay(string $workspacePublicId, string $requestId, int $expires): string
    {
        $payload = "$workspacePublicId.$requestId.$expires";
        return $payload . '.' . substr(hash_hmac('sha256', 'saml-relay|' . $payload, config('app.key')), 0, 32);
    }

    /** @return array{0:string, 1:string} workspace public id and AuthnRequest id */
    public static function verifyRelay(string $relay): array
    {
        $parts = explode('.', $relay);
        if (count($parts) !== 4 || (int) $parts[2] < now()->getTimestamp()
            || !hash_equals(substr(hash_hmac('sha256', "saml-relay|{$parts[0]}.{$parts[1]}.{$parts[2]}", config('app.key')), 0, 32), $parts[3])) {
            throw HttpException::forbidden('This sign-in attempt expired. Start again.');
        }
        return [$parts[0], $parts[1]];
    }

    // ------------------------------------------------------------------ OIDC

    /** @return array{email:string, name:string, subject:string, workspace:array} */
    public function oidcCallback(string $code, string $state): array
    {
        $pending = Session::get('sso');
        Session::forget('sso');
        if (!is_array($pending) || !isset($pending['state']) || !hash_equals($pending['state'], $state) || $pending['exp'] < now()->getTimestamp()) {
            throw HttpException::forbidden('This sign-in attempt expired. Start again.');
        }
        $workspace = WorkspaceService::byPublicId($pending['ws']) ?? throw HttpException::notFound();
        $config = self::config($workspace)['oidc'] ?? throw HttpException::notFound();
        $meta = $this->discover($config['issuer']);
        [$status, $body] = $this->http('POST', $meta['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::oidcRedirectUri(),
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code_verifier' => $pending['verifier'],
        ]);
        $tokens = json_decode($body, true);
        if ($status !== 200 || !is_string($tokens['id_token'] ?? null)) {
            throw new HttpException(502, 'sso_failed', 'The identity provider did not return an ID token.');
        }
        $claims = $this->verifyIdToken($tokens['id_token'], $meta, $config, $pending['nonce']);
        $email = strtolower((string) ($claims['email'] ?? ''));
        if ($email === '' || (isset($claims['email_verified']) && $claims['email_verified'] === false)) {
            throw HttpException::forbidden('Your identity provider did not share a verified email address.');
        }
        return ['email' => $email, 'name' => (string) ($claims['name'] ?? $email), 'subject' => 'oidc|' . $claims['iss'] . '|' . $claims['sub'], 'workspace' => $workspace];
    }

    public function verifyIdToken(string $jwt, array $meta, array $config, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw HttpException::forbidden('Malformed ID token.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64url($h), true);
        $claims = json_decode(self::b64url($p), true);
        if (($header['alg'] ?? '') !== 'RS256' || !is_array($claims)) {
            throw HttpException::forbidden('Unsupported ID token algorithm.');
        }
        [$status, $body] = $this->http('GET', $meta['jwks_uri'], []);
        $keys = json_decode($body, true)['keys'] ?? [];
        $jwk = null;
        foreach ($keys as $key) {
            if (($key['kty'] ?? '') === 'RSA' && (!isset($header['kid']) || ($key['kid'] ?? null) === $header['kid'])) {
                $jwk = $key;
                break;
            }
        }
        if ($status !== 200 || $jwk === null || openssl_verify("$h.$p", self::b64url($s), self::jwkToPem($jwk), OPENSSL_ALGO_SHA256) !== 1) {
            throw HttpException::forbidden('The ID token signature is invalid.');
        }
        $now = now()->getTimestamp();
        $aud = (array) ($claims['aud'] ?? []);
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($config['issuer'], '/')
            || !in_array($config['client_id'], $aud, true)
            || (int) ($claims['exp'] ?? 0) < $now - 60
            || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 60)
            || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))
            || empty($claims['sub'])) {
            throw HttpException::forbidden('The ID token could not be validated.');
        }
        return $claims;
    }

    private function discover(string $issuer): array
    {
        [$status, $body] = $this->http('GET', rtrim($issuer, '/') . '/.well-known/openid-configuration', []);
        $meta = json_decode($body, true);
        if ($status !== 200 || !isset($meta['authorization_endpoint'], $meta['token_endpoint'], $meta['jwks_uri'])) {
            throw new HttpException(502, 'sso_unavailable', 'Could not reach the identity provider.');
        }
        return $meta;
    }

    public static function jwkToPem(array $jwk): string
    {
        $n = self::b64url($jwk['n']);
        $e = self::b64url($jwk['e']);
        $int = static function (string $bytes): string {
            // DER INTEGER: minimal big-endian, with a leading zero byte if the high bit is set.
            $bytes = ltrim($bytes, "\0");
            $bytes = $bytes === '' ? "\0" : $bytes;
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\0" . $bytes;
            }
            return "\x02" . self::derLength(strlen($bytes)) . $bytes;
        };
        $rsa = "\x30" . self::derLength(strlen($int($n) . $int($e))) . $int($n) . $int($e);
        $bitString = "\x03" . self::derLength(strlen($rsa) + 1) . "\0" . $rsa;
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . self::derLength(strlen($algorithm . $bitString)) . $algorithm . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function b64url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    // ------------------------------------------------------------------ SAML

    /** @return array{email:string, name:string, subject:string, workspace:array} */
    public function samlAcs(string $samlResponse, string $relayState): array
    {
        [$workspaceId, $requestId] = self::verifyRelay($relayState);
        $workspace = WorkspaceService::byPublicId($workspaceId) ?? throw HttpException::notFound();
        $config = self::config($workspace)['saml'] ?? throw HttpException::notFound();
        $result = self::validateSamlResponse(
            (string) base64_decode($samlResponse, true),
            $config,
            self::spEntityId($workspace),
            self::acsUrl(),
            $requestId,
        );
        return $result + ['workspace' => $workspace];
    }

    /** @return array{email:string, name:string, subject:string} */
    public static function validateSamlResponse(string $xml, array $config, string $audience, string $acsUrl, ?string $requestId): array
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw HttpException::forbidden('Invalid SAML response.');
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw HttpException::forbidden('Invalid SAML response.');
        }
        $xp = self::xpath($doc);
        $response = $doc->documentElement;
        if ($response->localName !== 'Response' || $response->namespaceURI !== 'urn:oasis:names:tc:SAML:2.0:protocol') {
            throw HttpException::forbidden('Invalid SAML response.');
        }
        if ($xp->query('//saml:EncryptedAssertion')->length > 0) {
            throw HttpException::forbidden('Encrypted SAML assertions are not supported. Disable assertion encryption for SSShare.');
        }
        $status = $xp->query('samlp:Status/samlp:StatusCode', $response)->item(0);
        if (!$status instanceof DOMElement || $status->getAttribute('Value') !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            throw HttpException::forbidden('The identity provider rejected the sign-in.');
        }
        $assertions = $xp->query('//saml:Assertion');
        if ($assertions->length !== 1) {
            throw HttpException::forbidden('The SAML response must contain exactly one assertion.');
        }
        $assertion = $assertions->item(0);

        // The signed element must be the assertion itself, or the Response that directly contains it.
        $signed = null;
        foreach ([$assertion, $response] as $candidate) {
            $sig = $xp->query('ds:Signature', $candidate)->item(0);
            if ($sig instanceof DOMElement) {
                self::verifyXmlSignature($candidate, $sig, $xp, $config['idp_certificate']);
                $signed = $candidate;
                break;
            }
        }
        if ($signed === null || ($signed === $response && $assertion->parentNode !== $response)) {
            throw HttpException::forbidden('The SAML response is not signed.');
        }

        $now = now()->getTimestamp();
        $skew = 120;
        $issuer = trim((string) $xp->query('saml:Issuer', $assertion)->item(0)?->textContent);
        if ($issuer !== $config['idp_entity_id']) {
            throw HttpException::forbidden('Unexpected SAML issuer.');
        }
        $conditions = $xp->query('saml:Conditions', $assertion)->item(0);
        if ($conditions instanceof DOMElement) {
            if ($conditions->hasAttribute('NotBefore') && strtotime($conditions->getAttribute('NotBefore')) > $now + $skew) {
                throw HttpException::forbidden('The SAML assertion is not valid yet.');
            }
            if ($conditions->hasAttribute('NotOnOrAfter') && strtotime($conditions->getAttribute('NotOnOrAfter')) <= $now - $skew) {
                throw HttpException::forbidden('The SAML assertion has expired.');
            }
            $audiences = array_map(static fn ($n) => trim($n->textContent), iterator_to_array($xp->query('saml:AudienceRestriction/saml:Audience', $conditions)));
            if ($audiences !== [] && !in_array($audience, $audiences, true)) {
                throw HttpException::forbidden('The SAML assertion is for a different service.');
            }
        }
        $confirmation = $xp->query('saml:Subject/saml:SubjectConfirmation[@Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"]/saml:SubjectConfirmationData', $assertion)->item(0);
        if (!$confirmation instanceof DOMElement
            || $confirmation->getAttribute('Recipient') !== $acsUrl
            || strtotime($confirmation->getAttribute('NotOnOrAfter')) <= $now - $skew
            || ($requestId !== null && $confirmation->hasAttribute('InResponseTo') && $confirmation->getAttribute('InResponseTo') !== $requestId)) {
            throw HttpException::forbidden('The SAML subject confirmation is invalid.');
        }
        // Each assertion can be used once.
        $assertionId = $assertion->getAttribute('ID');
        try {
            Database::insert('rate_limits', ['bucket' => 'saml-assertion:' . hash('sha256', $issuer . '|' . $assertionId), 'hits' => 1, 'window_start' => db_time()]);
        } catch (\PDOException) {
            throw HttpException::forbidden('This SAML assertion was already used.');
        }

        $nameId = trim((string) $xp->query('saml:Subject/saml:NameID', $assertion)->item(0)?->textContent);
        $attr = static function (array $names) use ($xp, $assertion): ?string {
            foreach ($names as $name) {
                $value = $xp->query('saml:AttributeStatement/saml:Attribute[@Name="' . $name . '"]/saml:AttributeValue', $assertion)->item(0);
                if ($value !== null && trim($value->textContent) !== '') {
                    return trim($value->textContent);
                }
            }
            return null;
        };
        $email = strtolower($attr(['email', 'mail', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress']) ?? $nameId);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::forbidden('The identity provider did not share an email address.');
        }
        $name = $attr(['displayName', 'name', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name']) ?? $email;
        return ['email' => $email, 'name' => $name, 'subject' => 'saml|' . $issuer . '|' . $nameId];
    }

    private static function xpath(DOMDocument $doc): DOMXPath
    {
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xp->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xp->registerNamespace('ec', 'http://www.w3.org/2001/10/xml-exc-c14n#');
        return $xp;
    }

    /** Enveloped XML-DSig with exclusive C14N and RSA-SHA256 (or RSA-SHA1 for legacy IdPs). */
    private static function verifyXmlSignature(DOMElement $element, DOMElement $signature, DOMXPath $xp, string $certificate): void
    {
        $fail = static fn () => throw HttpException::forbidden('The SAML signature is invalid.');
        $signedInfo = $xp->query('ds:SignedInfo', $signature)->item(0) ?? $fail();
        $c14n = $xp->query('ds:CanonicalizationMethod', $signedInfo)->item(0)?->getAttribute('Algorithm');
        if (!in_array($c14n, ['http://www.w3.org/2001/10/xml-exc-c14n#', 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments'], true)) {
            $fail();
        }
        $references = $xp->query('ds:Reference', $signedInfo);
        if ($references->length !== 1) {
            $fail();
        }
        $reference = $references->item(0);
        $id = $element->getAttribute('ID');
        if ($id === '' || $reference->getAttribute('URI') !== '#' . $id) {
            $fail();
        }
        // IDs must be unique in the document, or the reference is ambiguous.
        if ($xp->query('//*[@ID="' . str_replace('"', '', $id) . '"]')->length !== 1) {
            $fail();
        }
        $prefixes = $xp->query('ds:Transforms/ds:Transform/ec:InclusiveNamespaces', $reference)->item(0)?->getAttribute('PrefixList');
        $clone = $element->cloneNode(true);
        foreach ($clone->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Signature' && $child->namespaceURI === 'http://www.w3.org/2000/09/xmldsig#') {
                $clone->removeChild($child);
                break;
            }
        }
        $digestAlg = $xp->query('ds:DigestMethod', $reference)->item(0)?->getAttribute('Algorithm');
        $algo = match ($digestAlg) {
            'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
            'http://www.w3.org/2000/09/xmldsig#sha1' => 'sha1',
            default => $fail(),
        };
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($clone, true));
        $canonical = $doc->documentElement->C14N(true, false, null, $prefixes ? preg_split('/\s+/', trim($prefixes)) : null);
        $digest = base64_encode(hash($algo, $canonical, true));
        if (!hash_equals($digest, trim((string) $xp->query('ds:DigestValue', $reference)->item(0)?->textContent))) {
            $fail();
        }
        $sigAlg = $xp->query('ds:SignatureMethod', $signedInfo)->item(0)?->getAttribute('Algorithm');
        $opensslAlg = match ($sigAlg) {
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
            default => $fail(),
        };
        $signedInfoC14n = $signedInfo->C14N(true, false);
        $value = base64_decode(preg_replace('/\s+/', '', (string) $xp->query('ds:SignatureValue', $signature)->item(0)?->textContent), true);
        $key = openssl_pkey_get_public($certificate);
        if ($value === false || $key === false || openssl_verify($signedInfoC14n, $value, $key, $opensslAlg) !== 1) {
            $fail();
        }
    }

    // ------------------------------------------------------------------ provisioning

    /** Finds or creates the user (just-in-time provisioning) and ensures workspace membership. */
    public function provision(array $identity): array
    {
        $workspace = $identity['workspace'];
        $config = self::config($workspace);
        $domain = substr(strrchr($identity['email'], '@') ?: '', 1);
        if (!in_array($domain, $config['domains'] ?? [], true)) {
            throw HttpException::forbidden('Your email domain is not allowed to sign in to this workspace.');
        }
        $user = Database::first('SELECT * FROM users WHERE sso_subject = ?', [$identity['subject']])
            ?? Database::first('SELECT * FROM users WHERE email = ?', [$identity['email']]);
        if ($user === null) {
            $user = (new UserService())->register($identity['email'], bin2hex(random_bytes(24)), mb_substr($identity['name'], 0, 80) ?: $identity['email']);
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'account_suspended', 'This account has been suspended.');
        }
        Database::update('users', ['sso_subject' => $identity['subject'], 'current_workspace_id' => $workspace['id'], 'last_login_at' => db_time()], 'id = ?', [$user['id']]);
        Database::execute(
            'INSERT IGNORE INTO workspace_members (workspace_id, user_id, role, created_at) VALUES (?, ?, ?, ?)',
            [$workspace['id'], $user['id'], $config['default_role'] ?? 'member', db_time()]
        );
        WorkspaceService::audit($workspace, 'workspace.sso_login', ['user' => $user['uuid']]);
        WorkspaceService::flush();
        return Database::first('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }

    public static function spMetadata(array $workspace): string
    {
        return sprintf(
            '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="%s"><md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"><md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat><md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="%s" index="0"/></md:SPSSODescriptor></md:EntityDescriptor>',
            htmlspecialchars(self::spEntityId($workspace), ENT_XML1), htmlspecialchars(self::acsUrl(), ENT_XML1)
        );
    }

    /** @return array{0:int, 1:string} */
    private function http(string $method, string $url, array $form): array
    {
        if (self::$http !== null) {
            return (self::$http)($method, $url, $form);
        }
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $body];
    }
}
