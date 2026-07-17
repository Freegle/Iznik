<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sandwich bounds for rippling_reach.polygon (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
 *
 * The invariants under test are the ones that make the bounds safe to consult
 * before (or instead of) the exact polygon:
 *   - outer_bound ⊇ polygon (a viewer outside outer_bound is definitely out of reach)
 *   - inner_bound ⊆ polygon, or NULL (a viewer inside inner_bound is definitely in reach)
 * Anything the deriver cannot verify must fall back (envelope / NULL), never ship
 * an unverified bound, and never throw into the calling tick.
 */
class ReachBoundsServiceTest extends TestCase
{
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

    /** Seed a message + rippling_reach row with the given polygon; returns the msgid. */
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
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $wkt]
        );

        return (int) $message->id;
    }

    private function boundsRow(int $msgid): ?object
    {
        return DB::selectOne(
            'SELECT msgid,
                    ST_GeometryType(outer_bound) AS outer_type,
                    inner_bound IS NULL AS inner_null
               FROM rippling_reach_bounds WHERE msgid = ?',
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
            'SELECT ST_Contains(b.outer_bound, rr.polygon) AS o,
                    (b.inner_bound IS NULL OR ST_Contains(rr.polygon, b.inner_bound)) AS i
               FROM rippling_reach_bounds b
               JOIN rippling_reach rr ON rr.msgid = b.msgid
              WHERE b.msgid = ?',
            [$msgid]
        );
        $this->assertSame(1, (int) $check->o, 'outer_bound must contain the exact polygon');
        $this->assertSame(1, (int) $check->i, 'inner_bound must be NULL or inside the exact polygon');

        // For a clean simple polygon the full derivation should succeed, giving a
        // real (non-NULL) inner bound — not just the envelope fallback.
        $this->assertSame(0, (int) $row->inner_null, 'clean polygon derives a usable inner bound');
    }

    public function test_sync_is_an_upsert(): void
    {
        $msgid = $this->seedReach(self::WKT);

        $this->service()->syncFromPolygon($msgid);
        $this->service()->syncFromPolygon($msgid);

        $this->assertSame(
            1,
            (int) DB::table('rippling_reach_bounds')->where('msgid', $msgid)->count(),
            'repeated syncs keep a single bounds row'
        );
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
                'SELECT MBRContains(b.outer_bound, rr.polygon) AS o
                   FROM rippling_reach_bounds b
                   JOIN rippling_reach rr ON rr.msgid = b.msgid
                  WHERE b.msgid = ?',
                [$msgid]
            );
            $this->assertSame(1, (int) $check->o, 'fallback outer bound covers the polygon extent');
        } else {
            // No row at all is also acceptable — readers fall back to the exact test.
            $this->assertNull($row);
        }
    }

    public function test_sync_for_missing_reach_row_writes_nothing_and_does_not_throw(): void
    {
        $this->service()->syncFromPolygon(999999999);

        $this->assertSame(
            0,
            (int) DB::table('rippling_reach_bounds')->where('msgid', 999999999)->count()
        );
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

        // The exact polygon is untouched.
        $poly = DB::selectOne(
            'SELECT ST_GeometryType(polygon) AS t FROM rippling_reach WHERE msgid = ?',
            [$msgid]
        );
        $this->assertSame('POLYGON', $poly->t);
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
            'SELECT ST_Equals(b.outer_bound, ST_GeomFromText(?, 3857)) AS oe,
                    ST_Equals(b.inner_bound, ST_GeomFromText(?, 3857)) AS ie
               FROM rippling_reach_bounds b WHERE b.msgid = ?',
            [$outer, $inner, $msgid]
        );
        $this->assertNotNull($check, 'provided bounds are stored');
        $this->assertSame(1, (int) $check->oe, 'verified provided outer is stored verbatim');
        $this->assertSame(1, (int) $check->ie, 'verified provided inner is stored verbatim');
    }

    public function test_sync_with_bad_provided_inner_nulls_it(): void
    {
        // A provided inner that pokes outside the stored polygon (possible after clips, or
        // a routing bug) must fail write-time verification and be dropped — never shipped
        // as a cheap-accept.
        $msgid = $this->seedReach(self::WKT);
        $outer = 'POLYGON((-0.21 51.39, 0.01 51.39, 0.01 51.61, -0.21 51.61, -0.21 51.39))';
        $badInner = 'POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))'; // ⊃ polygon

        $this->service()->sync($msgid, $outer, $badInner);

        $row = $this->boundsRow($msgid);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->inner_null, 'unverifiable provided inner is NULLed');
        $ok = DB::selectOne(
            'SELECT ST_Contains(b.outer_bound, rr.polygon) AS o
               FROM rippling_reach_bounds b JOIN rippling_reach rr ON rr.msgid = b.msgid
              WHERE b.msgid = ?',
            [$msgid]
        );
        $this->assertSame(1, (int) $ok->o, 'the good provided outer is kept');
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
            'SELECT ST_Contains(b.outer_bound, rr.polygon) AS o
               FROM rippling_reach_bounds b JOIN rippling_reach rr ON rr.msgid = b.msgid
              WHERE b.msgid = ?',
            [$msgid]
        );
        $this->assertNotNull($ok, 'a bounds row is still written');
        $this->assertSame(1, (int) $ok->o, 'fallback outer contains the stored polygon');
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
}
