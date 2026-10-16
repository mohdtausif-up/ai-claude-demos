<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-rendered SVG charts (no inline styles: colours come from .viz classes in app.css,
 * so light/dark themes and the CSP both hold). Columns are capped at 24px with a 4px rounded
 * data-end on a single baseline; an optional second series is a 2px line with ringed dots.
 * Every column carries a hover target (data-tip) and a <title> fallback; a table view is
 * rendered alongside by the caller via table().
 */
final class Chart
{
    /**
     * @param list<array{label:string, value:float|int, value2?:float|int, tip:string}> $points
     * @param array{0:string, 1:?string} $series names of the column series and the optional line series
     */
    public static function columns(array $points, array $series, string $ariaLabel, ?callable $format = null): string
    {
        $format ??= static fn ($v) => number_format((float) $v);
        $W = 640;
        $H = 220;
        $left = 44;
        $right = 12;
        $top = 16;
        $bottom = 28;
        $plotW = $W - $left - $right;
        $plotH = $H - $top - $bottom;
        $n = max(1, count($points));
        $max = 0;
        foreach ($points as $p) {
            $max = max($max, (float) $p['value'], (float) ($p['value2'] ?? 0));
        }
        [$niceMax, $step] = self::niceScale($max);
        $band = $plotW / $n;
        $barW = min(24, $band * 0.6);
        $y = static fn (float $v) => $top + $plotH - ($niceMax > 0 ? $v / $niceMax * $plotH : 0);

        $svg = sprintf('<svg class="viz" viewBox="0 0 %d %d" role="img" aria-label="%s" preserveAspectRatio="xMidYMid meet">', $W, $H, e($ariaLabel));
        // Recessive hairline grid + clean, comma'd ticks.
        for ($t = 0; $t <= $niceMax + 1e-9; $t += $step) {
            $gy = round($y($t), 1);
            $svg .= sprintf('<line class="viz-grid%s" x1="%d" x2="%d" y1="%s" y2="%s"/>', $t == 0 ? ' viz-baseline' : '', $left, $W - $right, $gy, $gy);
            $svg .= sprintf('<text class="viz-tick" x="%d" y="%s" text-anchor="end">%s</text>', $left - 8, $gy + 4, e($format($t)));
        }
        $labelEvery = (int) ceil($n / 7);
        $linePts = [];
        foreach (array_values($points) as $i => $p) {
            $cx = $left + $band * $i + $band / 2;
            $v = (float) $p['value'];
            if ($v > 0) {
                $x0 = $cx - $barW / 2;
                $yTop = $y($v);
                $h = $y(0) - $yTop;
                $r = min(4, $h, $barW / 2);
                // Rounded data-end, square at the baseline.
                $svg .= sprintf(
                    '<path class="viz-s1" d="M%1$.1f %2$.1f V%3$.1f Q%1$.1f %4$.1f %5$.1f %4$.1f H%6$.1f Q%7$.1f %4$.1f %7$.1f %3$.1f V%2$.1f Z"/>',
                    $x0, $y(0), $yTop + $r, $yTop, $x0 + $r, $x0 + $barW - $r, $x0 + $barW
                );
            }
            if (array_key_exists('value2', $p) && $series[1] !== null) {
                $linePts[] = [$cx, $y((float) $p['value2'])];
            }
            if ($i % $labelEvery === 0 || $i === $n - 1) {
                $svg .= sprintf('<text class="viz-tick" x="%.1f" y="%d" text-anchor="middle">%s</text>', $cx, $H - 8, e($p['label']));
            }
        }
        if ($linePts !== []) {
            $svg .= '<polyline class="viz-s2-line" points="' . implode(' ', array_map(static fn ($p) => sprintf('%.1f,%.1f', $p[0], $p[1]), $linePts)) . '"/>';
            foreach ($linePts as [$px, $py]) {
                $svg .= sprintf('<circle class="viz-s2-dot" cx="%.1f" cy="%.1f" r="4"/>', $px, $py);
            }
        }
        // Hover layer: full-height hit targets, larger than the marks.
        foreach (array_values($points) as $i => $p) {
            $svg .= sprintf(
                '<rect class="viz-hit" x="%.1f" y="%d" width="%.1f" height="%d" tabindex="0" data-tip="%s"><title>%s</title></rect>',
                $left + $band * $i, $top, $band, $plotH, e($p['tip']), e($p['tip'])
            );
        }
        $svg .= '</svg>';

        $legend = '';
        if ($series[1] !== null) {
            $legend = '<ul class="viz-legend"><li><span class="viz-key viz-key-s1" aria-hidden="true"></span>' . e($series[0])
                . '</li><li><span class="viz-key viz-key-s2" aria-hidden="true"></span>' . e($series[1]) . '</li></ul>';
        }
        return '<div class="viz-root" data-chart>' . $legend . $svg . '<div class="viz-tooltip" role="status" hidden></div></div>';
    }

    /** @return array{0:float, 1:float} a rounded axis maximum and tick step (about 4 ticks) */
    public static function niceScale(float $max): array
    {
        if ($max <= 0) {
            return [4, 1];
        }
        $raw = $max / 4;
        $mag = 10 ** floor(log10($raw));
        $step = $mag * (($raw / $mag) <= 1 ? 1 : (($raw / $mag) <= 2 ? 2 : (($raw / $mag) <= 5 ? 5 : 10)));
        $step = max($step, 1);
        return [ceil($max / $step) * $step, $step];
    }

    /** Accessible data table for a chart. */
    public static function table(array $headers, array $rows, string $caption): string
    {
        $html = '<table class="viz-table"><caption class="visually-hidden">' . e($caption) . '</caption><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th scope="col">' . e($h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>' . implode('', array_map(static fn ($c) => '<td>' . e($c) . '</td>', $row)) . '</tr>';
        }
        return $html . '</tbody></table>';
    }
}
