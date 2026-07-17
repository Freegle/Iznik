<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachQueryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReachQueryServiceTest extends TestCase
{
    // A box covering lng [-0.2, 0.0], lat [51.4, 51.6].
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
    }

    private function seedReach(): int
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
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, self::POLY, self::POLY]
        );

        return (int) $message->id;
    }

    public function test_point_inside_reach_is_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($this->seedReach(), 51.5, -0.1));
    }

    public function test_point_outside_reach_is_not_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($this->seedReach(), 52.0, 1.0));
    }

    public function test_missing_reach_row_is_not_within(): void
    {
        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach(999999999, 51.5, -0.1));
    }

    public function test_any_location_covered_returns_true_if_one_inside(): void
    {
        $msgid = $this->seedReach();
        $svc = new ReachQueryService();
        // First location outside, second inside → eligible (multiple viewer locations).
        $this->assertTrue($svc->isWithinReachAny($msgid, [[52.0, 1.0], [51.5, -0.1]]));
        // All outside → not eligible.
        $this->assertFalse($svc->isWithinReachAny($msgid, [[52.0, 1.0], [40.0, 0.0]]));
    }

    /** Adversarial sandwich bounds for the msgid (contradicting the polygon on purpose). */
    private function seedBounds(int $msgid, string $outerWkt, ?string $innerWkt): void
    {
        DB::statement(
            'UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = '
                . ($innerWkt !== null ? 'ST_GeomFromText(?, 3857)' : 'NULL') . ' WHERE msgid = ?',
            $innerWkt !== null ? [$outerWkt, $innerWkt, $msgid] : [$outerWkt, $msgid]
        );
    }

    public function test_within_reach_consults_sandwich_bounds(): void
    {
        // The single-point gate consults the sandwich bounds before the ~178KB exact
        // polygon (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). Adversarial
        // fixtures — bounds contradicting the polygon, impossible for verified
        // writer-derived bounds — are the only way to observe which shape was trusted.
        $svc = new ReachQueryService();

        // Cheap reject: polygon COVERS the point, outer_bound doesn't.
        $cheapReject = $this->seedReach();
        $this->seedBounds($cheapReject, 'POLYGON((5 5, 5.1 5, 5.1 5.1, 5 5.1, 5 5))', null);
        $this->assertFalse(
            $svc->isWithinReach($cheapReject, 51.5, -0.1),
            'a point outside outer_bound is rejected without testing the polygon'
        );

        // Cheap accept: polygon does NOT cover the point, inner_bound does.
        $cheapAccept = $this->seedReach();
        DB::statement(
            "UPDATE rippling_reach SET polygon = ST_GeomFromText(
                'POLYGON((5.0 51.4, 5.2 51.4, 5.2 51.6, 5.0 51.6, 5.0 51.4))', 3857) WHERE msgid = ?",
            [$cheapAccept]
        );
        $this->seedBounds(
            $cheapAccept,
            'POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))',
            self::POLY
        );
        $this->assertTrue(
            $svc->isWithinReach($cheapAccept, 51.5, -0.1),
            'a point inside inner_bound is accepted without testing the polygon'
        );
    }

    public function test_within_reach_band_and_degraded_bounds_use_exact_polygon(): void
    {
        $svc = new ReachQueryService();

        // Band (inside outer, no inner): the exact polygon decides.
        $bandIn = $this->seedReach();
        $this->seedBounds($bandIn, 'POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))', null);
        $this->assertTrue($svc->isWithinReach($bandIn, 51.5, -0.1), 'band falls back to the exact polygon (covered)');
        $this->assertFalse($svc->isWithinReach($bandIn, 51.55, -0.25), 'band falls back to the exact polygon (inside outer, outside polygon)');

        // Degraded (POINT outer, from completion pruning): treated as absent — the
        // exact polygon decides, so held-reply release for a taken post still works.
        $degraded = $this->seedReach();
        DB::statement(
            'UPDATE rippling_reach SET outer_bound = ST_SRID(POINT(-0.1, 51.5), 3857), inner_bound = NULL
              WHERE msgid = ?',
            [$degraded]
        );
        $this->assertTrue(
            $svc->isWithinReach($degraded, 51.5, -0.15),
            'degraded bounds fall back to the exact polygon (covered → within reach)'
        );
    }
}
