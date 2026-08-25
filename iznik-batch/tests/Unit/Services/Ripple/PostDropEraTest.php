<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\CellSetService;
use App\Services\Ripple\LegacyGeometry;
use App\Services\Ripple\ReachBoundsService;
use App\Services\Ripple\ReachQueryService;
use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

/**
 * The CELLS-ONLY era: what every reach read does once polygon, max_polygon and
 * overflow_bounds have been dropped (plans/2026-08-24-rippling-reach-raster-
 * storage.md Stage 3).
 *
 * Both eras have to be covered, and the rest of the suite covers the
 * transition one - which is the code production actually runs first, for as
 * long as the backfill takes, so it must not be the era that loses its tests.
 * These tests force the era guard instead of dropping the columns, which works
 * precisely BECAUSE the cells-only branches never name a dropped column: the
 * SQL they emit is valid against a schema that still has them, and asserting
 * that is the point. The migration proves the schema half by running.
 *
 * Every test here seeds a row whose polygon and cells describe the SAME shape,
 * so a disagreement is a bug in the cells-only branch rather than a difference
 * in the fixture.
 */
class PostDropEraTest extends TestCase
{
    use FakesRingIndex;

    /** A box covering lng [-0.2, 0.0], lat [51.4, 51.6]. */
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    /** A smaller box wholly inside POLY, for the max-reach pair. */
    private const INNER_POLY = 'POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))';

    private CellSetService $cells;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        $this->fakeRingIndex();
        $this->cells = new CellSetService();

