<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\ReachQueryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

class DrainDedupedBlobsCommandTest extends TestCase
{
    use FakesRingIndex;

    private const WKT1 = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    private const WKT2 = 'POLYGON((1.0 52.0, 1.3 52.0, 1.3 52.3, 1.0 52.3, 1.0 52.0))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
        $this->fakeRingIndex();
    }

    private function seedRow(string $polyWkt, ?string $maxPolyWkt, string $status = 'done'): int
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, max_polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857),"
            . ($maxPolyWkt !== null ? 'ST_GeomFromText(?, 3857)' : 'NULL')
            . ", ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL,
                     ?, ?, ?)",
            array_values(array_filter([
                $msgid, $polyWkt, $maxPolyWkt, $polyWkt, $status, now()->subDay(), now()->subDay(),
            ], static fn ($v) => $v !== null))
        );

        return $msgid;
    }

    private function polygonIsSentinel(int $msgid): bool
    {
        return (int) DB::selectOne(
            'SELECT ' . GeomShareService::drainedExpr('rippling_reach', 'polygon') . ' AS d
               FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->d === 1;
    }

    public function test_drains_a_backfilled_done_row(): void
    {
        $msgid = $this->seedRow(self::WKT1, self::WKT2, 'done');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');
        GeomShareService::upsertFromRow($msgid, 'max_polygon');
        GeomShareService::rehashFromRow($msgid, 'max_polygon');
        $before = DB::table('rippling_reach')->where('msgid', $msgid)->value('updated_at');

        $this->artisan('ripple:drain-deduped-blobs')->assertExitCode(0);

        $row = DB::table('rippling_reach')->where('msgid', $msgid)->first();
        $this->assertNull($row->max_polygon, 'max_polygon is fully nulled');
        $this->assertTrue($this->polygonIsSentinel($msgid), 'polygon became the sentinel POINT');
        $this->assertNotNull($row->polygon_hash);
        $this->assertNotNull($row->max_polygon_hash);
        $this->assertSame((string) $before, (string) $row->updated_at, 'updated_at is held still');
    }

    public function test_after_drain_is_within_reach_still_answers_correctly(): void
    {
        $msgid = $this->seedRow(self::WKT1, null, 'done');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $this->artisan('ripple:drain-deduped-blobs')->assertExitCode(0);
        $this->assertTrue($this->polygonIsSentinel($msgid));

        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($msgid, 51.5, -0.1), 'the COALESCE join still answers correctly once the blob is gone');
        $this->assertFalse($svc->isWithinReach($msgid, 55.0, -3.0));
    }

    public function test_a_row_that_fails_the_verification_guard_is_refused(): void
    {
        $msgid = $this->seedRow(self::WKT1, null, 'done');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');
        // Simulate a missed write site: blob changed, hash left stale.
        DB::statement('UPDATE rippling_reach SET polygon = ST_GeomFromText(?, 3857) WHERE msgid = ?', [self::WKT2, $msgid]);

        $this->artisan('ripple:drain-deduped-blobs')
            ->expectsOutputToContain('refused')
            ->assertExitCode(0);

        $this->assertFalse($this->polygonIsSentinel($msgid), 'the guard refused - the stale row is left untouched');
    }

    public function test_expanding_rows_are_not_candidates(): void
    {
        $msgid = $this->seedRow(self::WKT1, null, 'expanding');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $this->artisan('ripple:drain-deduped-blobs')->assertExitCode(0);

        $this->assertFalse($this->polygonIsSentinel($msgid), 'an actively expanding post is churn, not a candidate');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $msgid = $this->seedRow(self::WKT1, null, 'done');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $this->artisan('ripple:drain-deduped-blobs', ['--dry-run' => true])->assertExitCode(0);

        $this->assertFalse($this->polygonIsSentinel($msgid));
    }

    public function test_already_drained_rows_are_not_re_counted(): void
    {
        $msgid = $this->seedRow(self::WKT1, null, 'done');
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        $this->artisan('ripple:drain-deduped-blobs')
            ->expectsOutputToContain('Drained 1 row')
            ->assertExitCode(0);

        // Re-scan the same range explicitly (bypassing the advanced mark): the
        // already-drained row must be skipped, not re-reported as drained.
        $this->artisan('ripple:drain-deduped-blobs', ['--after' => 0])
            ->expectsOutputToContain('Drained 0 row')
            ->assertExitCode(0);
    }
}
