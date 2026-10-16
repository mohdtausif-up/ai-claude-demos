<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Crypto;
use App\Support\Database;
use App\Support\HttpException;

/** Two-factor authentication: RFC 6238 TOTP (30 s, 6 digits, SHA-1) plus one-time recovery codes. */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= self::ALPHABET[ord($byte) & 31];
        }
        return $secret;
    }

    public static function provisioningUri(string $secret, string $email): string
    {
        return 'otpauth://totp/' . rawurlencode('SSShare:' . $email) . '?' . http_build_query([
            'secret' => $secret, 'issuer' => 'SSShare', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
        ]);
    }

    public static function code(string $secret, int $timestep): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $timestep), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Verifies a code within ±1 step and rejects reuse of an already-accepted step. */
    public static function verify(string $secret, string $code, ?int $userId = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $step = intdiv(now()->getTimestamp(), 30);
        foreach ([0, -1, 1] as $drift) {
            if (hash_equals(self::code($secret, $step + $drift), $code)) {
                if ($userId !== null) {
                    try {
                        Database::insert('rate_limits', ['bucket' => "totp-used:$userId:" . ($step + $drift), 'hits' => 1, 'window_start' => db_time()]);
                    } catch (\PDOException) {
                        return false; // replay
                    }
                }
                return true;
            }
        }
        return false;
    }

    /** Step 1: create a pending secret (not active until confirmed with a code). */
    public function begin(array $user): string
    {
        $secret = self::generateSecret();
        Database::update('users', ['totp_secret' => Crypto::encrypt($secret, 'totp'), 'totp_enabled_at' => null], 'id = ?', [$user['id']]);
        return $secret;
    }

    /** Step 2: confirm the first code; returns plaintext recovery codes (shown once). */
    public function confirm(array $user, string $code): array
    {
        $fresh = Database::first('SELECT totp_secret FROM users WHERE id = ?', [$user['id']]);
        if (empty($fresh['totp_secret'])) {
            throw new HttpException(409, 'invalid_state', 'Start two-factor setup first.');
        }
        if (!self::verify(Crypto::decrypt($fresh['totp_secret'], 'totp'), $code, (int) $user['id'])) {
            throw HttpException::validation(['code' => 'That code is not valid. Check your device clock and try again.']);
        }
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtolower(substr(strtr(base64_encode(random_bytes(8)), '+/=', 'xyz'), 0, 5) . '-' . substr(strtr(base64_encode(random_bytes(8)), '+/=', 'xyz'), 0, 5));
        }
        Database::update('users', [
            'totp_enabled_at' => db_time(),
            'recovery_codes' => json_encode(array_map(static fn ($c) => hash('sha256', $c), $codes)),
        ], 'id = ?', [$user['id']]);
        AuditLogService::record('user.2fa_enabled', 'user', $user['uuid']);
        return $codes;
    }

    public function disable(array $user): void
    {
        Database::update('users', ['totp_secret' => null, 'totp_enabled_at' => null, 'recovery_codes' => null], 'id = ?', [$user['id']]);
        AuditLogService::record('user.2fa_disabled', 'user', $user['uuid']);
    }

    /** Verifies a TOTP code or consumes a recovery code. */
    public function check(array $user, string $input): bool
    {
        if (empty($user['totp_enabled_at']) || empty($user['totp_secret'])) {
            return true;
        }
        if (self::verify(Crypto::decrypt($user['totp_secret'], 'totp'), $input, (int) $user['id'])) {
            return true;
        }
        $hash = hash('sha256', strtolower(trim($input)));
        $codes = json_decode((string) $user['recovery_codes'], true) ?: [];
        $index = array_search($hash, $codes, true);
        if ($index === false) {
            return false;
        }
        unset($codes[$index]);
        Database::update('users', ['recovery_codes' => json_encode(array_values($codes))], 'id = ?', [$user['id']]);
        AuditLogService::record('user.recovery_code_used', 'user', $user['uuid'], ['remaining' => count($codes)]);
        return true;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
