<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Domain;

/**
 * Tiny dependency-free inline SVG chart (bars for the primary series, line for the secondary one), so the back office
 * dashboard works offline / without a CDN. Output is self-contained, escaped SVG markup.
 */
final class SvgChart
{
    /**
     * @param array<string, float> $bars  label => value (primary, left axis)
     * @param array<string, float> $line  label => value (secondary, same scale, drawn as a line)
     */
    public static function barLine(array $bars, array $line = [], int $width = 900, int $height = 260, string $barLabel = 'GMV', string $lineLabel = 'Commission'): string
    {
        $labels = array_keys($bars);
        $n = max(1, count($labels));
        $padL = 56;
        $padR = 12;
        $padT = 24;
        $padB = 46;
        $w = $width - $padL - $padR;
        $h = $height - $padT - $padB;
        $max = max(1.0, (float) max([0, ...array_values($bars), ...array_values($line)]));
        $max = self::niceCeil($max);
        $slot = $w / $n;
        $bw = max(2.0, $slot * 0.7);
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $svg = sprintf('<svg class="bxmp-chart" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s" xmlns="http://www.w3.org/2000/svg" style="max-width:%dpx;font:11px sans-serif">', $width, $height, $e($barLabel . ' trend'), $width);
        // grid + y axis labels
        for ($i = 0; $i <= 4; ++$i) {
            $y = $padT + $h - $h * $i / 4;
            $svg .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#e5e7eb"/>', $padL, $padL + $w, $y, $y);
            $svg .= sprintf('<text x="%d" y="%.1f" text-anchor="end" fill="#6b7280">%s</text>', $padL - 6, $y + 4, $e(self::short($max * $i / 4)));
        }
        $every = (int) ceil($n / 12);
        $points = [];
        foreach ($labels as $i => $label) {
            $v = (float) $bars[$label];
            $bh = $h * $v / $max;
            $x = $padL + $slot * $i + ($slot - $bw) / 2;
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#25b9d7" rx="2"><title>%s: %s %.2f</title></rect>', $x, $padT + $h - $bh, $bw, $bh, $e($label), $e($barLabel), $v);
            if ($i % $every === 0) {
                $svg .= sprintf('<text x="%.1f" y="%d" text-anchor="middle" fill="#6b7280">%s</text>', $padL + $slot * $i + $slot / 2, $padT + $h + 16, $e(self::shortLabel((string) $label)));
            }
            if (array_key_exists($label, $line)) {
                $points[] = sprintf('%.1f,%.1f', $padL + $slot * $i + $slot / 2, $padT + $h - $h * (float) $line[$label] / $max);
            }
        }
        if ($points) {
            $svg .= '<polyline fill="none" stroke="#f59e0b" stroke-width="2" points="' . implode(' ', $points) . '"/>';
        }
        // legend
        $ly = $height - 10;
        $svg .= sprintf('<rect x="%d" y="%d" width="10" height="10" fill="#25b9d7"/><text x="%d" y="%d">%s</text>', $padL, $ly - 9, $padL + 14, $ly, $e($barLabel));
        if ($points) {
            $svg .= sprintf('<line x1="%d" x2="%d" y1="%d" y2="%d" stroke="#f59e0b" stroke-width="2"/><text x="%d" y="%d">%s</text>', $padL + 90, $padL + 104, $ly - 4, $ly - 4, $padL + 108, $ly, $e($lineLabel));
        }

        return $svg . '</svg>';
    }

    /** Horizontal bars (e.g. GMV share per vendor). @param array<string, float> $values */
    public static function hbars(array $values, int $width = 420, string $suffix = ''): string
    {
        $rowH = 22;
        $height = max(1, count($values)) * $rowH + 4;
        $max = max(1e-9, (float) max([0, ...array_values($values)]));
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $svg = sprintf('<svg viewBox="0 0 %d %d" width="100%%" xmlns="http://www.w3.org/2000/svg" style="max-width:%dpx;font:11px sans-serif">', $width, $height, $width);
        $i = 0;
        foreach ($values as $label => $v) {
            $y = $i * $rowH + 2;
            $bw = ($width - 190) * (float) $v / $max;
            $svg .= sprintf('<text x="0" y="%d" fill="#374151">%s</text>', $y + 14, $e(mb_strimwidth((string) $label, 0, 22, '…')));
            $svg .= sprintf('<rect x="130" y="%d" width="%.1f" height="16" fill="#25b9d7" rx="2"/>', $y + 2, max(0.0, $bw));
            $svg .= sprintf('<text x="%.1f" y="%d" fill="#374151">%s%s</text>', 134 + max(0.0, $bw), $y + 14, $e(number_format((float) $v, 2, '.', ',')), $e($suffix));
            ++$i;
        }

        return $svg . '</svg>';
    }

    public static function niceCeil(float $v): float
    {
        if ($v <= 0) {
            return 1.0;
        }
        $exp = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $exp >= $v) {
                return $m * $exp;
            }
        }

        return 10 * $exp;
    }

    private static function short(float $v): string
    {
        if ($v >= 1_000_000) {
            return round($v / 1_000_000, 1) . 'M';
        }
        if ($v >= 1000) {
            return round($v / 1000, 1) . 'k';
        }

        return (string) round($v, 2);
    }

    private static function shortLabel(string $l): string
    {
        return preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $l, $m) ? $m[2] . '/' . $m[1] : $l;
    }
}
