<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ReachBoundsService::syncFromPolygon / ensureUsefulInner (and, inside them,
 * verifySandwich) derive the sandwich bounds from "the polygon" via
 * GeomShareService::sourceExpr - the shared row when the hash points at one,
 * the blob otherwise. The proof needed for the dedup: on a DRAINED row (blob
 * replaced by the sentinel POINT(0 0), hash pointing at the real geometry in
 * rippling_reach_geom), the derived bounds must still describe the REAL
 * polygon - covering its area, containing points inside it - not a
 * degenerate point-shaped bound around the sentinel. A join that silently
 * fell back to the blob on a drained row would produce bounds that are a
 * single point (or a buffer of one), which is unmissable against a real
 * ~0.2deg-square polygon.
 */
class GeomShareReachBoundsMatrixTest extends TestCase
{
    // A ~0.2° square around central London - comfortably larger than the
    // ±0.002° simplify/buffer tolerance, same fixture as ReachBoundsServiceTest.
    private const WKT = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        GeomShareService::forgetReady();
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM rippling_reach_geom');
    }

    private function service(): ReachBoundsService
    {
        return new ReachBoundsService();
    }

    /** Seed a message + rippling_reach row with WKT, in the given dedup state. */
    private function seedReach(string $state): int
    {
        $user = $this->createTestUser();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: bounds dedup fixture (London)',
            'textbody' => 'Bounds dedup fixture.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => 51.5,
            'lng' => -0.1,
        ]);
        $msgid = (int) $message->id;

        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 90, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, self::WKT, self::WKT]
        );

        if ($state === 'undeduped') {
            return $msgid;
        }

        // Hashed from the REAL bytes, exactly as the writers do: the geometry is
        // deduped BEFORE it is ever drained.
        GeomShareService::upsertFromRow($msgid, 'polygon');
        GeomShareService::rehashFromRow($msgid, 'polygon');

        if ($state === 'drained') {
            DB::statement(
                "UPDATE rippling_reach SET polygon = ST_GeomFromText('POINT(0 0)', 3857) WHERE msgid = ?",
                [$msgid]
            );
        }

        return $msgid;
    }

    private function assertSyncDerivesFromRealGeometry(string $state): void
    {
        $msgid = $this->seedReach($state);

        $this->service()->syncFromPolygon($msgid);

        $row = DB::selectOne(
            'SELECT ST_GeometryType(outer_bound) AS outer_type,
                    ST_Contains(outer_bound, ST_GeomFromText(?, 3857)) AS covers_real
               FROM rippling_reach WHERE msgid = ?',
            [self::WKT, $msgid]
        );
        $this->assertNotSame(
            'POINT',
            $row->outer_type,
            "state={$state}: outer_bound must not degrade to a point - that only happens for completed posts"
        );
        $this->assertSame(
            1,
            (int) $row->covers_real,
            "state={$state}: outer_bound must contain the REAL polygon's full area, proving it was derived from the shared geometry and not the sentinel"
        );
    }

    public function test_sync_from_polygon_derives_real_bounds_when_undeduped(): void
    {
        $this->assertSyncDerivesFromRealGeometry('undeduped');
    }

    public function test_sync_from_polygon_derives_real_bounds_when_deduped(): void
    {
        $this->assertSyncDerivesFromRealGeometry('deduped');
    }

    public function test_sync_from_polygon_derives_real_bounds_when_drained(): void
    {
        $this->assertSyncDerivesFromRealGeometry('drained');
    }

    private function assertEnsureUsefulInnerDerivesFromRealGeometry(string $state): void
    {
        $msgid = $this->seedReach($state);
        $svc = $this->service();
        $svc->syncFromPolygon($msgid); // establishes outer_bound (and a first inner)

        // Force the re-derive branch so the outcome is directly comparable across
        // all three states, rather than depending on whether the first inner from
        // syncFromPolygon happened to already clear the usefulness bar.
        DB::statement('UPDATE rippling_reach SET inner_bound = NULL WHERE msgid = ?', [$msgid]);

        $outcome = $svc->ensureUsefulInner($msgid);
        $this->assertSame(
            'derived',
            $outcome,
            "state={$state}: a missing inner on a healthy (non-degraded) row must be re-derived"
        );

        $row = DB::selectOne(
            'SELECT inner_bound IS NULL AS missing,
                    ST_Contains(ST_GeomFromText(?, 3857), inner_bound) AS contained
               FROM rippling_reach WHERE msgid = ?',
            [self::WKT, $msgid]
        );
        $this->assertSame(0, (int) $row->missing, "state={$state}: inner_bound must be filled in, not left null");
        $this->assertSame(
            1,
            (int) $row->contained,
            "state={$state}: inner_bound must be CONTAINED BY the REAL polygon - an inner derived from the sentinel would not verify against it and would be nulled instead"
        );
    }

    public function test_ensure_useful_inner_derives_from_real_geometry_when_undeduped(): void
    {
        $this->assertEnsureUsefulInnerDerivesFromRealGeometry('undeduped');
    }

    public function test_ensure_useful_inner_derives_from_real_geometry_when_deduped(): void
    {
        $this->assertEnsureUsefulInnerDerivesFromRealGeometry('deduped');
    }

    public function test_ensure_useful_inner_derives_from_real_geometry_when_drained(): void
    {
        $this->assertEnsureUsefulInnerDerivesFromRealGeometry('drained');
    }
}
