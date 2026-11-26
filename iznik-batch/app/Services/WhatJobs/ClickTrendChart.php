<?php

namespace App\Services\WhatJobs;

/**
 * Draws the WhatJobs trend chart for the emails as a PNG. Mail clients run no scripts and
 * Gmail shows no SVG, so the chart is a picture. The batch image has GD but no font files,
 * so the labels use GD's built-in font.
 *
 * Two panels on one time axis: clicks a day above (billable clicks, and the job ad clicks in
 * our own log), active members a day below, because they are different sizes.
 */
class ClickTrendChart
{
    private const WIDTH = 680;

    private const HEIGHT = 410;

    private const LEFT = 52;

    private const RIGHT = 14;

    private const TOP_PANEL = [34, 228];

    private const BOTTOM_PANEL = [276, 380];

    public const COLOURS = [
        'billable' => [51, 136, 8],
        'ours' => [150, 160, 172],
        'active' => [59, 110, 168],
    ];

    /**
     * @param  list<array{label: ?string, billable: ?float, ours: ?float, active: ?float}>  $points
     * @return string PNG bytes
     */
    public static function render(array $points): string
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($img, 255, 255, 255);
        $grid = imagecolorallocate($img, 229, 229, 229);
        $text = imagecolorallocate($img, 85, 85, 85);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $white);
        $colour = [];
        foreach (self::COLOURS as $name => [$r, $g, $b]) {
            $colour[$name] = imagecolorallocate($img, $r, $g, $b);
        }

        $n = count($points);
        $x = fn (int $i) => (int) round(self::LEFT + ($n > 1 ? $i * (self::WIDTH - self::LEFT - self::RIGHT) / ($n - 1) : 0));

        $clicksMax = self::niceMax(max([1, ...array_filter(array_merge(array_column($points, 'billable'), array_column($points, 'ours')))]));
        $activeMax = self::niceMax(max([1, ...array_filter(array_column($points, 'active'))]));

        self::panel($img, self::TOP_PANEL, $clicksMax, $grid, $text);
        self::panel($img, self::BOTTOM_PANEL, $activeMax, $grid, $text);

        // Legends, above each panel.
        self::legend($img, 8, [[$colour['billable'], 'Billable clicks a day (WhatJobs)'], [$colour['ours'], 'Job ad clicks a day in our own log']], $text);
        self::legend($img, self::BOTTOM_PANEL[0] - 24, [[$colour['active'], 'Active members a day']], $text);

        self::line($img, $points, 'ours', $x, self::TOP_PANEL, $clicksMax, $colour['ours']);
        self::line($img, $points, 'billable', $x, self::TOP_PANEL, $clicksMax, $colour['billable']);
        self::line($img, $points, 'active', $x, self::BOTTOM_PANEL, $activeMax, $colour['active']);

        foreach ($points as $i => $p) {
            if ($p['label'] !== null) {
                imageline($img, $x($i), self::BOTTOM_PANEL[1], $x($i), self::BOTTOM_PANEL[1] + 4, $text);
                imagestring($img, 2, $x($i) - 3 * strlen($p['label']), self::BOTTOM_PANEL[1] + 8, $p['label'], $text);
            }
        }

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** The top of an axis: four grid steps, each a round number, just above the value. */
    public static function niceMax(float $value): float
    {
        $step = $value / 4;
        $power = 10 ** floor(log10($step));
        foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
            if ($m * $power >= $step) {
                return round(4 * $m * $power, 6);
            }
        }

        return round(40 * $power, 6);
    }

    private static function panel(\GdImage $img, array $panel, float $max, int $grid, int $text): void
    {
        [$top, $bottom] = $panel;
        for ($i = 0; $i <= 4; $i++) {
            $y = (int) round($bottom - $i * ($bottom - $top) / 4);
            imageline($img, self::LEFT, $y, self::WIDTH - self::RIGHT, $y, $grid);
            $label = number_format($max * $i / 4);
            imagestring($img, 2, self::LEFT - 6 - 6 * strlen($label), $y - 6, $label, $text);
        }
    }

    private static function legend(\GdImage $img, int $y, array $items, int $text): void
    {
        $x = self::LEFT;
        foreach ($items as [$colour, $label]) {
            imagefilledrectangle($img, $x, $y + 5, $x + 16, $y + 8, $colour);
            imagestring($img, 3, $x + 22, $y, $label, $text);
            $x += 22 + 7 * strlen($label) + 24;
        }
    }

    private static function line(\GdImage $img, array $points, string $key, \Closure $x, array $panel, float $max, int $colour): void
    {
        [$top, $bottom] = $panel;
        $y = fn (float $v) => (int) round($bottom - min($v, $max) / $max * ($bottom - $top));
        imagesetthickness($img, 2);
        $previous = null;
        foreach ($points as $i => $p) {
            $v = $p[$key];
            if ($v === null) {
                $previous = null;

                continue;
            }
            if ($previous !== null) {
                imageline($img, $x($previous[0]), $y($previous[1]), $x($i), $y($v), $colour);
            } elseif (! isset($points[$i + 1][$key])) {
                imagefilledellipse($img, $x($i), $y($v), 5, 5, $colour);
            }
            $previous = [$i, $v];
        }
        imagesetthickness($img, 1);
        $last = array_key_last(array_filter($points, fn ($p) => $p[$key] !== null));
        if ($last !== null) {
            imagefilledellipse($img, $x($last), $y($points[$last][$key]), 7, 7, $colour);
        }
    }
}
