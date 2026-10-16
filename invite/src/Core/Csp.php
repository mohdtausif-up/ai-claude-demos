<?php

declare(strict_types=1);

namespace App\Core;

final class Csp
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Strict policy: scripts and styles only from this origin or with the per-request nonce.
     * No inline event handlers, no style attributes, no third-party origins.
     */
    public static function header(): string
    {
        $n = "'nonce-" . self::nonce() . "'";
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' $n",
            "style-src 'self' $n",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
