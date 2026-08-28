<?php

namespace Tests\Unit\Services\FirstReply;

use App\Services\FirstReply\MaxReachService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

class MaxReachServiceTest extends TestCase
{
    use SeedsReachCells;

    // Tick 1: a small box around (51.5, -0.1). Tick 3: a much bigger one that
    // contains it, which is what the eventual reach looks like.
    private const TICK1 = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const INSIDE_TICK1 = [51.5, -0.1];      // [lat, lng]

    private const INSIDE_TICK3_ONLY = [51.9, 0.8];  // outside now, reached later

    private const OUTSIDE_EVERYTHING = [55.0, -3.0];

    protected function setUp(): void
    {
        parent::setUp();
        MaxReachService::forgetAvailability();
        // The sizing sweep works on every pending row, so leftovers from another
        // test would show up in this one's counts.
        DB::statement('DELETE FROM firstreply_passthroughs');
        DB::statement('DELETE FROM rippling_reach');
    }

    private function service(): MaxReachService
    {
        return app(MaxReachService::class);
    }

    /** A post rippling at tick 1, with the whole schedule cached as it is in production. */
    private function seedRipplingPost(bool $withSchedule = true): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $schedule = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
            ['tick' => 2, 'drive_min' => 15, 'cumulative_users' => 900, 'wkt' => self::TICK3],
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::TICK1), self::TICK1, $withSchedule ? $schedule : null]
        );

        return (int) $message->id;
    }

    public function test_populate_stores_the_widest_tick_not_the_current_one(): void
    {
        $msgid = $this->seedRipplingPost();

        $stats = $this->service()->populate();

        $this->assertSame(1, $stats['filled']);
        $this->assertSame(0, $stats['routed'], 'inline WKT means no routing call is needed');

        // Somewhere only the final tick covers is now "will reach", while the
        // current reach is untouched.
        $this->assertTrue($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
        $this->assertSame(4000, $this->service()->maxCumulativeUsers($msgid));
    }

    public function test_current_reach_still_counts_as_within_max_reach(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();

        $this->assertTrue($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK1[0], self::INSIDE_TICK1[1]
        ));
    }

    public function test_somewhere_the_post_never_reaches_is_not_within_max_reach(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();

        $this->assertFalse($this->service()->isWithinMaxReach(
            $msgid, self::OUTSIDE_EVERYTHING[0], self::OUTSIDE_EVERYTHING[1]
        ));
    }

    public function test_unpopulated_row_reports_nothing_within_max_reach(): void
    {
        // This is the pre-backfill state, and it must read as "no wider reach
        // known" rather than as "everywhere" or an error: callers fall back to
        // existing behaviour on false.
        $msgid = $this->seedRipplingPost();

        $this->assertFalse($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
        $this->assertNull($this->service()->maxCumulativeUsers($msgid));
    }

    public function test_populate_is_idempotent(): void
    {
        $this->seedRipplingPost();

        $this->assertSame(1, $this->service()->populate()['filled']);
        $this->assertSame(0, $this->service()->populate()['scanned'], 'already-filled rows are not re-scanned');
    }

    public function test_post_with_no_reach_row_is_not_within_max_reach(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $this->assertFalse($this->service()->isWithinMaxReach((int) $message->id, 51.5, -0.1));
    }

    public function test_schedule_order_does_not_decide_the_max(): void
    {
        // The widest tick is picked by tick NUMBER, not by array position, so a
        // reordered payload cannot silently narrow every post's eventual reach.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);

        $schedule = json_encode([
            ['tick' => 3, 'drive_min' => 30, 'cumulative_users' => 4000, 'wkt' => self::TICK3],
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 200, 'wkt' => self::TICK1],
        ]);

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, ?, NOW(), 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::TICK1), self::TICK1, $schedule]
        );

        $this->service()->populate();

        $this->assertTrue($this->service()->isWithinMaxReach(
            (int) $message->id, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ));
    }

    public function test_sizes_a_passthrough_by_the_tick_that_would_have_covered_the_replier(): void
    {
        // The whole point of the passthrough is the wait it removes, so that has
        // to be measurable per reply rather than guessed from a population.
        $msgid = $this->seedRipplingPost();

        // The replier is outside tick 1 but inside tick 2's polygon, and the LOWEST
        // covering tick is the one that decides. Tick k goes live at hazard_hours[k-1],
        // so tick 2 is due 3h after arrival, and the post arrived just now.
        //
        // This asserted 1h (hazard_hours[0]) until 2026-08-12, pinning an off-by-one in
        // the code rather than the behaviour. The mapping is settled by the rest of the
        // engine - ReachService::tickForElapsedHours sets tick = $i + 1 once elapsed >=
        // hazard_hours[$i], and nextExpansionAfter agrees - and by live rows, where
        // reaches finishing at tick k do so exactly hazard_hours[k] hours after arrival
        // (tick 1 at 3.0h, tick 4 at 24.0h, tick 8 at 168.0h).
        DB::table('firstreply_passthroughs')->insert([
            'msgid' => $msgid,
            'source' => 'email',
            'lat' => self::INSIDE_TICK3_ONLY[0],
            'lng' => self::INSIDE_TICK3_ONLY[1],
            'created_at' => now(),
        ]);

        $stats = $this->service()->computePassthroughSavings();

        $this->assertSame(1, $stats['computed']);

        $waited = DB::table('firstreply_passthroughs')->where('msgid', $msgid)->value('waited_hours');
        $this->assertNotNull($waited);
        $this->assertEqualsWithDelta(3.0, (float) $waited, 0.2);
    }

    public function test_a_replier_already_inside_the_current_tick_is_sized_at_zero(): void
    {
        // Not discarded as unknown: dropping the least impressive cases would
        // quietly flatter the average.
        $msgid = $this->seedRipplingPost();

        DB::table('firstreply_passthroughs')->insert([
            'msgid' => $msgid,
            'source' => 'web',
            'lat' => self::INSIDE_TICK1[0],
            'lng' => self::INSIDE_TICK1[1],
            'created_at' => now(),
        ]);

        $this->service()->computePassthroughSavings();

        $this->assertSame(
            0.0,
            (float) DB::table('firstreply_passthroughs')->where('msgid', $msgid)->value('waited_hours')
        );
    }

    public function test_a_replier_no_tick_covers_is_left_unknown_not_zero(): void
    {
        $msgid = $this->seedRipplingPost();

        DB::table('firstreply_passthroughs')->insert([
            'msgid' => $msgid,
            'source' => 'web',
            'lat' => self::OUTSIDE_EVERYTHING[0],
            'lng' => self::OUTSIDE_EVERYTHING[1],
            'created_at' => now(),
        ]);

        $stats = $this->service()->computePassthroughSavings();

        $this->assertSame(1, $stats['unknown']);

        $row = DB::table('firstreply_passthroughs')->where('msgid', $msgid)->first();
        $this->assertNull($row->waited_hours, 'unknown stays unknown rather than counting as no saving');
        $this->assertNotNull($row->computed_at, 'but it is stamped so it is not rescanned forever');
    }

    public function test_sizing_is_not_repeated(): void
    {
        $msgid = $this->seedRipplingPost();
        DB::table('firstreply_passthroughs')->insert([
            'msgid' => $msgid,
            'source' => 'web',
            'lat' => self::INSIDE_TICK3_ONLY[0],
            'lng' => self::INSIDE_TICK3_ONLY[1],
            'created_at' => now(),
        ]);

        $this->assertSame(1, $this->service()->computePassthroughSavings()['scanned']);
        $this->assertSame(0, $this->service()->computePassthroughSavings()['scanned']);
    }

    /**
     * The real end-to-end proof for plans/2026-08-24-rippling-reach-raster-
     * storage.md: populate() must not just leave max_polygon_cells alone -
     * it has to make a REAL call to the spatial server's rasterise endpoint
     * and store what comes back, decodably, agreeing with the geometry it
     * was rasterised from. This is the one test in the suite that actually
     * exercises the network call rather than tolerating it failing silently
     * (CellSetService::rasterize is best-effort everywhere else, by design).
     */
    public function test_populate_actually_rasterises_the_max_polygon_via_the_real_spatial_service(): void
    {
        $msgid = $this->seedRipplingPost();

        $this->service()->populate();

        $cells = DB::table('rippling_reach')->where('msgid', $msgid)->value('max_polygon_cells');
        $this->assertNotNull(
            $cells,
            'populate() must call the spatial server and store what it returns - '
            . 'if this is null, the rasterise endpoint is unreachable or missing'
        );

        $cellSets = app(\App\Services\Ripple\CellSetService::class);
        $decoded = $cellSets->decode($cells);
        // TICK3 is the eventual reach; a point only it covers must be contained.
        $this->assertTrue($cellSets->contains($decoded, self::INSIDE_TICK3_ONLY[1], self::INSIDE_TICK3_ONLY[0]));
        $this->assertFalse($cellSets->contains($decoded, -3.0, 55.0));
    }

    public function test_a_malformed_cell_set_fails_closed(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();

        // Corrupt the cell set in place - a bad magic number, the way a
        // truncated write or a format mismatch would present. There is
        // nothing to fall back to, and admitting on unreadable bytes would
        // be the unsafe direction for a reply gate.
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['max_polygon_cells' => 'not a cellset']);

        $this->assertFalse(
            $this->service()->isWithinMaxReach($msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1])
        );
    }

    public function test_populate_fails_the_fill_when_the_rasteriser_is_unreachable(): void
    {
        // The grid IS the stored form, so a failed rasterise must not mark the
        // row filled - it stays a candidate for the next pass.
        $msgid = $this->seedRipplingPost();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(null, 500)]);

        $stats = $this->service()->populate();

        $this->assertSame(0, $stats['filled']);
        $this->assertNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('max_polygon_cells'),
            'a failed rasterise must leave the row unfilled for the next pass'
        );
    }


    public function test_max_reach_prefers_the_stored_label_verdict(): void
    {
        // The cells say INSIDE_TICK1 is within reach; the stored label knows
        // better (the far bank of an estuary) and its verdict decides.
        $msgid = $this->seedRipplingPost();
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'label-bytes']);
        \Illuminate\Support\Facades\Http::fake(['*reach-eval*' => \Illuminate\Support\Facades\Http::response([
            'results' => [['msgid' => $msgid, 'verdict' => 'out']],
        ])]);

        $this->assertFalse($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK1[0], self::INSIDE_TICK1[1]
        ), 'an OUT label verdict must override in-reach cells');

        // The gate asks about the EVENTUAL reach, so the label is evaluated at
        // its own full budget, not the current tick.
        \Illuminate\Support\Facades\Http::assertSent(
            fn ($req) => str_contains($req->url(), 'reach-eval') && ($req['budget'] ?? '') === 'max'
        );
    }

    public function test_max_reach_keeps_the_cells_verdict_without_a_label(): void
    {
        $msgid = $this->seedRipplingPost();
        $this->service()->populate();
        \Illuminate\Support\Facades\Http::fake(['*reach-eval*' => \Illuminate\Support\Facades\Http::response([
            'results' => [['msgid' => $msgid, 'verdict' => 'nolabels']],
        ])]);

        $this->assertTrue($this->service()->isWithinMaxReach(
            $msgid, self::INSIDE_TICK3_ONLY[0], self::INSIDE_TICK3_ONLY[1]
        ), 'with no stored label the max cell grid decides');
    }


    public function test_populate_fills_cumulative_users_for_labelled_rows_without_a_grid(): void
    {
        $msgid = $this->seedRipplingPost();
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'label-bytes']);

        $stats = $this->service()->populate();

        $this->assertSame(1, $stats['labelled_cumulative']);
        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertSame(4000, (int) $row->max_cumulative_users, 'the schedule final tick audience feeds the nudge');
        $this->assertNull($row->max_polygon_cells, 'a labelled row needs no grid - its label answers the gate');
    }

    public function test_indexed_candidate_scan_skips_labelled_rows(): void
    {
        // has_max_reach is generated from max_polygon_cells alone, so without
        // an explicit exclusion the indexed sweep would re-select - and
        // re-fill - every labelled row whose drained grid it just NULLed.
        $msgid = $this->seedRipplingPost();
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'label-bytes']);

        \App\Services\Ripple\MaxReachCandidateIndex::fake(ready: true);
        try {
            $stats = $this->service()->populate();
            $this->assertSame(0, $stats['filled'], 'a labelled row must not have its max grid rewritten');
            $this->assertNull(
                DB::table('rippling_reach')->where('msgid', $msgid)->value('max_polygon_cells')
            );
        } finally {
            \App\Services\Ripple\MaxReachCandidateIndex::reset();
        }
    }
}
