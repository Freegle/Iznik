<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Message;
use App\Services\Ripple\ReachQueryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesRingIndex;
use Tests\TestCase;

class ReachQueryServiceTest extends TestCase
{
    use FakesRingIndex;

    // A box covering lng [-0.2, 0.0], lat [51.4, 51.6].
    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_reach');
        // The ring question is answered by the spatial index now, for every surface
        // at once. The fake answers from the rows each test seeds, so a test still
        // says what it said - only the route to the answer changed.
        $this->fakeRingIndex();
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

    /**
     * The rural-access ring as a third way to be reply-eligible.
     *
     * This is the place where missing the ring would be most obviously wrong: a member who can
     * SEE a post on browse but is told they are too far away to reply to it has been shown
     * something they cannot use.
     */
    private function seedRing(int $msgid, string $band = 'sparse'): void
    {
        // Well outside the reach polygon the seed above uses.
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'overflow_bounds' => json_encode([
                'rural' => [$band => 'POLYGON((0.5 51.9,1.5 51.9,1.5 52.5,0.5 52.5,0.5 51.9))'],
                'bbox' => [0.5, 51.9, 1.5, 52.5],
            ]),
        ]);
    }

    /**
     * The cluster-anchor wedges as a way to be reply-eligible.
     *
     * The website's reply gate reads these wedges (rippling.ViewerOverflowPaths in the Go
     * side), so this path must too. If it did not, a member could reply from the site but
     * have the identical reply, sent by replying to the notification email, held instead -
     * and because a cluster-anchored post's reach never grows, that hold would never release.
     */
    private function seedClusterWedges(int $msgid): void
    {
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'overflow_bounds' => json_encode([
                'cluster' => [
                    'w1' => 'POLYGON((0.5 51.9,1.5 51.9,1.5 52.5,0.5 52.5,0.5 51.9))',
                    'w2' => 'POLYGON((-3.5 53.9,-2.5 53.9,-2.5 54.5,-3.5 54.5,-3.5 53.9))',
                ],
                'bbox' => [-3.5, 51.9, 1.5, 54.5],
            ]),
        ]);
    }

    public function test_cluster_wedge_admits_a_replier_the_website_would_accept(): void
    {
        config(['freegle.ripple.cluster.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedClusterWedges($msgid);

        $svc = new ReachQueryService();
        // No band passed at all: the wedges are unconditional, because they sit beyond every
        // band's ceiling and gating them on a band would refuse the town members they exist for.
        $this->assertTrue($svc->isWithinReach($msgid, 52.0, 1.0), 'first wedge admits');
        $this->assertTrue($svc->isWithinReach($msgid, 54.0, -3.0), 'a later wedge admits too');
    }

    /**
     * Most of the UK sits at a negative longitude, and these coordinates bind as strings.
     * A string compared against a JSON number is not compared numerically, so an uncast
     * bbox prefilter rejects everyone west of Greenwich while appearing to work east of it -
     * a bug that hides behind any fixture built at a positive longitude.
     */
    public function test_ring_admits_at_a_negative_longitude(): void
    {
        config(['freegle.ripple.cluster.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedClusterWedges($msgid);

        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($msgid, 54.0, -3.0), 'a wedge west of Greenwich admits');
    }

    public function test_cluster_wedge_does_not_admit_when_the_lane_is_off(): void
    {
        config(['freegle.ripple.cluster.enabled' => false]);
        $msgid = $this->seedReach();
        $this->seedClusterWedges($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0));
    }

    public function test_cluster_wedge_does_not_admit_a_point_outside_every_wedge(): void
    {
        config(['freegle.ripple.cluster.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedClusterWedges($msgid);

        $svc = new ReachQueryService();
        // Inside the shared bbox but in neither wedge - the bbox is only a prefilter, so the
        // exact test behind it still has to reject.
        $this->assertFalse($svc->isWithinReach($msgid, 53.0, -1.0));
    }

    public function test_cluster_admissions_are_counted_under_their_own_lane(): void
    {
        config(['freegle.ripple.cluster.enabled' => true, 'freegle.ripple.rural_access.enabled' => false]);
        $msgid = $this->seedReach();
        $this->seedClusterWedges($msgid);

        (new ReachQueryService())->isWithinReach($msgid, 52.0, 1.0);

        $this->assertDatabaseHas('rippling_event_metrics', [
            'day' => now()->toDateString(),
            'event' => 'cluster_admitted',
        ]);
    }

    public function test_ring_does_not_admit_when_the_lane_is_off(): void
    {
        config(['freegle.ripple.rural_access.enabled' => false]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0, 'sparse'));
    }

    public function test_ring_admits_a_member_of_that_band(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid);

        $svc = new ReachQueryService();
        // Same point, same ring, same post as the test above - only the flag differs.
        $this->assertTrue($svc->isWithinReach($msgid, 52.0, 1.0, 'sparse'));
    }

    public function test_ring_admissions_are_counted_by_day_and_lane(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid);
        DB::table('rippling_event_metrics')->where('event', 'rural_admitted')->delete();

        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($msgid, 52.0, 1.0, 'sparse'));
        $this->assertTrue($svc->isWithinReach($msgid, 52.0, 1.0, 'sparse'));

        // Two admissions, one row, today's date: the answer to "how many did
        // the lane let in today". Admissions via the committed reach must not
        // count - only the ring path does.
        $row = DB::table('rippling_event_metrics')->where('event', 'rural_admitted')->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->count);
        $this->assertSame(now()->toDateString(), (string) $row->day);

        $inside = DB::table('rippling_event_metrics')->where('event', 'rural_admitted')->value('count');
        $this->assertTrue($svc->isWithinReach($msgid, 51.5, -0.1, 'sparse')); // inside the committed reach
        $this->assertSame((int) $inside, (int) DB::table('rippling_event_metrics')->where('event', 'rural_admitted')->value('count'));
    }

    public function test_ring_does_not_admit_a_member_of_another_band(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid, 'sparse');

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0, 'dense'));
    }

    public function test_ring_does_not_admit_a_member_with_no_band(): void
    {
        // Absent must mean "not eligible", not "matches anything", or switching the lane on
        // would widen replies for the whole membership at once.
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0, null));
    }

    public function test_ring_admits_through_the_multi_location_wrapper(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        $msgid = $this->seedReach();
        $this->seedRing($msgid);

        $svc = new ReachQueryService();
        // The band has to survive the loop, which is exactly the kind of argument that gets
        // dropped when a parameter is threaded through a wrapper.
        $this->assertTrue($svc->isWithinReachAny($msgid, [[52.0, 1.0]], 'sparse'));
        $this->assertFalse($svc->isWithinReachAny($msgid, [[52.0, 1.0]], null));
    }

    /**
     * The deprivation lane, on reply eligibility. Same rules as the mail and the browse feed:
     * a person let in by one and not the others is shown a post they cannot reply to.
     */
    private function seedFairnessRing(int $msgid, string $q = '1'): void
    {
        DB::table('rippling_reach')->where('msgid', $msgid)->update([
            'overflow_bounds' => json_encode([
                'fairness' => [$q => 'POLYGON((0.5 51.9,1.5 51.9,1.5 52.5,0.5 52.5,0.5 51.9))'],
                'bbox' => [0.5, 51.9, 1.5, 52.5],
            ]),
        ]);
    }

    public function test_fairness_ring_admits_someone_in_the_target_fifth(): void
    {
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintile*' => Http::response(['quintile' => 1, 'available' => true])]));
        $msgid = $this->seedReach();
        $this->seedFairnessRing($msgid);

        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($msgid, 52.0, 1.0));
    }

    public function test_fairness_ring_does_not_admit_someone_outside_the_target_fifth(): void
    {
        // Same point, same ring. Containment gets them considered; the fifth decides. Without
        // this the lane is just a wider reach for everybody.
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintile*' => Http::response(['quintile' => 4, 'available' => true])]));
        $msgid = $this->seedReach();
        $this->seedFairnessRing($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0));
    }

    public function test_fairness_ring_admits_nobody_when_deprivation_is_unavailable(): void
    {
        // Fails closed: admitting everyone inside a stretched ring is the very thing the fifth
        // exists to prevent.
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintile*' => Http::response(null, 500)]));
        $msgid = $this->seedReach();
        $this->seedFairnessRing($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0));
    }

    public function test_fairness_ring_is_not_consulted_when_the_lane_is_off(): void
    {
        config(['freegle.ripple.fairness.enabled' => false]);
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintile*' => Http::response(['quintile' => 1, 'available' => true])]));
        $msgid = $this->seedReach();
        $this->seedFairnessRing($msgid);

        $svc = new ReachQueryService();
        $this->assertFalse($svc->isWithinReach($msgid, 52.0, 1.0));
        Http::assertNothingSent();
    }

    public function test_a_covered_person_never_costs_a_deprivation_lookup(): void
    {
        // The ring is only consulted once the reach proper has said no, so a post that already
        // covers someone must not pay for a network call on every check.
        config(['freegle.ripple.fairness.enabled' => true]);
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintile*' => Http::response(['quintile' => 1, 'available' => true])]));
        $msgid = $this->seedReach();
        $this->seedFairnessRing($msgid);

        $svc = new ReachQueryService();
        $this->assertTrue($svc->isWithinReach($msgid, 51.5, -0.1));
        Http::assertNothingSent();
    }
}
