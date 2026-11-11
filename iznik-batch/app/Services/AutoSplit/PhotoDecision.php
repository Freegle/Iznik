<?php

namespace App\Services\AutoSplit;

/**
 * What one photo becomes, from the boxes the vision model drew on it. Pure: no model, no image.
 *
 * - No listed item in it: a shared photo of the post, on no item.
 * - One listed item: the whole photo goes to that item.
 * - Several: one crop per item, but only when every crop is clean. A crop is clean when its box
 *   is a real part of the photo (not a speck), not a sliver, at least MIN_SIDE pixels on its
 *   short side, and holds no more than MAX_OVERLAP of any other item's box. If any item fails,
 *   the photo stays shared: a bad crop looks worse than none.
 *
 * The thresholds were set by looking at crops of recorded posts (2026-11): at a fifth of another
 * item a crop still reads as its own item; at a quarter it starts to show the neighbour enough
 * to confuse. docs/developers/reference/auto-split.md.
 */
final class PhotoDecision
{
    public const PAD = 0.06;

    public const MIN_AREA = 0.015;

    public const MAX_OVERLAP = 0.2;

    public const MIN_SIDE = 100;

    public const MAX_ASPECT = 4.0;

    /**
     * @param  list<array{item:int, box_2d:list<int|float>}>  $shows  1-based item numbers; box_2d is [ymin, xmin, ymax, xmax] on 0..1000
     * @param  int  $items  how many items the post has
     * @param  int  $width  photo width in pixels, as shown (after any rotation)
     * @param  int  $height  photo height in pixels, as shown
     * @return array{action:string, item?:int, crops?:list<array{item:int,cx:int,cy:int,cw:int,ch:int}>, why?:string}
     */
    public static function decide(array $shows, int $items, int $width, int $height): array
    {
        $boxes = [];
        foreach ($shows as $show) {
            $item = (int) ($show['item'] ?? 0);
            $box = $show['box_2d'] ?? null;
            if ($item < 1 || $item > $items || ! is_array($box) || count($box) !== 4 || isset($boxes[$item])) {
                continue;
            }
            [$y0, $x0, $y1, $x1] = array_map(fn ($v) => max(0.0, min(1000.0, (float) $v)) / 1000, array_values($box));
            $rect = ['x0' => min($x0, $x1), 'y0' => min($y0, $y1), 'x1' => max($x0, $x1), 'y1' => max($y0, $y1)];
            if (self::area($rect) > 0) {
                $boxes[$item] = $rect;
            }
        }

        if (! $boxes) {
            return ['action' => 'shared', 'why' => 'no_items'];
        }
        if (count($boxes) === 1) {
            return ['action' => 'whole', 'item' => array_key_first($boxes)];
        }
        if ($width <= 0 || $height <= 0) {
            return ['action' => 'shared', 'why' => 'no_size'];
        }

        $crops = [];
        foreach ($boxes as $item => $r) {
            if (self::area($r) < self::MIN_AREA) {
                return ['action' => 'shared', 'why' => "small:{$item}"];
            }
            $w = $r['x1'] - $r['x0'];
            $h = $r['y1'] - $r['y0'];
            $c = [
                'x0' => max(0.0, $r['x0'] - $w * self::PAD),
                'y0' => max(0.0, $r['y0'] - $h * self::PAD),
                'x1' => min(1.0, $r['x1'] + $w * self::PAD),
                'y1' => min(1.0, $r['y1'] + $h * self::PAD),
            ];
            foreach ($boxes as $other => $o) {
                if ($other !== $item && self::area(self::intersect($c, $o)) > self::MAX_OVERLAP * self::area($o)) {
                    return ['action' => 'shared', 'why' => "overlap:{$item}:{$other}"];
                }
            }

            $cx = (int) round($c['x0'] * $width);
            $cy = (int) round($c['y0'] * $height);
            $cw = min($width - $cx, (int) round(($c['x1'] - $c['x0']) * $width));
            $ch = min($height - $cy, (int) round(($c['y1'] - $c['y0']) * $height));
            if (min($cw, $ch) < self::MIN_SIDE) {
                return ['action' => 'shared', 'why' => "tiny:{$item}"];
            }
            if (max($cw, $ch) > self::MAX_ASPECT * min($cw, $ch)) {
                return ['action' => 'shared', 'why' => "sliver:{$item}"];
            }
            $crops[] = ['item' => $item, 'cx' => $cx, 'cy' => $cy, 'cw' => $cw, 'ch' => $ch];
        }

        return ['action' => 'crop', 'crops' => $crops];
    }

    private static function area(array $r): float
    {
        return max(0.0, $r['x1'] - $r['x0']) * max(0.0, $r['y1'] - $r['y0']);
    }

    private static function intersect(array $a, array $b): array
    {
        return [
            'x0' => max($a['x0'], $b['x0']),
            'y0' => max($a['y0'], $b['y0']),
            'x1' => min($a['x1'], $b['x1']),
            'y1' => min($a['y1'], $b['y1']),
        ];
    }
}
