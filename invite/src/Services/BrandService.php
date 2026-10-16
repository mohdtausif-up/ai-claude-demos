<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** White-label brand kit per workspace, sanitised agency custom CSS, and custom-domain verification. */
final class BrandService
{
    public function __construct(private PlanService $plans, private ImageService $images)
    {
    }

    public function forWorkspace(int $workspaceId): array
    {
        $w = Db::one('SELECT * FROM workspaces WHERE id = ?', [$workspaceId]) ?? [];
        return [
            'name' => $w['brand_name'] ?? null, 'primary' => $w['brand_primary'] ?? null, 'accent' => $w['brand_accent'] ?? null,
            'logo' => !empty($w['brand_logo_path']) ? '/uploads/' . $w['brand_logo_path'] : null,
            'hide_branding' => (bool) ($w['hide_branding'] ?? false) && $this->plans->allows($w, 'remove_branding'),
            'css' => !empty($w['custom_css']) && $this->plans->allows($w, 'custom_css') ? $this->sanitizeCss((string) $w['custom_css']) : '',
            'domain' => !empty($w['domain_verified_at']) && $this->plans->allows($w, 'custom_domain') ? $w['custom_domain'] : null,
        ];
    }

    /** @param array{brand_name?:?string,brand_primary?:?string,brand_accent?:?string,hide_branding?:bool,custom_css?:?string} $d */
    public function save(array $workspace, array $d, ?array $logoFile): void
    {
        $this->plans->require($workspace, 'brand_kit');
        $hex = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
        $row = [
            'brand_name' => ($d['brand_name'] ?? '') !== '' ? mb_substr((string) $d['brand_name'], 0, 100) : null,
            'brand_primary' => $hex($d['brand_primary'] ?? null), 'brand_accent' => $hex($d['brand_accent'] ?? null),
            'hide_branding' => !empty($d['hide_branding']) ? 1 : 0,
        ];
        if (!empty($d['hide_branding'])) {
            $this->plans->require($workspace, 'remove_branding');
        }
        if (($d['custom_css'] ?? '') !== '') {
            $this->plans->require($workspace, 'custom_css');
            $row['custom_css'] = mb_substr((string) $d['custom_css'], 0, 5000);
        } else {
            $row['custom_css'] = null;
        }
        if ($logoFile) {
            $res = $this->images->store(['uuid' => 'brand-' . $workspace['uuid'], 'workspace_id' => $workspace['id'], 'id' => null], null, $logoFile, 'logo');
            $row['brand_logo_path'] = substr($res['url'], strlen('/uploads/'));
        }
        Db::update('workspaces', $row, ['id' => $workspace['id']]);
    }

    /**
     * Keep only simple "selector { property: value }" rules, scoped under the invitation page. No @-rules, url(), imports,
     * expressions, escapes or markup. What survives cannot load anything or break out of the <style> element.
     */
    public function sanitizeCss(string $css): string
    {
        $css = preg_replace(['#/\*.*?\*/#s', '/@[^{};]*;/'], '', $css) ?? '';
        $out = [];
        if (!preg_match_all('/([^{}@]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
            return '';
        }
        foreach (array_slice($rules, 0, 60) as [, $selector, $body]) {
            $sels = [];
            foreach (explode(',', $selector) as $s) {
                $s = trim($s);
                if ($s !== '' && preg_match('/^[a-zA-Z0-9\s.#_\-\[\]=">+~*:()]+$/', $s) && !preg_match('/\bscript\b|:has\(|\\\\/', $s)) {
                    $sels[] = 'body.invitation ' . $s;
                }
            }
            $decls = [];
            foreach (explode(';', $body) as $decl) {
                if (!str_contains($decl, ':')) {
                    continue;
                }
                [$prop, $val] = array_map('trim', explode(':', $decl, 2));
                if (!preg_match('/^[a-z-]{2,40}$/', $prop) || preg_match('/url\s*\(|expression|javascript|behavior|-moz-binding|@|\\\\|[<>{}]|image-set|var\s*\(--inv/i', $val) || $val === '' || strlen($val) > 200) {
                    continue;
                }
                if ($prop === 'position' && preg_match('/fixed|sticky/i', $val)) {
                    continue; // no clickjacking-style overlays
                }
                $decls[] = $prop . ':' . $val;
            }
            if ($sels && $decls) {
                $out[] = implode(',', $sels) . '{' . implode(';', $decls) . '}';
            }
        }
        return implode("\n", $out);
    }

    // ---- custom domains ----------------------------------------------------------------------------------

    public function setDomain(array $workspace, string $domain): string
    {
        $this->plans->require($workspace, 'custom_domain');
        $domain = strtolower(trim($domain));
        if (!preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain)) {
            throw new ValidationException(['domain' => ['Enter a domain like rsvp.yourcompany.com.']]);
        }
        $host = strtolower((string) parse_url((string) env('APP_URL'), PHP_URL_HOST));
        if ($domain === $host || str_ends_with($domain, '.' . $host)) {
            throw new ValidationException(['domain' => ['That domain belongs to Invite.com.']]);
        }
        if (Db::value('SELECT 1 FROM workspaces WHERE custom_domain = ? AND id <> ?', [$domain, $workspace['id']])) {
            throw new ValidationException(['domain' => ['That domain is already used by another workspace.']]);
        }
        $token = bin2hex(random_bytes(16));
        Db::update('workspaces', ['custom_domain' => $domain, 'domain_token' => $token, 'domain_verified_at' => null], ['id' => $workspace['id']]);
        return $token;
    }

    /** TXT record _invite-verify.<domain> must contain the token. @param callable|null $resolver test hook returning list of TXT strings */
    public function verifyDomain(array $workspace, ?callable $resolver = null): bool
    {
        if (empty($workspace['custom_domain']) || empty($workspace['domain_token'])) {
            return false;
        }
        $name = '_invite-verify.' . $workspace['custom_domain'];
        $txt = $resolver ? $resolver($name) : array_column(@dns_get_record($name, DNS_TXT) ?: [], 'txt');
        foreach ($txt as $t) {
            if (hash_equals((string) $workspace['domain_token'], trim((string) $t))) {
                Db::update('workspaces', ['domain_verified_at' => utcnow()], ['id' => $workspace['id']]);
                return true;
            }
        }
        return false;
    }

    public function removeDomain(array $workspace): void
    {
        Db::update('workspaces', ['custom_domain' => null, 'domain_token' => null, 'domain_verified_at' => null], ['id' => $workspace['id']]);
    }

    /** @return array<string,mixed>|null the workspace that owns a verified custom domain */
    public function workspaceForHost(string $host): ?array
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host) ?? '');
        if ($host === '') {
            return null;
        }
        $w = Db::one('SELECT * FROM workspaces WHERE custom_domain = ? AND domain_verified_at IS NOT NULL', [$host]);
        return $w && $this->plans->allows($w, 'custom_domain') ? $w : null;
    }
}