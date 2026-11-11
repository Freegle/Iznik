<?php

namespace Tests\Unit\Services\AutoSplit;

use App\Services\AutoSplit\PhotoDecision;
use PHPUnit\Framework\TestCase;

class PhotoDecisionTest extends TestCase
{
    public function test_a_photo_of_none_of_the_items_is_shared(): void
    {
        $this->assertSame(['action' => 'shared', 'why' => 'no_items'], PhotoDecision::decide([], 2, 1000, 800));
    }

    public function test_a_photo_of_one_item_goes_whole_to_it(): void
    {
        $d = PhotoDecision::decide([['item' => 2, 'box_2d' => [100, 100, 900, 900]]], 2, 1000, 800);
        $this->assertSame(['action' => 'whole', 'item' => 2], $d);
    }

    public function test_two_items_side_by_side_are_cropped_in_pixels_of_the_photo(): void
    {
        // Left half and right half, with a gap between.
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [100, 50, 900, 400]],
            ['item' => 2, 'box_2d' => [100, 600, 900, 950]],
        ], 2, 1000, 800);

        $this->assertSame('crop', $d['action']);
        $this->assertCount(2, $d['crops']);
        [$a, $b] = $d['crops'];
        $this->assertSame(1, $a['item']);
        // x0 = 0.05 - 0.35 * 0.06 = 0.029 -> 29px; y0 = 0.1 - 0.8 * 0.06 = 0.052 -> 42px of 800.
        $this->assertSame(29, $a['cx']);
        $this->assertSame(42, $a['cy']);
        $this->assertSame(392, $a['cw']);
        $this->assertSame(717, $a['ch']);
        $this->assertSame(2, $b['item']);
        $this->assertLessThanOrEqual(1000, $b['cx'] + $b['cw']);
        $this->assertLessThanOrEqual(800, $b['cy'] + $b['ch']);
    }

    public function test_items_that_overlap_too_much_leave_the_photo_shared(): void
    {
        // A table, with the chairs around it inside its box.
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [200, 200, 800, 800]],
            ['item' => 2, 'box_2d' => [150, 150, 850, 850]],
        ], 2, 1000, 800);

        $this->assertSame('shared', $d['action']);
        $this->assertStringStartsWith('overlap:', $d['why']);
    }

    public function test_a_little_overlap_still_crops(): void
    {
        // Each padded crop reaches a little way into the other box, well under a fifth of it.
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [100, 50, 900, 520]],
            ['item' => 2, 'box_2d' => [100, 500, 900, 950]],
        ], 2, 1000, 800);
        $this->assertSame('crop', $d['action']);
    }

    public function test_a_speck_leaves_the_photo_shared(): void
    {
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [100, 50, 900, 450]],
            ['item' => 2, 'box_2d' => [10, 900, 60, 950]],
        ], 2, 1000, 800);
        $this->assertSame(['action' => 'shared', 'why' => 'small:2'], $d);
    }

    public function test_a_sliver_leaves_the_photo_shared(): void
    {
        // Tall and thin: 5% wide, 90% high.
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [50, 100, 950, 400]],
            ['item' => 2, 'box_2d' => [50, 800, 950, 850]],
        ], 2, 4000, 4000);
        $this->assertSame('shared', $d['action']);
        $this->assertSame('sliver:2', $d['why']);
    }

    public function test_a_crop_too_small_in_pixels_leaves_the_photo_shared(): void
    {
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [100, 50, 900, 400]],
            ['item' => 2, 'box_2d' => [100, 600, 900, 950]],
        ], 2, 200, 200);
        $this->assertSame('shared', $d['action']);
        $this->assertStringStartsWith('tiny:', $d['why']);
    }

    public function test_answers_for_items_that_do_not_exist_are_ignored(): void
    {
        $d = PhotoDecision::decide([
            ['item' => 7, 'box_2d' => [0, 0, 500, 500]],
            ['item' => 0, 'box_2d' => [0, 0, 500, 500]],
            ['item' => 1, 'box_2d' => [0, 0, 500]],
            ['item' => 2, 'box_2d' => [100, 100, 900, 900]],
        ], 2, 1000, 800);
        $this->assertSame(['action' => 'whole', 'item' => 2], $d);
    }

    public function test_a_box_given_upside_down_is_put_right(): void
    {
        $d = PhotoDecision::decide([
            ['item' => 1, 'box_2d' => [900, 400, 100, 50]],
            ['item' => 2, 'box_2d' => [100, 600, 900, 950]],
        ], 2, 1000, 800);
        $this->assertSame('crop', $d['action']);
        $this->assertSame(29, $d['crops'][0]['cx']);
    }
}
