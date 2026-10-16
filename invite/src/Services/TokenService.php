<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Stateless signed tokens for guest links, QR passes and unsubscribe links.
 *
 * Format: <uuid:22 base64url>.<version base36>.<hmac:22 base64url>. Nothing reversible is stored in the database:
 * the host can always regenerate a guest's link, and bumping event_guests.token_version revokes every old one.
 * Types are domain-separated so a QR token cannot be replayed as a guest link.
 */
final class TokenService
{
    public const GUEST = 'g';
    public const QR = 'q';
    public const UNSUB = 'u';

    private function sign(string $type, string $uuid, int $version): string
    {
        $key = (string) env('APP_KEY', '');
        if (strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY must be set (at least 32 characters).');
        }
        return substr(hash_hmac('sha256', "$type|$uuid|$version", $key, true), 0, 16);
    }

    public function make(string $type, string $uuid, int $version): string
    {
        $raw = (string) hex2bin(str_replace('-', '', $uuid));
        return base64url($raw) . '.' . base_convert((string) $version, 10, 36) . '.' . base64url($this->sign($type, $uuid, $version));
    }

    /** @return array{uuid:string,version:int}|null null when malformed or the signature does not verify */
    public function parse(string $type, string $token): ?array
    {
        if (strlen($token) > 80 || !preg_match('/^([A-Za-z0-9_-]{22})\.([0-9a-z]{1,4})\.([A-Za-z0-9_-]{22})$/', $token, $m)) {
            return null;
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/') . '==', true);
        if ($raw === false || strlen($raw) !== 16) {
            return null;
        }
        $h = bin2hex($raw);
        $uuid = sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
        $version = (int) base_convert($m[2], 36, 10);
        $expected = base64url($this->sign($type, $uuid, $version));
        return hash_equals($expected, $m[3]) ? ['uuid' => $uuid, 'version' => $version] : null;
    }

    /** Random single-use token for emails (verify / reset / magic login). Only its hash is persisted. */
    public static function randomToken(): string
    {
        return base64url(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
