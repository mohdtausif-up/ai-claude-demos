<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Short-lived signed URLs for private media. Raw bytes for protected content are only
 * reachable through a signature that was issued after an authorised share-page view.
 */
final class UrlSigningService
{
    public static function rawUrl(string $linkPublicId, int $fileId, string $ext, string $purpose = 'view', ?int $ttl = null): string
    {
        $expires = now()->getTimestamp() + ($ttl ?? (int) config('app.security.signed_url_ttl'));
        $signature = self::signature($linkPublicId, $fileId, $purpose, $expires);
        $query = http_build_query(['f' => $fileId, 'e' => $expires, 'p' => $purpose, 's' => $signature]);
        return "/r/{$linkPublicId}.{$ext}?{$query}";
    }

    public static function verify(string $linkPublicId, int $fileId, string $purpose, int $expires, string $signature): bool
    {
        if ($expires < now()->getTimestamp() || !in_array($purpose, ['view', 'download', 'owner', 'image'], true)) {
            return false;
        }
        return hash_equals(self::signature($linkPublicId, $fileId, $purpose, $expires), $signature);
    }

    private static function signature(string $linkPublicId, int $fileId, string $purpose, int $expires): string
    {
        $payload = implode('|', ['raw', $linkPublicId, $fileId, $purpose, $expires]);
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, config('app.key'), true)), '+/', '-_'), '=');
    }
}
