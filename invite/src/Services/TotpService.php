<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) for authenticator apps, with encrypted secrets and hashed single-use recovery codes. */
final class TotpService
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '')) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secretBin, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secretBin, true);
        $o = ord($hash[19]) & 0xf;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public function uri(string $secretB32, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode('Invite.com:' . $account) . '?secret=' . $secretB32 . '&issuer=Invite.com&algorithm=SHA1&digits=6&period=30';
    }

    private function key(): string
    {
        return hash('sha256', 'mfa|' . env('APP_KEY', ''), true);
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    private function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true) ?: '';
        return (string) openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    }

    public function isEnabled(int $userId): bool
    {
        return (bool) Db::value('SELECT 1 FROM user_mfa WHERE user_id = ? AND enabled_at IS NOT NULL', [$userId]);
    }

    /** Start enrolment: store an (unconfirmed) secret and return it for display. */
    public function begin(int $userId): string
    {
        $secret = $this->newSecret();
        Db::run('INSERT INTO user_mfa (user_id, secret_enc) VALUES (?, ?) ON DUPLICATE KEY UPDATE secret_enc = VALUES(secret_enc), enabled_at = NULL, recovery_hashes = NULL, last_step = NULL', [$userId, $this->encrypt($secret)]);
        return $secret;
    }

    public function pendingSecret(int $userId): ?string
    {
        $row = Db::one('SELECT secret_enc FROM user_mfa WHERE user_id = ? AND enabled_at IS NULL', [$userId]);
        return $row ? $this->decrypt($row['secret_enc']) : null;
    }

    /** Verify a code (±1 step) and refuse a step that was already used. */
    public function verify(int $userId, string $code): bool
    {
        $row = Db::one('SELECT secret_enc, last_step FROM user_mfa WHERE user_id = ?', [$userId]);
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (!$row || strlen($code) !== 6) {
            return false;
        }
        $secret = self::base32Decode($this->decrypt($row['secret_enc']));
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if (hash_equals(self::code($secret, $step), $code)) {
                if ($row['last_step'] !== null && $step <= (int) $row['last_step']) {
                    return false; // replay
                }
                Db::exec('UPDATE user_mfa SET last_step = ? WHERE user_id = ?', [$step, $userId]);
                return true;
            }
        }
        return false;
    }

    /** Confirm enrolment with a first valid code. @return list<string>|null recovery codes (shown once) */
    public function confirm(int $userId, string $code): ?array
    {
        if ($this->pendingSecret($userId) === null || !$this->verify($userId, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        Db::update('user_mfa', ['enabled_at' => utcnow(), 'recovery_hashes' => json_out(array_map([TokenService::class, 'hash'], $codes))], ['user_id' => $userId]);
        return $codes;
    }

    /** @return list<string> */
    public function newRecoveryCodes(): array
    {
        $out = [];
        for ($i = 0; $i < 8; $i++) {
            $h = bin2hex(random_bytes(5));
            $out[] = substr($h, 0, 5) . '-' . substr($h, 5);
        }
        return $out;
    }

    public function regenerateRecovery(int $userId): array
    {
        $codes = $this->newRecoveryCodes();
        Db::update('user_mfa', ['recovery_hashes' => json_out(array_map([TokenService::class, 'hash'], $codes))], ['user_id' => $userId]);
        return $codes;
    }

    /** One-time recovery code. */
    public function useRecovery(int $userId, string $code): bool
    {
        $hashes = json_decode((string) Db::value('SELECT recovery_hashes FROM user_mfa WHERE user_id = ?', [$userId]), true) ?: [];
        $h = TokenService::hash(strtolower(trim($code)));
        $i = array_search($h, $hashes, true);
        if ($i === false) {
            return false;
        }
        unset($hashes[$i]);
        Db::update('user_mfa', ['recovery_hashes' => json_out(array_values($hashes))], ['user_id' => $userId]);
        return true;
    }

    public function recoveryLeft(int $userId): int
    {
        return count(json_decode((string) Db::value('SELECT recovery_hashes FROM user_mfa WHERE user_id = ?', [$userId]), true) ?: []);
    }

    public function disable(int $userId): void
    {
        Db::exec('DELETE FROM user_mfa WHERE user_id = ?', [$userId]);
    }
}