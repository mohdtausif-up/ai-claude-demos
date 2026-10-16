<?php

declare(strict_types=1);

namespace App\Support;

/** Non-sequential identifiers for every externally visible object. */
final class Ids
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function publicId(int $length = 10): string
    {
        $id = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $id .= self::ALPHABET[random_int(0, $max)];
        }
        return $id;
    }

    /** URL-safe secret token. */
    public static function token(int $bytes = 24): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