        // Pretend the legacy columns are gone. The schema still has them, so a
        // branch that wrongly reads one would SUCCEED here rather than error -
        // which is why these tests assert on ANSWERS, not on the absence of an
        // exception. The query-shape assertions at the bottom cover the other
        // half: that no dropped column is named at all.
        LegacyGeometry::fake(polygon: false, overflow: false);
    }

    protected function tearDown(): void
    {
        LegacyGeometry::reset();
        parent::tearDown();
    }

    /**
     * A reach row with BOTH forms describing the same shape. The cells come
     * from the real rasteriser, so these are the bytes production would hold.
     */
    private function seedReach(bool $withMax = false): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: lamp (London)',
            'textbody' => 'A lamp.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $cells = $this->cells->rasterize(self::POLY);
        if ($cells === null) {
            $this->markTestSkipped('The spatial rasteriser is not reachable; these tests need real cell bytes.');
        }

        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ?,
                     ST_Buffer(ST_Simplify(ST_GeomFromText(?, 3857), 0.002), 0.002),
                     NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::POLY, $cells, self::POLY]
        );

        if ($withMax) {
            $maxCells = $this->cells->rasterize(self::POLY);
            $nowCells = $this->cells->rasterize(self::INNER_POLY);
            DB::statement(
                'UPDATE rippling_reach SET polygon_cells = ?, max_polygon_cells = ? WHERE msgid = ?',
                [$nowCells, $maxCells, $message->id]
            );
        }

        return (int) $message->id;
    }

    // ---- the reply gate ----

    public function test_reply_gate_admits_a_point_inside_from_cells_alone(): void
    {
        $msgid = $this->seedReach();
        $this->assertTrue((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    public function test_reply_gate_refuses_a_point_outside_from_cells_alone(): void
    {
        $msgid = $this->seedReach();
        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 52.0, 1.0));
    }

    /**
     * Corrupt bytes must HOLD the reply (fail closed), not admit it. Pre-drop
     * this fell back to the polygon; post-drop there is nothing to fall back
     * to, and admitting on unreadable bytes would be the unsafe direction.
     */
    public function test_reply_gate_fails_closed_on_unreadable_cells(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET polygon_cells = ? WHERE msgid = ?', ['not a cell set', $msgid]);

        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    public function test_reply_gate_refuses_when_the_row_has_no_cells(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET polygon_cells = NULL WHERE msgid = ?', [$msgid]);

        $this->assertFalse((new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1));
    }

    // ---- the first-reply passthrough gate ----

    public function test_max_reach_gate_reads_both_grids(): void
    {
        $msgid = $this->seedReach(withMax: true);
        $svc = app(\App\Services\FirstReply\MaxReachService::class);

        // Inside the CURRENT reach (the inner box).
        $this->assertTrue($svc->isWithinMaxReach($msgid, 51.5, -0.1));
        // Outside the current reach but inside the EVENTUAL one - the whole
        // point of this gate.
        $this->assertTrue($svc->isWithinMaxReach($msgid, 51.42, -0.18));
        // Outside both.
        $this->assertFalse($svc->isWithinMaxReach($msgid, 52.0, 1.0));
    }

    // ---- how far outside the reach a held replier is ----

    public function test_miles_outside_reach_comes_from_the_grid(): void
    {
        $msgid = $this->seedReach();
        $svc = new RippleReplyService(new ReachQueryService());

        $method = new \ReflectionMethod($svc, 'milesOutsideReach');
        $method->setAccessible(true);

        // A point inside is zero miles outside.
        $this->assertSame(0.0, $method->invoke($svc, $msgid, 51.5, -0.1));

        // A point well east of the box is a positive, sane distance. The box
        // ends at lng 0.0; lng 0.5 at this latitude is ~35km away.
        $miles = $method->invoke($svc, $msgid, 51.5, 0.5);
        $this->assertNotNull($miles);
        $this->assertGreaterThan(15, $miles);
        $this->assertLessThan(30, $miles);
    }

    // ---- the sandwich bounds, re-derived from the grid ----

    public function test_bounds_are_derived_from_the_traced_grid(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL WHERE msgid = ?',
            ['POLYGON((-0.01 51.49, 0.0 51.49, 0.0 51.5, -0.01 51.5, -0.01 51.49))', $msgid]);

        (new ReachBoundsService())->syncFromPolygon($msgid);

        // Whatever it derived, the outer bound must CONTAIN the reach: that is
        // the superset guarantee every degraded read path depends on.
        $row = DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS ok FROM rippling_reach WHERE msgid = ?',
            [self::POLY, $msgid]
        );
        $this->assertSame(1, (int) $row->ok, 'outer_bound must be a superset of the reach after re-derivation');
    }

    /**
     * With no cells AND no polygon there is nothing to derive from. The bounds
     * must be left alone (a stale outer is safe-loose) and the inner cleared
     * (a stale inner would cheap-accept people who are no longer covered).
     */
    public function test_bounds_derivation_clears_the_inner_when_it_cannot_measure(): void
    {
        $msgid = $this->seedReach();
        DB::statement('UPDATE rippling_reach SET polygon_cells = NULL, inner_bound = ST_GeomFromText(?, 3857) WHERE msgid = ?',
            [self::INNER_POLY, $msgid]);

        (new ReachBoundsService())->syncFromPolygon($msgid);

        $inner = DB::table('rippling_reach')->where('msgid', $msgid)->value('inner_bound');
        $this->assertNull($inner, 'an unmeasurable reach must lose its cheap-accept bound');
    }

    // ---- the shape assertions: no dropped column may be named ----

    /**
     * The other half of the guarantee. Forcing the era guard proves the
     * ANSWERS are right, but only reading the emitted SQL proves the branch
     * would not have crashed on a schema where the columns are actually gone.
     * These are the queries a post-drop deploy runs first.
     */
    public function test_no_cells_only_read_names_a_dropped_column(): void
    {
        $msgid = $this->seedReach(withMax: true);

        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        (new ReachQueryService())->isWithinReach($msgid, 51.5, -0.1);
        app(\App\Services\FirstReply\MaxReachService::class)->isWithinMaxReach($msgid, 51.5, -0.1);
        (new ReachBoundsService())->syncFromPolygon($msgid);

        $this->assertNotEmpty($statements, 'expected the reads to issue SQL');

        $dropped = ['polygon_hash', 'max_polygon_hash', 'rippling_reach_geom', 'overflow_bounds'];
        foreach ($statements as $sql) {
            foreach ($dropped as $col) {
                $this->assertStringNotContainsString(
                    $col,
                    $sql,
                    "a cells-only read still names the dropped {$col}: {$sql}"
                );
            }
            // `polygon` needs care: polygon_cells and max_polygon_cells
            // legitimately contain the word, so match the bare column only.
            $this->assertDoesNotMatchRegularExpression(
                '/\bpolygon\b(?!_cells)/',
                $sql,
                "a cells-only read still names the dropped polygon column: {$sql}"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bmax_polygon\b(?!_cells)/',
                $sql,
                "a cells-only read still names the dropped max_polygon column: {$sql}"
            );
        }
    }
}
