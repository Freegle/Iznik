<?php

namespace Tests\Feature\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ripple:backfill-inner-bounds — the one-shot repair for rows whose inner bound is
 * missing or uselessly small (the town-core-fragment inners behind the Aug 2026 db3
 * saturation). Dry-run reports without touching rows; a real run re-derives the inner
 * from the stored polygon, one row at a time, and leaves useful inners and degraded
 * (completed-post) rows alone.
 */
class BackfillInnerBoundsCommandTest extends TestCase
{
    // Comfortably larger than the ±0.002° derivation tolerance.
    private const WKT = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    // ~0.002° square in the middle of WKT: correct (⊆ polygon) but useless (~0.01% area).
    private const TINY_INNER = 'POLYGON((-0.101 51.499, -0.099 51.499, -0.099 51.501, -0.101 51.501, -0.101 51.499))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    /** Seed a message + reach row with the given inner bound state; returns the msgid. */
    private function seedReach(?string $innerWkt): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: inner bounds fixture (London)',
            'textbody' => 'Inner bounds fixture.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        DB::statement(
            'INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, inner_bound, arrival, mode, tick,
                total_ticks, total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ' . ReachBoundsService::outerExpr('ST_GeomFromText(?, 3857)') . ', '
                . ($innerWkt !== null ? 'ST_GeomFromText(?, 3857)' : 'NULL') . ",
                NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, 'done', NOW(), NOW())",
            $innerWkt !== null
                ? [$message->id, self::WKT, self::WKT, $innerWkt]
                : [$message->id, self::WKT, self::WKT]
        );

        return (int) $message->id;
    }

    private function innerRatio(int $msgid): float
    {
        return (float) DB::selectOne(
            'SELECT COALESCE(ST_Area(inner_bound) / NULLIF(ST_Area(polygon), 0), 0) AS r
               FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->r;
    }

    public function test_dry_run_reports_candidates_without_changing_rows(): void
    {
        $tiny = $this->seedReach(self::TINY_INNER);
        $missing = $this->seedReach(null);

        $this->artisan('ripple:backfill-inner-bounds', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertLessThan(0.5, $this->innerRatio($tiny), 'dry-run leaves the tiny inner in place');
        $this->assertSame(0.0, $this->innerRatio($missing), 'dry-run leaves the missing inner NULL');
    }

    public function test_execute_fixes_bad_rows_and_leaves_good_and_degraded_rows_alone(): void
    {
        $tiny = $this->seedReach(self::TINY_INNER);
        $missing = $this->seedReach(null);

        $good = $this->seedReach(null);
        (new ReachBoundsService())->syncFromPolygon($good);
        $goodBefore = DB::selectOne(
            'SELECT ST_AsBinary(inner_bound) AS b FROM rippling_reach WHERE msgid = ?',
            [$good]
        )->b;

        $degraded = $this->seedReach(null);
        (new ReachBoundsService())->degradeForCompleted($degraded);

        $this->artisan('ripple:backfill-inner-bounds')->assertExitCode(0);

        $this->assertGreaterThan(0.5, $this->innerRatio($tiny), 'tiny inner is re-derived from the polygon');
        $this->assertGreaterThan(0.5, $this->innerRatio($missing), 'missing inner is derived from the polygon');

        $goodAfter = DB::selectOne(
            'SELECT ST_AsBinary(inner_bound) AS b FROM rippling_reach WHERE msgid = ?',
            [$good]
        )->b;
        $this->assertSame($goodBefore, $goodAfter, 'a useful inner is not rewritten');

        $deg = DB::selectOne(
            'SELECT ST_GeometryType(outer_bound) AS ot, inner_bound IS NULL AS inner_null
               FROM rippling_reach WHERE msgid = ?',
            [$degraded]
        );
        $this->assertSame('POINT', $deg->ot, 'completed-post degraded outer stays a point');
        $this->assertSame(1, (int) $deg->inner_null, 'completed-post inner stays NULL');
    }

    public function test_limit_bounds_the_number_of_fixes(): void
    {
        $first = $this->seedReach(self::TINY_INNER);
        $second = $this->seedReach(self::TINY_INNER);

        $this->artisan('ripple:backfill-inner-bounds', ['--limit' => 1])->assertExitCode(0);

        $ratios = [$this->innerRatio($first), $this->innerRatio($second)];
        sort($ratios);
        $this->assertLessThan(0.5, $ratios[0], 'the row beyond --limit is untouched');
        $this->assertGreaterThan(0.5, $ratios[1], 'exactly one row is fixed under --limit=1');
    }

    public function test_nothing_to_do_is_a_clean_noop(): void
    {
        $good = $this->seedReach(null);
        (new ReachBoundsService())->syncFromPolygon($good);

        $this->artisan('ripple:backfill-inner-bounds')->assertExitCode(0);
    }
}
