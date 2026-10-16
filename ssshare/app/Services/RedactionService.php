<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Finds sensitive data to suggest for redaction. Nothing is changed automatically:
 * results are always shown on a review screen where the user accepts or rejects each one.
 */
final class RedactionService
{
    public const KINDS = [
        'email' => 'Email address',
        'phone' => 'Phone number',
        'token' => 'API token or secret',
        'password' => 'Password',
        'card' => 'Payment card number',
        'ip' => 'IP address',
        'name' => 'Name in a notification',
        'qr' => 'QR code',
        'face' => 'Face',
    ];

    private const TOKEN_PATTERNS = [
        '/\bAKIA[0-9A-Z]{16}\b/',                                   // AWS access key
        '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',                         // GitHub
        '/\bxox[abposr]-[A-Za-z0-9-]{10,}\b/',                      // Slack
        '/\bsk-(?:ant-|proj-|live_|test_)?[A-Za-z0-9_-]{20,}\b/',   // OpenAI/Anthropic/Stripe style
        '/\b(?:sss|pk|rk)_(?:live|test)_[A-Za-z0-9_-]{16,}\b/',     // SSShare / Stripe keys
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', // JWT
        '/\bAIza[0-9A-Za-z_-]{35}\b/',                              // Google API key
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    ];

    /**
     * Matches in plain text.
     *
     * @return list<array{kind:string, start:int, length:int, preview:string}>
     */
    public static function scanText(string $text): array
    {
        $found = [];
        $add = static function (string $kind, int $offset, string $value) use (&$found, $text) {
            foreach ($found as $f) {
                if ($offset < $f['_byte'] + $f['_blen'] && $offset + strlen($value) > $f['_byte']) {
                    return; // overlapping a stronger match
                }
            }
            $found[] = [
                '_byte' => $offset,
                '_blen' => strlen($value),
                'kind' => $kind,
                'start' => mb_strlen(substr($text, 0, $offset)),
                'length' => mb_strlen($value),
                'preview' => self::mask($value),
            ];
        };

        foreach (self::TOKEN_PATTERNS as $pattern) {
            self::each($pattern, $text, static fn ($v, $o) => $add('token', $o, $v));
        }
        self::each('/(?i)\b(?:pass(?:word|wd)?|pwd|secret|api[_-]?key|token|auth)\b\s*[:=]\s*["\']?([^\s"\']{4,})/', $text, static fn ($v, $o) => $add('password', $o, $v), 1);
        // High-entropy strings that look like secrets.
        self::each('/\b[A-Za-z0-9+\/_-]{32,}={0,2}\b/', $text, static function ($v, $o) use ($add) {
            if (self::entropy($v) > 4.0 && preg_match('/\d/', $v) && preg_match('/[A-Za-z]/', $v)) {
                $add('token', $o, $v);
            }
        });
        self::each('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, static fn ($v, $o) => $add('email', $o, $v));
        self::each('/\b(?:\d[ -]?){13,19}\b/', $text, static function ($v, $o) use ($add) {
            if (self::luhn(preg_replace('/\D/', '', $v))) {
                $add('card', $o, $v);
            }
        });
        self::each('/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/', $text, static fn ($v, $o) => $add('ip', $o, $v));
        self::each('/\b(?:[0-9a-fA-F]{1,4}:){4,7}[0-9a-fA-F]{1,4}\b/', $text, static fn ($v, $o) => $add('ip', $o, $v));
        self::each('/(?<![\w.])\+?\(?\d{1,3}\)?[\s.-]?\(?\d{2,4}\)?[\s.-]?\d{3,4}[\s.-]?\d{3,4}(?![\w.])/', $text, static function ($v, $o) use ($add) {
            $digits = strlen(preg_replace('/\D/', '', $v));
            if ($digits >= 9 && $digits <= 15) {
                $add('phone', $o, $v);
            }
        });
        // Names in notification banners, e.g. "Jane Doe: running late" or "Message from Jane Doe".
        self::each('/(?m)^(?:From:\s*|Message from\s+)?([A-Z][a-z]+(?: [A-Z][a-z]+){1,2})(?=\s*(?:[:·•\-]|\d{1,2}:\d{2}|now\b|sent\b))/', $text, static fn ($v, $o) => $add('name', $o, $v), 1);

        usort($found, static fn ($a, $b) => $a['start'] <=> $b['start']);
        return array_map(static fn ($f) => array_diff_key($f, ['_byte' => 0, '_blen' => 0]), $found);
    }

    /** Replaces accepted matches (by index) with block characters. */
    public static function applyToText(string $text, array $matches, array $acceptedIndexes): string
    {
        $accepted = array_values(array_intersect_key($matches, array_flip($acceptedIndexes)));
        usort($accepted, static fn ($a, $b) => $b['start'] <=> $a['start']);
        foreach ($accepted as $m) {
            $text = mb_substr($text, 0, $m['start']) . str_repeat('█', $m['length']) . mb_substr($text, $m['start'] + $m['length']);
        }
        return $text;
    }

    /**
     * Suggestions for an image: OCR text regions matching sensitive patterns, as boxes
     * normalised to 0..1. The image is processed in memory and never stored.
     *
     * @return list<array{kind:string, label:string, preview:string, x:float, y:float, w:float, h:float}>
     */
    public static function scanImage(string $bytes): array
    {
        $ocr = (new OcrService())->recognize($bytes);
        $W = max(1, $ocr['width']);
        $H = max(1, $ocr['height']);
        $boxes = [];
        foreach ($ocr['lines'] as $line) {
            foreach (self::scanText($line['text']) as $match) {
                // Map the character range of the match back to the words that contain it.
                $pos = 0;
                $hit = [];
                foreach ($line['words'] as $word) {
                    $start = $pos;
                    $end = $pos + mb_strlen($word['text']);
                    if ($end > $match['start'] && $start < $match['start'] + $match['length']) {
                        $hit[] = $word;
                    }
                    $pos = $end + 1;
                }
                if ($hit === []) {
                    continue;
                }
                $x1 = min(array_column($hit, 'x'));
                $y1 = min(array_column($hit, 'y'));
                $x2 = max(array_map(static fn ($w) => $w['x'] + $w['w'], $hit));
                $y2 = max(array_map(static fn ($w) => $w['y'] + $w['h'], $hit));
                $padX = 4;
                $padY = 3;
                $boxes[] = [
                    'kind' => $match['kind'],
                    'label' => self::KINDS[$match['kind']],
                    'preview' => $match['preview'],
                    'x' => max(0, ($x1 - $padX) / $W),
                    'y' => max(0, ($y1 - $padY) / $H),
                    'w' => min(1, ($x2 - $x1 + 2 * $padX) / $W),
                    'h' => min(1, ($y2 - $y1 + 2 * $padY) / $H),
                ];
            }
        }
        return $boxes;
    }

    private static function each(string $pattern, string $text, callable $fn, int $group = 0): void
    {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[$group] as [$value, $offset]) {
                if ($value !== '' && $offset >= 0) {
                    $fn($value, $offset);
                }
            }
        }
    }

    public static function mask(string $value): string
    {
        $len = mb_strlen($value);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }
        return mb_substr($value, 0, 2) . str_repeat('•', min(12, $len - 4)) . mb_substr($value, -2);
    }

    public static function luhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19 || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$len - 1 - $i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    private static function entropy(string $s): float
    {
        $len = strlen($s);
        $entropy = 0.0;
        foreach (count_chars($s, 1) as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        return $entropy;
    }
}
