<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;

/** Renders a text or code snippet as a styled, shareable PNG ("text-to-image"). */
final class TextImageService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
    private const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';
    private const MAX_LINES = 200;
    private const MAX_COLUMNS = 120;

    private const THEMES = [
        'light' => ['bg' => [247, 248, 251], 'card' => [255, 255, 255], 'text' => [23, 25, 39], 'muted' => [105, 112, 131],
            'k' => [124, 58, 237], 's' => [15, 123, 79], 'n' => [180, 83, 9], 'c' => [107, 114, 128], 'border' => [226, 228, 236]],
        'dark' => ['bg' => [14, 15, 26], 'card' => [23, 25, 39], 'text' => [238, 240, 248], 'muted' => [139, 145, 168],
            'k' => [196, 165, 255], 's' => [126, 224, 168], 'n' => [246, 193, 119], 'c' => [139, 145, 168], 'border' => [43, 47, 68]],
    ];

    private const KEYWORDS = 'abstract and as async await break case catch class const continue def default do elif else enum except export extends false final finally fn for foreach from func function go if impl import in interface let match namespace new nil none null package private protected public pub return self static struct switch this throw true try type use var void while with yield';

    public function render(string $text, string $language = 'plaintext', string $theme = 'dark', ?string $title = null): string
    {
        if (!is_file(self::FONT)) {
            throw new HttpException(503, 'font_missing', 'Text-to-image is unavailable on this server.');
        }
        $colors = self::THEMES[$theme] ?? self::THEMES['dark'];
        $lines = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text));
        $wrapped = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            foreach (mb_str_split($line === '' ? ' ' : $line, self::MAX_COLUMNS) as $piece) {
                $wrapped[] = $piece;
            }
        }
        if (count($lines) > self::MAX_LINES) {
            $wrapped[] = '… ' . (count($lines) - self::MAX_LINES) . ' more lines';
        }

        $size = 13;
        $box = imagettfbbox($size, 0, self::FONT, 'M');
        $charW = abs($box[2] - $box[0]) + 0.6;
        $lineH = 22;
        $gutter = (int) ceil(strlen((string) count($wrapped)) * $charW + 24);
        $longest = max(array_map('mb_strlen', $wrapped));
        $pad = 48;
        $chrome = 44;
        $width = (int) min(1800, max(560, $pad * 2 + $gutter + $longest * $charW + 32));
        $height = (int) ($pad * 2 + $chrome + count($wrapped) * $lineH + 24);

        $im = imagecreatetruecolor($width, $height);
        $c = static fn (array $rgb) => imagecolorallocate($im, ...$rgb);
        imagefill($im, 0, 0, $c($colors['bg']));
        self::roundedRect($im, $pad, $pad, $width - $pad, $height - $pad, 16, $c($colors['card']));
        // Window chrome.
        foreach ([[255, 95, 87], [254, 188, 46], [40, 200, 64]] as $i => $dot) {
            imagefilledellipse($im, $pad + 24 + $i * 20, $pad + 22, 12, 12, $c($dot));
        }
        if ($title !== null && $title !== '') {
            imagettftext($im, 11, 0, $pad + 96, $pad + 27, $c($colors['muted']), self::FONT, mb_substr($title, 0, 80));
        }
        imageline($im, $pad, $pad + $chrome, $width - $pad - 1, $pad + $chrome, $c($colors['border']));

        $y = $pad + $chrome + 24 + $size;
        $x0 = $pad + 16;
        foreach ($wrapped as $n => $line) {
            imagettftext($im, $size, 0, (int) $x0, (int) $y, $c($colors['muted']), self::FONT, str_pad((string) ($n + 1), strlen((string) count($wrapped)), ' ', STR_PAD_LEFT));
            $x = $x0 + $gutter;
            foreach (self::tokens($line, $language) as [$type, $value]) {
                $font = $type === 'k' ? self::FONT_BOLD : self::FONT;
                imagettftext($im, $size, 0, (int) round($x), (int) $y, $c($colors[$type] ?? $colors['text']), is_file($font) ? $font : self::FONT, $value);
                $x += mb_strlen($value) * $charW;
            }
            $y += $lineH;
        }
        imagettftext($im, 9, 0, $width - $pad - 90, $height - 18, $c($colors['muted']), self::FONT, 'ssshare');

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /** Single-line tokenizer (comments, strings, numbers, keywords). */
    private static function tokens(string $line, string $language): array
    {
        if (in_array($language, ['plaintext', 'markdown', 'log'], true)) {
            return [[null, $line]];
        }
        $keywords = array_flip(explode(' ', self::KEYWORDS));
        $hashComment = in_array($language, ['python', 'ruby', 'bash', 'yaml', 'php'], true);
        $tokens = [];
        $len = mb_strlen($line);
        $plain = '';
        $flush = static function () use (&$plain, &$tokens) {
            if ($plain !== '') {
                $tokens[] = [null, $plain];
                $plain = '';
            }
        };
        for ($i = 0; $i < $len;) {
            $rest = mb_substr($line, $i);
            if (str_starts_with($rest, '//') || ($hashComment && str_starts_with($rest, '#')) || ($language === 'sql' && str_starts_with($rest, '--'))) {
                $flush();
                $tokens[] = ['c', $rest];
                break;
            }
            $ch = mb_substr($line, $i, 1);
            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $flush();
                $end = $i + 1;
                while ($end < $len && mb_substr($line, $end, 1) !== $ch) {
                    $end += mb_substr($line, $end, 1) === '\\' ? 2 : 1;
                }
                $tokens[] = ['s', mb_substr($line, $i, min($end + 1, $len) - $i)];
                $i = $end + 1;
                continue;
            }
            if (preg_match('/^\d+(\.\d+)?/', $rest, $m) && ($i === 0 || !preg_match('/\w/', mb_substr($line, $i - 1, 1)))) {
                $flush();
                $tokens[] = ['n', $m[0]];
                $i += mb_strlen($m[0]);
                continue;
            }
            if (preg_match('/^[A-Za-z_]\w*/', $rest, $m)) {
                $word = $m[0];
                if (isset($keywords[$language === 'sql' ? strtolower($word) : $word])) {
                    $flush();
                    $tokens[] = ['k', $word];
                } else {
                    $plain .= $word;
                }
                $i += strlen($word);
                continue;
            }
            $plain .= $ch;
            $i++;
        }
        $flush();
        return $tokens;
    }

    private static function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }
}
