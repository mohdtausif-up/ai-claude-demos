<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Dependency-free QR Code generator (ISO/IEC 18004), byte mode, versions 1-10, error correction L or M.
 * Enough for URLs up to ~210 characters. Output is an SVG string.
 */
final class QrService
{
    /** version => [totalCodewords, L => [ecPerBlock, blocksG1, dataG1, blocksG2, dataG2], M => [...]] */
    private const SPEC = [
        1 => [26, 'L' => [7, 1, 19, 0, 0], 'M' => [10, 1, 16, 0, 0]],
        2 => [44, 'L' => [10, 1, 34, 0, 0], 'M' => [16, 1, 28, 0, 0]],
        3 => [70, 'L' => [15, 1, 55, 0, 0], 'M' => [26, 1, 44, 0, 0]],
        4 => [100, 'L' => [20, 1, 80, 0, 0], 'M' => [18, 2, 32, 0, 0]],
        5 => [134, 'L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0]],
        6 => [172, 'L' => [18, 2, 68, 0, 0], 'M' => [16, 4, 27, 0, 0]],
        7 => [196, 'L' => [20, 2, 78, 0, 0], 'M' => [18, 4, 31, 0, 0]],
        8 => [242, 'L' => [24, 2, 97, 0, 0], 'M' => [22, 2, 38, 2, 39]],
        9 => [292, 'L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37]],
        10 => [346, 'L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    private const FORMAT_BITS = ['L' => 1, 'M' => 0];

    /** Test hook: pin the mask pattern (0-7) instead of choosing by penalty score. */
    public ?int $forceMask = null;

    /** @var list<int> */
    private static array $exp = [];
    /** @var array<int,int> */
    private static array $log = [];

    public function svg(string $data, string $ecc = 'M', int $quiet = 4, string $title = 'QR code'): string
    {
        $m = $this->matrix($data, $ecc);
        $n = count($m);
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            $x = 0;
            while ($x < $n) {
                if ($m[$y][$x]) {
                    $start = $x;
                    while ($x < $n && $m[$y][$x]) {
                        $x++;
                    }
                    $path .= 'M' . ($start + $quiet) . ',' . ($y + $quiet) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $size = $n + 2 * $quiet;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . e($title) . '" shape-rendering="crispEdges">'
            . '<title>' . e($title) . '</title><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /** @return list<list<bool>> modules, true = dark (no quiet zone) */
    public function matrix(string $data, string $ecc = 'M'): array
    {
        if (!isset(self::FORMAT_BITS[$ecc])) {
            throw new InvalidArgumentException('Unsupported error correction level');
        }
        $bytes = $data === '' ? [] : array_values(unpack('C*', $data));
        $len = count($bytes);
        $version = 0;
        foreach (self::SPEC as $v => $spec) {
            [$ec, $b1, $d1, $b2, $d2] = $spec[$ecc];
            $dataCw = $b1 * $d1 + $b2 * $d2;
            $needBits = 4 + ($v < 10 ? 8 : 16) + 8 * $len;
            if ($needBits <= $dataCw * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Data too long for QR version 10');
        }
        [$ecPerBlock, $b1, $d1, $b2, $d2] = self::SPEC[$version][$ecc];
        $dataCw = $b1 * $d1 + $b2 * $d2;

        // 1. Bit stream: mode, count, data, terminator, padding.
        $bits = [];
        $append = static function (int $val, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $append(0b0100, 4);
        $append($len, $version < 10 ? 8 : 16);
        foreach ($bytes as $b) {
            $append($b, 8);
        }
        $append(0, min(4, $dataCw * 8 - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $codewords[] = bindec(implode('', array_slice($bits, $i, 8)));
        }
        for ($pad = 0xEC; count($codewords) < $dataCw; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        // 2. Split into blocks, add Reed-Solomon codewords, interleave.
        $this->initGf();
        $blocks = [];
        $pos = 0;
        foreach ([[$b1, $d1], [$b2, $d2]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $blocks[] = array_slice($codewords, $pos, $size);
                $pos += $size;
            }
        }
        $gen = $this->rsGenerator($ecPerBlock);
        $ecBlocks = array_map(fn ($blk) => $this->rsRemainder($blk, $gen), $blocks);
        $final = [];
        $maxData = max($d1, $d2);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $blk) {
                if (isset($blk[$i])) {
                    $final[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $blk) {
                $final[] = $blk[$i];
            }
        }
        if (count($final) !== self::SPEC[$version][0]) {
            throw new \LogicException('QR codeword count mismatch');
        }

        // 3. Draw function patterns, place data, pick the best mask.
        $size = $version * 4 + 17;
        $mods = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $this->drawFunctionPatterns($mods, $fn, $version, $size);
        $this->placeData($mods, $fn, $final, $size);

        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            if ($this->forceMask !== null && $mask !== $this->forceMask) {
                continue;
            }
            $cand = $mods;
            $this->applyMask($cand, $fn, $mask, $size);
            $this->drawFormat($cand, $ecc, $mask, $size);
            $score = $this->penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }
        return $best;
    }

    private function drawFunctionPatterns(array &$m, array &$fn, int $version, int $size): void
    {
        $set = static function (int $x, int $y, bool $dark) use (&$m, &$fn, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $m[$y][$x] = $dark;
                $fn[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $cx) {
            foreach ($pos as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat($m, 'M', 0, $size, $fn); // reserve the format areas
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bitsV = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bitsV >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $bit);
                $set($b, $a, $bit);
            }
        }
    }

    /** Writes both copies of the 15 format bits (and the always-dark module). */
    private function drawFormat(array &$m, string $ecc, int $mask, int $size, ?array &$fn = null): void
    {
        $data = (self::FORMAT_BITS[$ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $set = function (int $x, int $y, bool $dark) use (&$m, &$fn): void {
            $m[$y][$x] = $dark;
            if ($fn !== null) {
                $fn[$y][$x] = true;
            }
        };
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    }

    private function placeData(array &$m, array $fn, array $codewords, int $size): void
    {
        $i = 0;
        $total = count($codewords) * 8;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) {
                        $m[$y][$x] = ((($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1);
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(array &$m, array $fn, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($fn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $m[$y][$x] = !$m[$y][$x];
                }
            }
        }
    }

    private function penalty(array $m, int $size): int
    {
        $score = 0;
        $lines = [];
        for ($y = 0; $y < $size; $y++) {
            $lines[] = implode('', array_map(static fn ($b) => $b ? '1' : '0', $m[$y]));
        }
        for ($x = 0; $x < $size; $x++) {
            $col = '';
            for ($y = 0; $y < $size; $y++) {
                $col .= $m[$y][$x] ? '1' : '0';
            }
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            // N1: runs of 5+ same-colour modules.
            if (preg_match_all('/0{5,}|1{5,}/', $line, $runs)) {
                foreach ($runs[0] as $run) {
                    $score += strlen($run) - 2;
                }
            }
            // N3: finder-like 1:1:3:1:1 patterns with a 4-module light border.
            foreach (['10111010000', '00001011101'] as $pat) {
                $off = 0;
                while (($p = strpos($line, $pat, $off)) !== false) {
                    $score += 40;
                    $off = $p + 1;
                }
            }
        }
        // N2: 2x2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // N4: balance of dark and light.
        $dark = 0;
        foreach ($m as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $score += ((int) ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;
        return $score;
    }

    private function initGf(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private function gfMul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return list<int> coefficients of the monic generator polynomial (highest degree term omitted) */
    private function rsGenerator(int $degree): array
    {
        $g = array_fill(0, $degree, 0);
        $g[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $g[$j] = $this->gfMul($g[$j], $root);
                if ($j + 1 < $degree) {
                    $g[$j] ^= $g[$j + 1];
                }
            }
            $root = $this->gfMul($root, 2);
        }
        return $g;
    }

    /** @return list<int> */
    private function rsRemainder(array $data, array $gen): array
    {
        $degree = count($gen);
        $rem = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($rem);
            $rem[] = 0;
            foreach ($gen as $i => $coef) {
                $rem[$i] ^= $this->gfMul($coef, $factor);
            }
        }
        return $rem;
    }
}
