<?php

namespace Tests\Unit\Console\Commands\Ripple;

use App\Console\Commands\Ripple\ShrinkOverflowBoundsCommand;
use Tests\TestCase;

/**
 * The whole value of this backfill is that it changes SIZE and not SHAPE, so these
 * tests are mostly about what it refuses to do.
 */
class ShrinkOverflowBoundsCommandTest extends TestCase
{
    private function command(): ShrinkOverflowBoundsCommand
    {
        return new ShrinkOverflowBoundsCommand();
    }

    /** A ring in the shape production actually stores: 14 significant digits per number. */
    private function ring(int $vertices = 5): string
    {
        $pts = [];
        for ($i = 0; $i < $vertices; $i++) {
            // 0.0003 apart, the lattice the rasteriser traces on.
            $pts[] = sprintf('%.12f %.12f', -2.012234405899 + ($i * 0.0003), 52.537323913574);
        }
        $pts[] = $pts[0];

        return 'POLYGON(('.implode(', ', $pts).'))';
    }

    public function test_shrinks_ring_wkt_without_moving_it(): void
    {
        $json = json_encode(['cluster' => ['w1' => $this->ring(20)]]);

        $out = $this->command()->shrink($json);

        $this->assertNotNull($out);
        $this->assertLessThan(strlen($json), strlen($out), 'the point is that it gets smaller');

        $before = json_decode($json, true)['cluster']['w1'];
        $after = json_decode($out, true)['cluster']['w1'];

        preg_match_all('/-?\d+(?:\.\d+)?/', $before, $b);
        preg_match_all('/-?\d+(?:\.\d+)?/', $after, $a);

        $this->assertSameSize($b[0], $a[0], 'no vertex may be dropped');
        foreach ($a[0] as $i => $v) {
            $this->assertLessThanOrEqual(
                0.00005,
                abs(((float) $v) - ((float) $b[0][$i])),
                "coordinate {$i} moved further than rounding to 4dp can move it"
            );
        }
    }

    public function test_leaves_bbox_and_scalars_alone(): void
    {
        $json = json_encode([
            'bbox' => [-2.256434405899, 52.537323913574, -1.91073203125, 52.630313201904],
            'fairness_budget_min' => 12.5,
            'cluster' => ['w1' => $this->ring(10)],
        ]);

        $out = json_decode($this->command()->shrink($json), true);

        // bbox is four doubles, not a ring: rounding it is not this command's business,
        // and it is four numbers against thousands, so there is nothing to win either.
        $this->assertSame(
            [-2.256434405899, 52.537323913574, -1.91073203125, 52.630313201904],
            $out['bbox']
        );
        $this->assertSame(12.5, $out['fairness_budget_min']);
    }

    public function test_keeps_every_lane_and_band(): void
    {
        $json = json_encode([
            'rural' => ['w1' => $this->ring(6), 'w2' => $this->ring(6)],
            'cluster' => ['w1' => $this->ring(6)],
        ]);

        $out = json_decode($this->command()->shrink($json), true);

        $this->assertSame(['w1', 'w2'], array_keys($out['rural']));
        $this->assertSame(['w1'], array_keys($out['cluster']));
        foreach (['rural', 'cluster'] as $lane) {
            foreach ($out[$lane] as $wkt) {
                $this->assertStringStartsWith('POLYGON((', $wkt);
                $this->assertStringEndsWith('))', $wkt);
            }
        }
    }

    public function test_already_short_ring_is_returned_unchanged(): void
    {
        // Written by the fixed ReachService, so there is nothing to round. It must come
        // back byte-identical, and the caller's min-saving check then skips the row.
        $json = json_encode(['cluster' => ['w1' => 'POLYGON((-2.0122 52.5373, -2.0119 52.5373, -2.0119 52.5376, -2.0122 52.5373))']]);

        $this->assertSame($json, $this->command()->shrink($json));
    }

    public function test_neighbouring_lattice_points_never_collapse(): void
    {
        // The rings are a raster staircase: neighbours are 0.0003 apart, which is three
        // whole units at 0.0001 resolution, and rounding moves each by at most half a
        // unit. If two ever merged, the ring would gain a zero-length segment and the
        // saving would be hiding a geometry change.
        $pts = [];
        for ($i = 0; $i < 200; $i++) {
            $pts[] = sprintf('%.12f %.12f', -2.012234405899 + ($i * 0.0003), 52.537323913574);
        }
        $pts[] = $pts[0];
        $json = json_encode(['cluster' => ['w1' => 'POLYGON(('.implode(', ', $pts).'))']]);

        $out = json_decode($this->command()->shrink($json), true)['cluster']['w1'];
        preg_match_all('/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/', $out, $m, PREG_SET_ORDER);

        $seen = null;
        foreach ($m as $pair) {
            $here = $pair[1].' '.$pair[2];
            $this->assertNotSame($seen, $here, 'two adjacent vertices collapsed into one');
            $seen = $here;
        }
    }

    public function test_rejects_json_that_is_not_an_object(): void
    {
        $this->assertNull($this->command()->shrink('not json'));
        $this->assertNull($this->command()->shrink('"a string"'));
    }

    public function test_negative_zero_is_written_as_zero(): void
    {
        // -0.0000001 rounds to "-0.000000" -> "-0", which is legal WKT but reads as a
        // mistake and compares unequal to the "0" a fresh write would produce.
        $json = json_encode(['cluster' => ['w1' => 'POLYGON((-0.000000100000 52.100000000000, 0.000000100000 52.100000000000, 0.000000100000 52.200000000000, -0.000000100000 52.100000000000))']]);

        $out = json_decode($this->command()->shrink($json), true)['cluster']['w1'];

        $this->assertStringNotContainsString('-0 ', $out);
        $this->assertStringNotContainsString('-0,', $out);
    }

    public function test_output_matches_what_a_fresh_write_would_produce(): void
    {
        // A backfilled row and a newly written one must be byte-identical, or the two
        // code paths have drifted and the next person cannot tell them apart.
        $reach = new \App\Services\Ripple\ReachService();

        $m = new \ReflectionMethod($reach, 'polygonToWkt');
        $m->setAccessible(true);

        $coords = [];
        for ($i = 0; $i < 8; $i++) {
            $coords[] = [-2.012234405899 + ($i * 0.0003), 52.537323913574];
        }
        $coords[] = $coords[0];

        $fresh = $m->invoke($reach, ['geometry' => ['coordinates' => [$coords]]]);

        $legacy = 'POLYGON(('.implode(', ', array_map(
            fn ($c) => sprintf('%.12f %.12f', $c[0], $c[1]),
            $coords
        )).'))';

        $backfilled = json_decode(
            $this->command()->shrink(json_encode(['cluster' => ['w1' => $legacy]])),
            true
        )['cluster']['w1'];

        $this->assertSame($fresh, $backfilled);
    }
}
