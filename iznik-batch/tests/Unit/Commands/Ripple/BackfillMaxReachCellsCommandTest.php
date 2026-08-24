<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These tests make REAL calls to the spatial server's rasterise endpoint
 * (App\Services\Ripple\CellSetService::rasterize) - the command exists
 * specifically to backfill rows the write path never touched, so faking the
 * network call would prove nothing about whether it actually works end to
 * end. If the spatial server is unreachable in whatever environment runs
 * this, every "fills" assertion below fails loudly rather than passing on a
 * null - that is the point.
 */
class BackfillMaxReachCellsCommandTest extends TestCase
{
    private const TICK3 = 'POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))';

    private const CONFIG_KEY_MARK = 'ripple_backfill_max_reach_cells_last_msgid';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        DB::table('config')->where('key', self::CONFIG_KEY_MARK)->delete();
    }

    /** A row whose eventual reach (max_polygon) predates this feature - no cells yet. */
    private function seedRowNeedingBackfill(): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, max_polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_GeomFromText(?, 3857),
                     ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 4000, 30, NULL,
                     'done', ?, ?)",
            [$msgid, self::TICK3, self::TICK3, self::TICK3, now()->subDays(2), now()->subDays(2)]
        );

        return $msgid;
    }

    private function cellsFor(int $msgid): ?string
    {
        return DB::table('rippling_reach')->where('msgid', $msgid)->value('max_polygon_cells');
    }

    public function test_fills_a_row_whose_max_polygon_predates_the_feature(): void
    {
        $msgid = $this->seedRowNeedingBackfill();

        $this->artisan('ripple:backfill-max-reach-cells')->assertExitCode(0);

        $cells = $this->cellsFor($msgid);
        $this->assertNotNull($cells);

        $cellSets = app(CellSetService::class);
        $decoded = $cellSets->decode($cells);
        $this->assertTrue($cellSets->contains($decoded, 0.8, 51.9), 'inside TICK3');
        $this->assertFalse($cellSets->contains($decoded, -3.0, 55.0), 'outside TICK3');
    }

    public function test_a_row_already_filled_is_not_a_candidate(): void
    {
        $msgid = $this->seedRowNeedingBackfill();
        $this->artisan('ripple:backfill-max-reach-cells')->assertExitCode(0);
        $firstFill = $this->cellsFor($msgid);
        $this->assertNotNull($firstFill);

        // --reset-mark so this checks candidate EXCLUSION (max_polygon_cells IS
        // NOT NULL) specifically, independent of the mark the first run already
        // advanced past this msgid - which would otherwise report "nothing left
        // after msgid X" for an unrelated reason.
        $this->artisan('ripple:backfill-max-reach-cells --dry-run --reset-mark')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);

        $this->assertSame($firstFill, $this->cellsFor($msgid), 'left untouched, not re-rasterised');
    }

    public function test_a_drained_row_is_still_a_candidate_via_the_hash(): void
    {
        $msgid = $this->seedRowNeedingBackfill();

        // Simulate the polygon-dedup drain (plans/2026-08-23-...): blob gone,
        // only the hash remains - "a max reach is known" must still hold.
        GeomShareService::upsertFromWkt(self::TICK3);
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'max_polygon_hash' => DB::selectOne(
                'SELECT UNHEX(MD5(ST_AsBinary(ST_GeomFromText(?, 3857)))) AS h', [self::TICK3]
            )->h,
            'max_polygon' => null,
        ]);

        $this->artisan('ripple:backfill-max-reach-cells')->assertExitCode(0);

        $cells = $this->cellsFor($msgid);
        $this->assertNotNull($cells, 'a drained row (hash only) must still be rasterised from the shared geometry');
    }

    public function test_dry_run_fills_nothing(): void
    {
        $msgid = $this->seedRowNeedingBackfill();

        $this->artisan('ripple:backfill-max-reach-cells --dry-run')->assertExitCode(0);

        $this->assertNull($this->cellsFor($msgid));
    }

    public function test_mark_resumes_and_after_does_not_move_it(): void
    {
        $a = $this->seedRowNeedingBackfill();
        $b = $this->seedRowNeedingBackfill();
        $this->assertGreaterThan($a, $b);

        $this->artisan("ripple:backfill-max-reach-cells --limit=1")->assertExitCode(0);
        $this->assertNotNull($this->cellsFor($a));
        $this->assertNull($this->cellsFor($b), 'the limit-1 run must not have reached the second row yet');

        $mark = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->value('value');
        $this->assertSame((string) $a, $mark);

        // A real (non --after) run picks up exactly where the mark left off.
        $this->artisan('ripple:backfill-max-reach-cells --limit=1')->assertExitCode(0);
        $this->assertNotNull($this->cellsFor($b));

        // An --after run must not move the stored mark.
        DB::table('rippling_reach')->where('msgid', $b)->update(['max_polygon_cells' => null]);
        $markBefore = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->value('value');
        $this->artisan("ripple:backfill-max-reach-cells --after=0 --limit=1")->assertExitCode(0);
        $markAfter = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->value('value');
        $this->assertSame($markBefore, $markAfter, '--after must not disturb the stored mark');
    }

    public function test_no_candidates_at_all_reports_nothing_rather_than_erroring(): void
    {
        // No rows seeded: a genuinely empty table is "nothing to do", not a
        // failure - distinct from the migration-missing guard (checked via
        // information_schema in the command itself; not exercised here since
        // this worktree's schema always has the column by this point).
        $this->artisan('ripple:backfill-max-reach-cells --dry-run')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);
    }
}
