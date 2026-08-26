<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real calls to the spatial server's rasterise endpoint, for the same reason
 * BackfillReachCellsCommandTest makes them: this command exists to convert
 * rings the write path will never touch again, so a faked network call would
 * prove nothing about whether it works.
 */
class BackfillRingCellsCommandTest extends TestCase
{
    private const REACH = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const SPARSE_RING = 'POLYGON((-1.5 50.5, 1.5 50.5, 1.5 52.5, -1.5 52.5, -1.5 50.5))';

    private const WEDGE_RING = 'POLYGON((2.0 50.5, 2.5 50.5, 2.5 51.0, 2.0 51.0, 2.0 50.5))';

    private const CONFIG_KEY_MARK = 'ripple_backfill_ring_cells_last_msgid';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        DB::table('config')->where('key', self::CONFIG_KEY_MARK)->delete();
    }

    /** A ringed row written before this feature: rings present, no cells. */
    private function seedRingedRow(?array $rings = null): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        $bounds = $rings ?? [
            'rural' => ['sparse' => self::SPARSE_RING],
            'cluster' => ['w1' => self::WEDGE_RING],
            // A scalar member, which must NOT be mirrored into the cells.
            'bbox' => [-1.5, 50.5, 2.5, 52.5],
        ];

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, overflow_bounds, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857),
                     ST_Envelope(ST_GeomFromText(?, 3857)), ?, NOW(), 'drive', 1, 3, 4000, 30, NULL,
                     'done', ?, ?)",
            [$msgid, self::REACH, self::REACH, json_encode($bounds), now()->subDays(2), now()->subDays(2)]
        );

        return $msgid;
    }

    private function ringCellsFor(int $msgid): ?array
    {
        $raw = DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_cells');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function test_fills_every_lane_a_row_carries_on_the_same_json_paths(): void
    {
        $msgid = $this->seedRingedRow();

        $this->artisan('ripple:backfill-ring-cells')->assertExitCode(0);

        $cells = $this->ringCellsFor($msgid);
        $this->assertNotNull($cells, 'the command must actually store ring cells');
        $this->assertArrayHasKey('sparse', $cells['rural'] ?? [], 'the rural lane keeps its own path');
        $this->assertArrayHasKey('w1', $cells['cluster'] ?? [], 'the cluster lane keeps its own path');
        $this->assertArrayNotHasKey('bbox', $cells, 'a scalar member is read from overflow_bounds, not copied here');

        $cellSets = app(CellSetService::class);
        $sparse = $cellSets->decode(base64_decode($cells['rural']['sparse']));
        $this->assertTrue($cellSets->contains($sparse, 0.0, 51.5), 'a point inside the sparse ring is inside its cells');
        $this->assertFalse($cellSets->contains($sparse, 2.2, 50.7), 'the wedge is a different lane, not part of this one');

        $wedge = $cellSets->decode(base64_decode($cells['cluster']['w1']));
        $this->assertTrue($cellSets->contains($wedge, 2.2, 50.7), 'and the wedge lane holds the wedge');
    }

    public function test_the_rings_themselves_are_left_alone(): void
    {
        // overflow_bounds stays the authority: the map overlay draws it, the
        // lane-presence test reads it, and has_overflow is generated from it.
        $msgid = $this->seedRingedRow();
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_bounds');

        $this->artisan('ripple:backfill-ring-cells')->assertExitCode(0);

        $this->assertSame(
            (string) $before,
            (string) DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_bounds'),
            'the ring geometry must be untouched'
        );
        $this->assertSame(
            1,
            (int) DB::table('rippling_reach')->where('msgid', $msgid)->value('has_overflow'),
            'the generated indexed flag the ring index hangs off must still be set'
        );
    }

    public function test_a_row_with_no_rings_is_not_a_candidate(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)),
                     NOW(), 'drive', 1, 3, 4000, 30, NULL, 'done', NOW(), NOW())",
            [(int) $message->id, self::REACH, self::REACH]
        );

        $this->artisan('ripple:backfill-ring-cells')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    public function test_a_row_that_already_has_ring_cells_is_not_a_candidate(): void
    {
        $msgid = $this->seedRingedRow();
        $this->artisan('ripple:backfill-ring-cells')->assertExitCode(0);
        $this->assertNotNull($this->ringCellsFor($msgid));

        $this->artisan('ripple:backfill-ring-cells --reset-mark --dry-run')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $msgid = $this->seedRingedRow();

        $this->artisan('ripple:backfill-ring-cells --dry-run')->assertExitCode(0);

        $this->assertNull($this->ringCellsFor($msgid), 'a dry run must not store anything');
        $this->assertSame(
            0,
            DB::table('config')->where('key', self::CONFIG_KEY_MARK)->count(),
            'a dry run must not advance the resumability mark'
        );
    }

    public function test_the_backfill_does_not_disturb_updated_at(): void
    {
        $msgid = $this->seedRingedRow();
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');

        $this->artisan('ripple:backfill-ring-cells')->assertExitCode(0);

        $this->assertNotNull($this->ringCellsFor($msgid));
        $this->assertSame(
            (string) $before,
            (string) DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at'),
            'updated_at must be held still - the reach mailer and the spatial delta poll both watch it'
        );
    }

    /**
     * A row whose rings PARTLY convert must be left entirely alone.
     *
     * This is the failure that would have been permanent and silent. The
     * command used to store whatever rings rasterised and drop the ones that
     * did not, which wrote overflow_cells NOT NULL - and from there nothing
     * could tell: the compare-and-swap in handle() only revisits rows where
     * overflow_cells IS NULL, the drop migration's guard tests exactly that
     * same condition, and ripple:verify-cells-parity does not look at ring
     * cells at all (it has eight read cases, none of them overflow). So one
     * transient 400 from the rasterise endpoint lost one lane's ring, and
     * after the drop there was no WKT left to rebuild it from: that lane
     * would admit nobody, for ever, with nothing anywhere saying so.
     *
     * Leaving the row unconverted instead means the drop migration REFUSES,
     * which is the outcome worth having.
     */
    public function test_a_row_whose_rings_partly_fail_is_left_entirely_unconverted(): void
    {
        // One ring the rasteriser accepts, one it cannot parse. Both are
        // non-empty strings, so both get past the is_string guard and are
        // genuinely attempted.
        $msgid = $this->seedRingedRow([
            'rural' => ['sparse' => self::SPARSE_RING],
            'cluster' => ['w1' => 'THIS IS NOT WKT AND CANNOT BE RASTERISED'],
        ]);

        $this->artisan('ripple:backfill-ring-cells')
            ->expectsOutputToContain('failed to rasterise')
            ->assertExitCode(0);

        $this->assertNull(
            $this->ringCellsFor($msgid),
            'a partly-converted row must store NOTHING - a partial write looks converted for ever'
        );

        // And it stays a candidate, so a --reset-mark sweep can pick it up
        // once the rasteriser is healthy again.
        $this->assertNotNull(
            DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_bounds'),
            'the ring WKT must survive, since it is the only thing left to retry from'
        );
    }

    /**
     * The sibling case: EVERY ring failing is also "leave it alone", and must
     * not write an empty object either.
     */
    public function test_a_row_whose_rings_all_fail_stores_nothing(): void
    {
        $msgid = $this->seedRingedRow([
            'rural' => ['sparse' => 'NOT WKT'],
        ]);

        $this->artisan('ripple:backfill-ring-cells')->assertExitCode(0);

        $this->assertNull($this->ringCellsFor($msgid), 'an all-failed row must not store an empty object');
    }
}
