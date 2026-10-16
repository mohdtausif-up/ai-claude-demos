<?php

declare(strict_types=1);

use App\Core\Csp;
use App\Core\Env;
use App\Core\Lang;
use App\Core\Session;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

/** HTML-escape for text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    return rtrim((string) env('APP_URL', 'http://localhost:8480'), '/') . '/' . ltrim($path, '/');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    static $versions = [];
    // Cache-busting query string from the file's mtime; memoised so each asset is stat()ed once per process.
    $mtime = $versions[$path] ??= @filemtime(BASE_PATH . '/public/assets/' . ltrim($path, '/')) ?: 1;
    return '/assets/' . ltrim($path, '/') . '?v=' . substr((string) $mtime, -6);
}

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csp_nonce(): string
{
    return Csp::nonce();
}

/** Value submitted on the previous request, for re-populating a form after a validation error. */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::peek('_old', []);
    return $old[$key] ?? $default;
}

function field_errors(): array
{
    return Session::peek('_errors', []);
}

function field_error(string $key): string
{
    $errs = field_errors();
    return isset($errs[$key][0]) ? (string) $errs[$key][0] : '';
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}

function is_uuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $s);
}

function base64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function utcnow(?int $offsetSeconds = null): string
{
    return gmdate('Y-m-d H:i:s', time() + ($offsetSeconds ?? 0));
}

/** Convert a local wall-clock string in $tz (as typed in a datetime-local input) to a UTC DB string. */
function to_utc(string $local, string $tz): string
{
    $dt = new DateTimeImmutable(str_replace('T', ' ', $local), new DateTimeZone($tz));
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function from_utc(string $utc, string $tz): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz));
}

/** Value for a datetime-local input, in the event's timezone. */
function to_local_input(?string $utc, string $tz): string
{
    return $utc ? from_utc($utc, $tz)->format('Y-m-d\TH:i') : '';
}

function fmt_dt(?string $utc, string $tz, string $fmt = 'D, j M Y \a\t g:i A T'): string
{
    return $utc ? Lang::date(from_utc($utc, $tz), $fmt) : '';
}

function t(string $key, array $vars = []): string
{
    return Lang::get($key, $vars);
}

function json_out(mixed $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}

function str_limit(string $s, int $n): string
{
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/** Prefix cells a spreadsheet would interpret as a formula so exports cannot carry CSV injection. */
function csv_safe(mixed $v): string
{
    $s = (string) $v;
    return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Checkbox state: after a failed submit use what was posted, otherwise the default/stored value. */
function form_bool(string $key, bool $default): bool
{
    $old = Session::peek('_old');
    return is_array($old) && $old ? !empty($old[$key]) : $default;
}

function checked(bool $on): string
{
    return $on ? ' checked' : '';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}