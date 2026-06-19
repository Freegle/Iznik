<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\Ripple\RippleTuneService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RippleTuneServiceTest extends TestCase
{
    private RippleTuneService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RippleTuneService();
        DB::statement('DELETE FROM rippling_hotspots');
        DB::statement('DELETE FROM rippling_live_metrics');
        DB::statement('DELETE FROM rippling_params');
    }

    /** The centrepiece: an area that is a robust outlier is flagged; normal areas are not. */
    public function test_detect_hotspots_flags_an_unusual_area_but_not_normal_ones(): void
    {
        // A dozen groups with ordinary spread around 10, then one wildly-high area.
        $values = [
            1 => 8, 2 => 9, 3 => 10, 4 => 11, 5 => 12,
            6 => 9, 7 => 10, 8 => 11, 9 => 8, 10 => 12, 11 => 10, 12 => 9,
            99 => 100,
        ];

        $written = $this->service->detectHotspots($values, 'volume_posts', '2026-06-11', 'group', [99 => 'Anomaly Town']);

        $this->assertSame(1, $written, 'only the outlier area is flagged');
        $hotspot = DB::table('rippling_hotspots')->where('metric', 'volume_posts')->first();
        $this->assertSame(99, (int) $hotspot->area_id);
        $this->assertSame('high', $hotspot->direction);
        $this->assertSame('alert', $hotspot->severity);
        $this->assertSame('Anomaly Town', $hotspot->area_name);
        $this->assertEqualsWithDelta(10.0, (float) $hotspot->baseline, 1.0, 'baseline is the robust median, not skewed by the outlier');
        // None of the ordinary groups are flagged.
        $this->assertSame(0, DB::table('rippling_hotspots')->whereIn('area_id', [1, 5, 12])->count());
    }

    /** Robust to the outlier inflating the spread: a single huge value does not mask itself. */
    public function test_detect_hotspots_flags_a_low_outlier_too(): void
    {
        $values = [
            1 => 100, 2 => 98, 3 => 102, 4 => 99, 5 => 101,
            6 => 100, 7 => 97, 8 => 103, 9 => 1,
        ];

        $this->service->detectHotspots($values, 'reach_drive_min', '2026-06-11', 'group');

        $hotspot = DB::table('rippling_hotspots')->where('metric', 'reach_drive_min')->first();
        $this->assertNotNull($hotspot);
        $this->assertSame(9, (int) $hotspot->area_id);
        $this->assertSame('low', $hotspot->direction);
    }

    /** Too few areas → "unusual" is meaningless, so nothing is flagged. */
    public function test_detect_hotspots_needs_enough_areas(): void
    {
        $written = $this->service->detectHotspots([1 => 1, 2 => 50, 3 => 2], 'volume_posts', '2026-06-11');
        $this->assertSame(0, $written);
        $this->assertSame(0, DB::table('rippling_hotspots')->count());
    }

    /** No spread at all → no area is unusual (and no divide-by-zero). */
    public function test_detect_hotspots_handles_no_spread(): void
    {
        $values = array_fill_keys(range(1, 8), 7.0);
        $written = $this->service->detectHotspots($values, 'volume_posts', '2026-06-11');
        $this->assertSame(0, $written);
    }

    /** rollup() records per-group volume and the overall percentiles for the period. */
    public function test_rollup_records_group_volume_and_overall_percentiles(): void
    {
        $start = now()->subDays(7);
        $end = now();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->seedApprovedPosts($groupA->id, 3, now()->subDays(2));
        $this->seedApprovedPosts($groupB->id, 1, now()->subDays(2));

        $written = $this->service->rollup($start, $end);

        $this->assertGreaterThanOrEqual(4, $written);
        $volA = DB::table('rippling_live_metrics')
            ->where('stratum_type', 'group')->where('stratum_key', (string) $groupA->id)
            ->where('metric', 'volume_posts')->value('value');
        $this->assertEquals(3.0, (float) $volA);
        $this->assertTrue(
            DB::table('rippling_live_metrics')->where('stratum_type', 'overall')->where('metric', 'volume_posts_p90')->exists(),
            'overall percentile metrics are recorded'
        );
    }

    /** tune() runs the full weekly pipeline (rollup, hotspot detection, advisory proposals). */
    public function test_tune_runs_the_full_pipeline_and_returns_counts(): void
    {
        $start = now()->subDays(7);
        $end = now();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $this->seedApprovedPosts($groupA->id, 3, now()->subDays(2));
        $this->seedApprovedPosts($groupB->id, 1, now()->subDays(2));

        $result = $this->service->tune($start->toDateString(), $end->toDateString());

        $this->assertSame($start->toDateString(), $result['period_start']);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('hotspots', $result);
        $this->assertArrayHasKey('proposals', $result);
        $this->assertGreaterThan(0, $result['metrics'], 'rollup wrote metrics for the seeded groups');
        $this->assertTrue(
            DB::table('rippling_live_metrics')->where('stratum_key', (string) $groupA->id)->exists(),
            'tune persisted the rollup metrics'
        );
    }

    /** tune() with no arguments defaults to the trailing 7-day window and still runs cleanly. */
    public function test_tune_defaults_to_the_trailing_week(): void
    {
        $result = $this->service->tune();

        $this->assertArrayHasKey('period_start', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertIsInt($result['hotspots']);
        // proposeParams is an advisory stub, so it never proposes regardless of DB state.
        $this->assertSame(0, $result['proposals']);
    }

    /** proposeParams proposes nothing while the per-category delta source is an advisory stub. */
    public function test_propose_params_proposes_nothing_while_deltas_are_unwired(): void
    {
        $this->assertSame(0, $this->service->proposeParams('2026-06-11'));
        $this->assertSame(0, DB::table('rippling_params')->where('status', 'proposed')->count());
    }

    /** Out-of-band categories are proposed (tighten/widen); in-band ones are left alone. */
    public function test_propose_params_writes_guard_railed_proposals_for_out_of_band_categories(): void
    {
        // The production categoryVolumeDeltas is an advisory stub; inject deltas to exercise the
        // proposal logic: one above the band (tighten), one below (widen), one inside (ignored).
        $service = new class extends RippleTuneService
        {
            protected function categoryVolumeDeltas(string $periodStart): array
            {
                return ['urban' => 0.80, 'rural' => -0.30, 'suburban' => 0.10];
            }
        };

        $written = $service->proposeParams('2026-06-11');

        $this->assertSame(2, $written, 'only the two out-of-band categories are proposed');
        $urban = DB::table('rippling_params')->where('ons_category', 'urban')->where('status', 'proposed')->first();
        $this->assertNotNull($urban);
        $this->assertSame(25, (int) $urban->max_minutes, 'high volume tightens reach to 25 min');
        $rural = DB::table('rippling_params')->where('ons_category', 'rural')->where('status', 'proposed')->first();
        $this->assertSame(35, (int) $rural->max_minutes, 'low volume widens reach to 35 min');
        $this->assertSame(
            0,
            DB::table('rippling_params')->where('ons_category', 'suburban')->count(),
            'the in-band category is left alone'
        );
    }

    private function seedApprovedPosts(int $groupid, int $count, $arrival): void
    {
        $user = $this->createTestUser();
        for ($i = 0; $i < $count; $i++) {
            $message = Message::create([
                'type' => Message::TYPE_OFFER,
                'fromuser' => $user->id,
                'subject' => 'OFFER: tune test item',
                'textbody' => 'x',
                'source' => 'Platform',
                'date' => $arrival,
                'arrival' => $arrival,
                'lat' => 51.5,
                'lng' => -0.1,
            ]);
            MessageGroup::create([
                'msgid' => $message->id,
                'groupid' => $groupid,
                'collection' => MessageGroup::COLLECTION_APPROVED,
                'arrival' => $arrival,
            ]);
        }
    }
}
