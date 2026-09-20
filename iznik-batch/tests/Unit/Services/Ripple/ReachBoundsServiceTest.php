<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sandwich bounds for the stored reach grid (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md;
 * derived from polygon_cells via the spatial server's trace since the raster storage change).
 *
 * The invariants under test are the ones that make the bounds safe to consult
 * before the reach itself:
 *   - outer_bound ⊇ reach (a viewer outside outer_bound is definitely out of reach)
 *   - inner_bound ⊆ reach, or NULL (a viewer inside inner_bound is definitely in reach)
 * Anything the deriver cannot verify must fall back (envelope / NULL), never ship
 * an unverified bound, and never throw into the calling tick.
 *
 * The invariant checks below compare against the seed WKT the grid was built
 * from. The grid's cell boundaries can jut up to half a lattice cell
 * (~0.00015 degrees) past that WKT, which is an order of magnitude inside the
 * +/-0.002-degree buffer the derivation applies, so the WKT stands in for the
 * reach exactly for these assertions.
 */
class ReachBoundsServiceTest extends TestCase
{
    use \Tests\Support\SeedsReachCells;

    /** @var array<int,string> seed WKT per msgid, for the invariant checks. */
    private array $seededWkt = [];

    // A ~0.1° square around central London: comfortably larger than the ±0.002°
    // simplify/buffer tolerance, so both derived bounds are non-degenerate.
    private const WKT = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    private function service(): ReachBoundsService
    {
        return new ReachBoundsService();
    }

    /** Seed a message + rippling_reach row whose reach is the given WKT; returns the msgid. */
    private function seedReach(string $wkt): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bounds fixture (London)',
            'textbody' => 'Bounds fixture.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor($wkt), $wkt]
        );
        $this->seededWkt[(int) $message->id] = $wkt;

        return (int) $message->id;
    }

    private function boundsRow(int $msgid): ?object
    {
        return DB::selectOne(
            'SELECT msgid,
                    ST_GeometryType(outer_bound) AS outer_type,
                    inner_bound IS NULL AS inner_null
               FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        );
    }

    public function test_sync_derives_verified_sandwich_bounds(): void
    {
        $msgid = $this->seedReach(self::WKT);

        $this->service()->syncFromPolygon($msgid);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row, 'a bounds row is written for the reach');

        // The safety invariants: outer contains the exact polygon; inner (when present)
        // is contained by it.
        $check = DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o,
                    (inner_bound IS NULL OR ST_Contains(ST_GeomFromText(?, 3857), inner_bound)) AS i
               FROM rippling_reach WHERE msgid = ?',
            [$this->seededWkt[$msgid], $this->seededWkt[$msgid], $msgid]
        );
        $this->assertSame(1, (int) $check->o, 'outer_bound must contain the reach');
        $this->assertSame(1, (int) $check->i, 'inner_bound must be NULL or inside the reach');

        // For a clean simple polygon the full derivation should succeed, giving a
        // real (non-NULL) inner bound — not just the envelope fallback.
        $this->assertSame(0, (int) $row->inner_null, 'clean polygon derives a usable inner bound');
    }

    public function test_sync_is_an_upsert(): void
    {
        $msgid = $this->seedReach(self::WKT);

        $this->service()->syncFromPolygon($msgid);
        $this->service()->syncFromPolygon($msgid);

        $this->assertNotNull($this->boundsRow($msgid), 'the reach row still carries bounds');
        // A no-change re-sync must not degrade the bounds (INSERT..ON DUPLICATE reports
        // 0 affected rows for identical values — that is not a failure).
        $this->assertSame(
            0,
            (int) $this->boundsRow($msgid)->inner_null,
            'an idempotent re-sync keeps the derived inner bound'
        );
    }

    public function test_sync_survives_invalid_polygon_with_safe_fallbacks(): void
    {
        // Self-intersecting bowtie: MySQL stores it, but GIS functions may throw on it
        // (~94% of production reach polygons are technically invalid). The deriver must
        // not throw, and whatever it stores must still be a safe superset (worst case
        // the envelope) with inner_bound NULLed.
        $msgid = $this->seedReach('POLYGON((-0.2 51.4, 0.0 51.6, 0.0 51.4, -0.2 51.6, -0.2 51.4))');

        $this->service()->syncFromPolygon($msgid);

        $row = $this->boundsRow($msgid);
        if ($row !== null) {
            // MBR containment is well-defined even for invalid geometry: the stored
            // outer bound must at least cover the polygon's extent.
            $check = DB::selectOne(
                'SELECT MBRContains(outer_bound, ST_GeomFromText(?, 3857)) AS o
                   FROM rippling_reach WHERE msgid = ?',
                [$this->seededWkt[$msgid], $msgid]
            );
            $this->assertSame(1, (int) $check->o, 'fallback outer bound covers the reach extent');
        } else {
            // No row at all is also acceptable — readers fall back to the exact test.
            $this->assertNull($row);
        }
    }

    public function test_sync_for_missing_reach_row_writes_nothing_and_does_not_throw(): void
    {
        $this->service()->syncFromPolygon(999999999);

        $this->assertNull($this->boundsRow(999999999));
    }

    public function test_degrade_for_completed_collapses_outer_and_nulls_inner(): void
    {
        // Completed posts are pruned from the candidate set via the BOUNDS row only —
        // the exact polygon must stay untouched (digest "came and went", held replies
        // to taken posts and un-completion all still read it).
        $msgid = $this->seedReach(self::WKT);
        $this->service()->syncFromPolygon($msgid);

        $this->service()->degradeForCompleted($msgid);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row);
        $this->assertSame('POINT', $row->outer_type, 'degraded outer bound is a degenerate point');
        $this->assertSame(1, (int) $row->inner_null, 'degraded bounds carry no inner accept');

        // The reach grid itself is untouched.
        $cells = DB::table('rippling_reach')->where('msgid', $msgid)->value('polygon_cells');
        $this->assertNotNull($cells, 'degrading the bounds must not touch the stored reach');
    }

    public function test_sync_with_provided_bounds_stores_them_after_verification(): void
    {
        // The routing server derives bounds on its rasterisation grid and ships them with
        // the catchment; sync() prefers those over deriving in MySQL. With no origin-group
        // polyindex in the fixture, verified provided bounds are stored verbatim.
        $msgid = $this->seedReach(self::WKT);
        $outer = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';
        $inner = 'POLYGON((-0.19 51.41, -0.01 51.41, -0.01 51.59, -0.19 51.59, -0.19 51.41))';

        $this->service()->sync($msgid, $outer, $inner);

        $check = DB::selectOne(
            'SELECT ST_Equals(outer_bound, ST_GeomFromText(?, 3857)) AS oe,
                    ST_Equals(inner_bound, ST_GeomFromText(?, 3857)) AS ie
               FROM rippling_reach WHERE msgid = ?',
            [$outer, $inner, $msgid]
        );
        $this->assertNotNull($check, 'provided bounds are stored');
        $this->assertSame(1, (int) $check->oe, 'verified provided outer is stored verbatim');
        $this->assertSame(1, (int) $check->ie, 'verified provided inner is stored verbatim');
    }

    public function test_sync_with_bad_provided_outer_falls_back_safely(): void
    {
        // A provided outer that does NOT contain the stored polygon (e.g. the polygon was
        // unioned with a group area the tick bound never saw) must not survive
        // verification; the fallback still satisfies the superset invariant.
        $msgid = $this->seedReach(self::WKT);
        $badOuter = 'POLYGON((5 5, 5.1 5, 5.1 5.1, 5 5.1, 5 5))';

        $this->service()->sync($msgid, $badOuter, null);

        $ok = DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o
               FROM rippling_reach WHERE msgid = ?',
            [$this->seededWkt[$msgid], $msgid]
        );
        $this->assertNotNull($ok, 'a bounds row is still written');
        $this->assertSame(1, (int) $ok->o, 'fallback outer contains the stored reach');
    }

    public function test_sync_without_provided_bounds_derives_in_sql(): void
    {
        // No routing bounds (old cached schedule, full-form schedule, old server) →
        // sync() falls back to the SQL derivation path.
        $msgid = $this->seedReach(self::WKT);

        $this->service()->sync($msgid, null, null);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row, 'bounds are derived in SQL when none are provided');
        $this->assertSame(0, (int) $row->inner_null, 'SQL derivation produces an inner bound for a clean polygon');
    }

    public function test_sync_after_degrade_restores_real_bounds(): void
    {
        // Un-completion (reopened post) must be able to restore working bounds from the
        // stored polygon alone — no routing call.
        $msgid = $this->seedReach(self::WKT);
        $this->service()->syncFromPolygon($msgid);
        $this->service()->degradeForCompleted($msgid);

        $this->service()->syncFromPolygon($msgid);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row);
        $this->assertNotSame('POINT', $row->outer_type, 'reopened post gets real bounds back');
    }

    /** The area the stored inner bound covers, as a share of the reach's area. */
    private function innerRatio(int $msgid): float
    {
        return (float) DB::selectOne(
            'SELECT COALESCE(ST_Area(inner_bound) / NULLIF(ST_Area(ST_GeomFromText(?, 3857)), 0), 0) AS r
               FROM rippling_reach WHERE msgid = ?',
            [$this->seededWkt[$msgid], $msgid]
        )->r;
    }

    public function test_sync_replaces_uselessly_small_provided_inner(): void
    {
        // A provided inner can be CORRECT (⊆ polygon) yet useless: the routing grid's
        // 3-cell erosion disintegrates ribbon-shaped rural reaches, leaving a town-core
        // fragment covering 1–2% of the polygon. Every viewer between that fragment and
        // the outer bound then pays the full 178KB polygon test — the db3 CPU saturation
        // of Aug 2026. Verified-but-tiny inners must be replaced by one derived from the
        // stored polygon.
        $msgid = $this->seedReach(self::WKT);
        $outer = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';
        $tinyInner = 'POLYGON((-0.101 51.499, -0.099 51.499, -0.099 51.501, -0.101 51.501, -0.101 51.499))';

        $this->service()->sync($msgid, $outer, $tinyInner);

        $this->assertGreaterThan(
            0.5,
            $this->innerRatio($msgid),
            'a verified-but-tiny provided inner is replaced by a polygon-derived one'
        );
        $check = DB::selectOne(
            'SELECT ST_Contains(ST_GeomFromText(?, 3857), inner_bound) AS i,
                    ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o
               FROM rippling_reach WHERE msgid = ?',
            [$this->seededWkt[$msgid], $this->seededWkt[$msgid], $msgid]
        );
        $this->assertSame(1, (int) $check->i, 'the replacement inner still satisfies inner inside the reach');
        $this->assertSame(1, (int) $check->o, 'the verified provided outer is kept');
    }

    public function test_sync_derives_inner_when_none_provided(): void
    {
        // Routing ships no inner when erosion leaves nothing usable. Previously that
        // stored NULL (no cheap accept, full polygon test for every in-outer viewer);
        // now the inner is derived from the stored polygon instead.
        $msgid = $this->seedReach(self::WKT);
        $outer = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';

        $this->service()->sync($msgid, $outer, null);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->inner_null, 'missing provided inner is derived from the polygon');
        $this->assertGreaterThan(0.5, $this->innerRatio($msgid), 'the derived inner is useful, not a sliver');
    }

    public function test_sync_derives_inner_after_nulling_unverifiable_provided_inner(): void
    {
        // An inner that pokes outside the polygon (possible after clips, or a routing
        // bug) must fail write-time verification and never ship as a cheap-accept; the
        // replacement is derived from the polygon rather than left NULL (usefulness).
        // Replaces the pre-guard test_sync_with_bad_provided_inner_nulls_it, whose
        // "ends as NULL" assertion described the behaviour this guard exists to remove.
        $msgid = $this->seedReach(self::WKT);
        $outer = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';
        $badInner = 'POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))'; // ⊃ polygon

        $this->service()->sync($msgid, $outer, $badInner);

        $check = DB::selectOne(
            'SELECT inner_bound IS NULL AS inner_null,
                    (inner_bound IS NULL OR ST_Contains(ST_GeomFromText(?, 3857), inner_bound)) AS i,
                    ST_Equals(inner_bound, ST_GeomFromText(?, 3857)) AS still_bad,
                    ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS o
               FROM rippling_reach WHERE msgid = ?',
            [$this->seededWkt[$msgid], $badInner, $this->seededWkt[$msgid], $msgid]
        );
        $this->assertSame(0, (int) $check->inner_null, 'a safe inner is derived to replace the rejected one');
        $this->assertSame(1, (int) $check->i, 'the derived inner satisfies inner inside the reach');
        $this->assertSame(0, (int) $check->still_bad, 'the rejected provided inner is not what is stored');
        $this->assertSame(1, (int) $check->o, 'the good provided outer is kept');
    }

    public function test_ensure_useful_inner_keeps_a_good_inner_untouched(): void
    {
        $msgid = $this->seedReach(self::WKT);
        $this->service()->syncFromPolygon($msgid);
        $before = DB::selectOne(
            'SELECT ST_AsBinary(inner_bound) AS b FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->b;

        $this->assertSame('kept', $this->service()->ensureUsefulInner($msgid));

        $after = DB::selectOne(
            'SELECT ST_AsBinary(inner_bound) AS b FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        )->b;
        $this->assertSame($before, $after, 'a useful inner is not rewritten');
    }

    public function test_ensure_useful_inner_skips_degraded_completed_rows(): void
    {
        // degradeForCompleted deliberately collapses the bounds to prune the post from
        // the browse R-tree; the usefulness guard must never resurrect an inner there.
        $msgid = $this->seedReach(self::WKT);
        $this->service()->syncFromPolygon($msgid);
        $this->service()->degradeForCompleted($msgid);

        $this->assertSame('skipped', $this->service()->ensureUsefulInner($msgid));

        $row = $this->boundsRow($msgid);
        $this->assertSame('POINT', $row->outer_type, 'degraded outer stays a point');
        $this->assertSame(1, (int) $row->inner_null, 'degraded inner stays NULL');
    }
}
