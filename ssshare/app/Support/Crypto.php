<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Authenticated encryption (AES-256-GCM) for sensitive database fields such as
 * webhook secrets, TOTP seeds and SSO client secrets. The key is derived from APP_KEY.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private static function key(string $purpose): string
    {
        return hash_hkdf('sha256', (string) config('app.key'), 32, 'ssshare-field-' . $purpose);
    }

    public static function encrypt(string $plaintext, string $purpose = 'field'): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded, string $purpose = 'field'): string
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Value is not encrypted');
        }
        $raw = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Malformed ciphertext');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }
        return $plain;
    }
}
