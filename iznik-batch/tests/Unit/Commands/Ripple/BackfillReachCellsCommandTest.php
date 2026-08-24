<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These tests make REAL calls to the spatial server's rasterise endpoint
 * (App\Services\Ripple\CellSetService::rasterize). The command exists
 * specifically to convert rows the write path will never touch again, so
 * faking the network call would prove nothing about whether it works end to
 * end. If the spatial server is unreachable, every "fills" assertion fails
 * loudly rather than passing on a null - that is the point.
 */
class BackfillReachCellsCommandTest extends TestCase
{
    private const REACH = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const CONFIG_KEY_MARK = 'ripple_backfill_reach_cells_last_msgid';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        DB::table('config')->where('key', self::CONFIG_KEY_MARK)->delete();
    }

    /** A row whose current reach predates this feature - no cells yet. */
    private function seedRowNeedingBackfill(string $wkt = self::REACH): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857),
                     ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 4000, 30, NULL,
                     'done', ?, ?)",
            [$msgid, $wkt, $wkt, now()->subDays(2), now()->subDays(2)]
        );

        return $msgid;
    }

    private function cellsFor(int $msgid): ?string
    {
        return DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
    }

    public function test_fills_a_row_whose_reach_predates_the_feature(): void
    {
        $msgid = $this->seedRowNeedingBackfill();

        $this->artisan('ripple:backfill-reach-cells')->assertExitCode(0);

        $cells = $this->cellsFor($msgid);
        $this->assertNotNull($cells, 'the command must actually store cells, not just report success');

        $cellSets = app(CellSetService::class);
        $decoded = $cellSets->decode($cells);
        $this->assertTrue($cellSets->contains($decoded, 0.0, 51.5), 'a point inside the reach is inside its cells');
        $this->assertFalse($cellSets->contains($decoded, 10.0, 10.0), 'a point nowhere near it is not');
    }

    public function test_a_row_that_already_has_cells_is_not_a_candidate(): void
    {
        $msgid = $this->seedRowNeedingBackfill();
        $this->artisan('ripple:backfill-reach-cells')->assertExitCode(0);
        $first = $this->cellsFor($msgid);
        $this->assertNotNull($first);

        // Second sweep from the beginning: the row is already converted, so it
        // is excluded by the candidate query rather than rasterised again.
        $this->artisan('ripple:backfill-reach-cells --reset-mark --dry-run')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $msgid = $this->seedRowNeedingBackfill();

        $this->artisan('ripple:backfill-reach-cells --dry-run')->assertExitCode(0);

        $this->assertNull($this->cellsFor($msgid), 'a dry run must not store anything');
        $this->assertSame(
            0,
            DB::table('config')->where('key', self::CONFIG_KEY_MARK)->count(),
            'a dry run must not advance the resumability mark'
        );
    }

    public function test_the_sweep_is_resumable_via_its_mark(): void
    {
        $first = $this->seedRowNeedingBackfill();
        $second = $this->seedRowNeedingBackfill();
        [$lower, $higher] = $first < $second ? [$first, $second] : [$second, $first];

        $this->artisan('ripple:backfill-reach-cells --limit=1')->assertExitCode(0);
        $this->assertNotNull($this->cellsFor($lower), 'the first run converts the lower msgid');
        $this->assertNull($this->cellsFor($higher), 'and stops there');
        $this->assertSame(
            (string) $lower,
            (string) DB::table('config')->where('key', self::CONFIG_KEY_MARK)->value('value'),
            'the mark records where it got to'
        );

        $this->artisan('ripple:backfill-reach-cells --limit=1')->assertExitCode(0);
        $this->assertNotNull($this->cellsFor($higher), 'the next run continues from the mark');
    }

    public function test_the_backfill_does_not_disturb_updated_at(): void
    {
        // A bulk reach backfill once generated 38k+ notification emails in a
        // morning by bumping this column: the reach mailer and the spatial
        // server's delta poll both watch it.
        $msgid = $this->seedRowNeedingBackfill();
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');

        $this->artisan('ripple:backfill-reach-cells')->assertExitCode(0);

        $this->assertNotNull($this->cellsFor($msgid));
        $this->assertSame(
            (string) $before,
            (string) DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at'),
            'updated_at must be held still'
        );
    }

    public function test_a_row_whose_geometry_lives_in_the_shared_table_is_still_converted(): void
    {
        // After the dedup drain the blob is a sentinel and only the hash points
        // at the real geometry. The command reads through the same COALESCE
        // join the readers use, so such a row converts from the SHARED bytes,
        // not from the sentinel.
        $msgid = $this->seedRowNeedingBackfill();
        if (!GeomShareService::ready()) {
            $this->markTestSkipped('rippling_reach_geom is not migrated in this database');
        }

        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');
        DB::statement(
            'UPDATE rippling_reach SET polygon = ST_GeomFromText(?, ?) WHERE msgid = ?',
            [GeomShareService::DRAIN_SENTINEL_WKT, GeomShareService::SRID, $msgid]
        );

        $this->artisan('ripple:backfill-reach-cells')->assertExitCode(0);

        $cells = $this->cellsFor($msgid);
        $this->assertNotNull($cells, 'a drained row must still convert');
        $cellSets = app(CellSetService::class);
        $this->assertTrue(
            $cellSets->contains($cellSets->decode($cells), 0.0, 51.5),
            'the cells must describe the SHARED geometry, not the drain sentinel'
        );
    }
}
