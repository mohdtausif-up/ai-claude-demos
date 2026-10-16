<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;

/**
 * Zero-knowledge shares. The browser encrypts with AES-256-GCM (Web Crypto) and keeps the
 * key in the URL fragment (#k=...), which is never sent to the server. The server stores
 * only ciphertext plus non-secret parameters, so it cannot preview, scan or recover content.
 */
final class EncryptionService
{
    public static function validateMeta(array $meta): array
    {
        $errors = [];
        $b64 = static fn ($v, int $min, int $max) => is_string($v) && preg_match('/^[A-Za-z0-9_-]+$/', $v) && strlen($v) >= $min && strlen($v) <= $max;
        if (($meta['v'] ?? null) !== 1 && ($meta['v'] ?? null) !== '1') {
            $errors['meta'] = 'Unsupported encryption format.';
        }
        if (($meta['alg'] ?? '') !== 'AES-GCM') {
            $errors['meta'] = 'Only AES-GCM is supported.';
        }
        if (!$b64($meta['iv'] ?? null, 16, 16)) {
            $errors['meta'] = 'Invalid IV.';
        }
        if (!$b64($meta['header'] ?? null, 20, 2000)) {
            $errors['meta'] = 'Invalid encrypted header.';
        }
        if (!$b64($meta['header_iv'] ?? null, 16, 16)) {
            $errors['meta'] = 'Invalid header IV.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        // The encrypted header (file name, type, size) is opaque to the server.
        return ['v' => 1, 'alg' => 'AES-GCM', 'iv' => $meta['iv'], 'header' => $meta['header'], 'header_iv' => $meta['header_iv']];
    }
}
