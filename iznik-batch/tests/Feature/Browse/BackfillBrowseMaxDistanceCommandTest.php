<?php

namespace Tests\Feature\Browse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The backfill puts each member on the travel-time budget their own density band
 * justifies, and keeps settings.browseMaxDistance (the derived cap the feed and
 * digest filters read) consistent with settings.browseMaxMinutes (the slider's
 * source of truth).
 *
 * Both halves matter. The reconciliation stops a stale radius filtering to a cap
 * the slider no longer shows; the band default is what admits each member to the
 * wider ripple on their OWN terms, without which a city member would start being
 * mailed posts 45 minutes away.
 */
class BackfillBrowseMaxDistanceCommandTest extends TestCase
{
    private const UNLIMITED = 9007199254740991;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.ripple.max_minutes' => 30,
            'freegle.ripple.density.enabled' => true,
            'freegle.ripple.density.k' => 400,
            'freegle.ripple.density.dense_max_miles' => 1.6,
            'freegle.ripple.density.medium_max_miles' => 3.1,
            'freegle.ripple.density.max_minutes.dense' => 20,
            'freegle.ripple.density.max_minutes.medium' => 30,
            'freegle.ripple.density.max_minutes.sparse' => 45,
        ]);
    }

    /**
     * Fake both endpoints the command uses: the spatial KNN behind the density band,
     * and the routing-backed town/near behind the derived radius. $count freeglers
     * with the furthest $miles away (due north, so the great-circle conversion is
     * the unambiguous one) decides the band.
     */
    private function fakeLookups(int $count, float $miles, float $radiusMiles = 8.5, float $lat = 53.4, float $lng = -1.3): void
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = ($miles * ($i + 1) / $count) / 69.05;
            $results[] = ['id' => $i + 1, 'extra' => ['lat' => $lat + $offset, 'lng' => $lng]];
        }

        Http::fake([
            '*userapproxlocs*' => Http::response(['results' => $results]),
            '*town/near*' => Http::response(['reach_radius_miles' => $radiusMiles]),
        ]);
    }

    /** A member in a medium band: 30-minute cap, so stored minutes carry across unchanged. */
    private function fakeMedium(float $radiusMiles = 8.5): void
    {
        $this->fakeLookups(400, 2.5, $radiusMiles);
    }

    private function userWith(array $settings, ?float $lat = 53.4, ?float $lng = -1.3, ?string $lastaccess = null): int
    {
        $locationId = null;
        if ($lat !== null) {
            $locationId = DB::table('locations')->insertGetId([
                'name' => 'TestLoc'.uniqid(),
                'type' => 'Postcode',
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }

        $row = [
            'fullname' => 'Backfill Test '.uniqid(),
            'systemrole' => 'User',
            'lastlocation' => $locationId,
            'settings' => json_encode($settings),
        ];
        if ($lastaccess !== null) {
            $row['lastaccess'] = $lastaccess;
        }

        return (int) DB::table('users')->insertGetId($row);
    }

    private function settingsOf(int $id): array
    {
        return json_decode(DB::table('users')->where('id', $id)->value('settings'), true) ?: [];
    }

    /** The inbound cap actually in force: their own choice if they made one, else the band default. */
    private function distanceOf(int $id): mixed
    {
        $s = $this->settingsOf($id);

        return $s['browseMaxDistance'] ?? $s['browseReachMaxDistance'] ?? null;
    }

    /** browseMaxDistance ONLY - the key that also caps how far away others see their posts. */
    private function chosenDistanceOf(int $id): mixed
    {
        return $this->settingsOf($id)['browseMaxDistance'] ?? null;
    }

    private function minutesOf(int $id): mixed
    {
        return $this->settingsOf($id)['browseMaxMinutes'] ?? null;
    }

    private function townNearCalls(): int
    {
        $n = 0;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'town/near')) {
                $n++;
            }
        }

        return $n;
    }

    public function testRecomputesAStaleCapFromTheRoutingEndpoint(): void
    {
        $this->fakeMedium();
        // The live case: slider at 25 minutes, cap stuck at a stale 1 mile.
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->distanceOf($id));
    }

    public function testLeavesAConsistentPairAlone(): void
    {
        $this->fakeMedium();
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 8.2]);

        // 0.3 miles inside the default 0.5 epsilon - not worth churning.
        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.2, $this->distanceOf($id));
    }

    public function testFillsAMissingCapForACapWantingSlider(): void
    {
        $this->fakeMedium(3.7);
        $id = $this->userWith(['browseMaxMinutes' => 10]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(3.7, $this->distanceOf($id));
    }

    public function testTopStopBelowTheCeilingStoresARealRadiusNotTheSentinel(): void
    {
        // The sentinel defers to the server's own reach, which now grows to the
        // CEILING - so for a band below it the sentinel would hand this member the
        // widest band's reach instead of their own.
        $this->fakeMedium(13.2);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => 12]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(13.2, $this->distanceOf($id));
        $this->assertSame(30, $this->minutesOf($id));
    }

    public function testSkipsWhenTheRoutingLookupFails(): void
    {
        Http::fake([
            '*userapproxlocs*' => Http::response(['results' => [
                ['id' => 1, 'extra' => ['lat' => 53.43, 'lng' => -1.3]],
            ]]),
            '*town/near*' => Http::response(null, 500),
        ]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        // Never clobber on a lookup blip - the stale value stays until a run
        // that can recompute it honestly.
        $this->assertSame(1, $this->distanceOf($id));
    }

    public function testSkipsWhenDensityCannotBeMeasured(): void
    {
        // An empty spatial index and a genuinely empty area look identical from here.
        // Guessing a band would hand the member a cap nobody chose.
        Http::fake([
            '*userapproxlocs*' => Http::response(['results' => []]),
            '*town/near*' => Http::response(['reach_radius_miles' => 8.5]),
        ]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(1, $this->distanceOf($id));
        $this->assertSame(25, $this->minutesOf($id));
        $this->assertSame(0, $this->townNearCalls());
    }

    public function testSkipsUsersWithNoKnownLocation(): void
    {
        $this->fakeMedium();
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1], null, null);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(1, $this->distanceOf($id));
        Http::assertNothingSent();
    }

    public function testOverridesALegacyMilesOnlyCapToTheBandCap(): void
    {
        $this->fakeMedium();
        // Pre-2026-07-10 miles-slider write: distance set, no minutes. The
        // time-based slider shows this member "no limit" - storage must match.
        $id = $this->userWith(['browseMaxDistance' => 5]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->distanceOf($id));
        $this->assertSame(30, $this->minutesOf($id));
    }

    public function testALegacyUnlimitedRowBelowTheCeilingIsBroughtBackToItsBand(): void
    {
        // 'Unlimited' used to mean the flat 30 the ripple grew to. It now means the
        // ceiling, so a medium-band member left on it would quietly gain the widest
        // band's reach - the exact flooding the recipient cap exists to prevent.
        $this->fakeMedium(13.2);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(13.2, $this->distanceOf($id));
        $this->assertSame(30, $this->minutesOf($id));
    }

    public function testASparseRowAtTheCeilingKeepsTheSentinel(): void
    {
        // At the ceiling 'defer to the server's own reach' is exactly right: the
        // ripple grows no further than this member is willing to travel.
        $this->fakeLookups(400, 16.1);
        $id = $this->userWith(['browseMaxMinutes' => 45, 'browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(self::UNLIMITED, $this->distanceOf($id));
        $this->assertSame(0, $this->townNearCalls());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->fakeMedium();
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance --dry-run')->assertSuccessful();

        $this->assertSame(1, $this->distanceOf($id));
    }

    public function testPreservesUnrelatedSettingsKeys(): void
    {
        $this->fakeMedium();
        $id = $this->userWith([
            'browseMaxMinutes' => 25,
            'browseMaxDistance' => 1,
            'browseView' => 'mygroups',
            'browseSort' => 'Newest',
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $settings = $this->settingsOf($id);
        $this->assertSame(8.5, $settings['browseMaxDistance']);
        $this->assertSame('mygroups', $settings['browseView']);
        $this->assertSame('Newest', $settings['browseSort']);
    }

    /**
     * The case that started this: a rural member at the old top stop. Their band
     * earns 45 minutes, so the top stop now means 45 - and still means "no limit",
     * deferring to the server's own reach rather than a radius that would narrow it.
     */
    public function testARuralMemberAtTheOldTopStopMovesUpToTheirBandCap(): void
    {
        $this->fakeLookups(400, 16.1);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(45, $this->minutesOf($id));
        $this->assertSame(self::UNLIMITED, $this->distanceOf($id));
    }

    /**
     * A city member at the old top stop lands on 20, not 30. Without this the wider
     * ripple would reach them with everything inside 45 minutes of a post.
     */
    public function testACityMemberAtTheOldTopStopComesDownToTheirBandCap(): void
    {
        $this->fakeLookups(400, 0.9, 7.4);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(20, $this->minutesOf($id));
        $this->assertSame(7.4, $this->distanceOf($id));
    }

    /**
     * A member who never touched the slider has no preference to reconcile, but they
     * are exactly who needs the default: the ripple now grows past what their band
     * justifies, and this is what holds them to it.
     */
    public function testGivesAnUntouchedActiveMemberTheirBandDefault(): void
    {
        $this->fakeLookups(400, 0.9, 7.4);
        $id = $this->userWith([], 53.4, -1.3, now()->subDays(3)->toDateTimeString());

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(20, $this->minutesOf($id));
        $this->assertSame(7.4, $this->distanceOf($id));
    }

    public function testLeavesLongDormantMembersAlone(): void
    {
        $this->fakeLookups(400, 0.9);
        $id = $this->userWith([], 53.4, -1.3, now()->subDays(400)->toDateTimeString());

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertNull($this->minutesOf($id));
    }

    public function testSinceDaysZeroTouchesOnlyMembersWhoAlreadyChose(): void
    {
        $this->fakeLookups(400, 0.9);
        $untouched = $this->userWith([], 53.4, -1.3, now()->subDays(3)->toDateTimeString());

        $this->artisan('browse:backfill-max-distance --since-days=0')->assertSuccessful();

        $this->assertNull($this->minutesOf($untouched));
    }

    /**
     * An explicit narrower choice is a fraction of the range the member was shown, not
     * an absolute travel time they would recognise. 15 was two fifths of the way up the
     * old 5-30; two fifths of a rural 5-45 is 20. Carrying 15 across unchanged would
     * quietly narrow them at the moment the reach engine got wider.
     */
    public function testRescalesAnExplicitChoiceOntoTheNewRange(): void
    {
        $this->fakeLookups(400, 16.1, 12.4);
        $id = $this->userWith(['browseMaxMinutes' => 15, 'browseMaxDistance' => 6]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(20, $this->minutesOf($id));
        $this->assertSame(12.4, $this->distanceOf($id));
    }

    public function testRescalingSnapsToTheSlidersStep(): void
    {
        $command = app(\App\Console\Commands\Browse\BackfillBrowseMaxDistanceCommand::class);

        // Rural range 5-45: the ends pin, the interior snaps to fives.
        $this->assertSame(5, $command->rescale(5, 45));
        $this->assertSame(45, $command->rescale(30, 45));
        $this->assertSame(20, $command->rescale(15, 45));
        $this->assertSame(30, $command->rescale(20, 45));

        // City range 5-20: every position lands at or below the cap.
        $this->assertSame(5, $command->rescale(5, 20));
        $this->assertSame(15, $command->rescale(20, 20));
        $this->assertSame(20, $command->rescale(30, 20));

        // A stored value past the old top (an older bundle, a hand-edited setting)
        // is still just "as far as I can".
        $this->assertSame(45, $command->rescale(60, 45));

        // No stored value at all means the top stop.
        $this->assertSame(45, $command->rescale(null, 45));
    }

    /**
     * A street of members must not cost a spatial call and a routing call each: on
     * live this pass covers 121,000 people.
     */
    public function testMemoizesLookupsAcrossCoLocatedMembers(): void
    {
        $this->fakeLookups(400, 16.1, 12.4);
        $this->userWith(['browseMaxMinutes' => 15, 'browseMaxDistance' => 6]);
        $this->userWith(['browseMaxMinutes' => 15, 'browseMaxDistance' => 6]);
        $this->userWith(['browseMaxMinutes' => 15, 'browseMaxDistance' => 6]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(1, $this->townNearCalls());
    }

    /**
     * The outbound half of the preference. browseMaxDistance also caps how far away other
     * people see a member's OWN posts, so the band default must never be written there:
     * a city member's ~4.8-mile radius would stop their posts travelling at all, which is
     * the opposite of what growing the ripple to the ceiling is for.
     */
    public function testTheBandDefaultNeverCreatesAnOutboundCap(): void
    {
        $this->fakeLookups(400, 0.9, 7.4);
        $id = $this->userWith([], 53.4, -1.3, now()->subDays(3)->toDateTimeString());

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertNull($this->chosenDistanceOf($id), 'no outbound cap may be invented');
        $this->assertSame(7.4, $this->settingsOf($id)['browseReachMaxDistance']);
    }

    public function testAnExplicitChoiceKeepsBeingTheOutboundCap(): void
    {
        // Someone who moved the slider chose both halves; we reconcile that key rather
        // than leaving it stale beside a new default.
        $this->fakeMedium(8.5);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->chosenDistanceOf($id));
        $this->assertArrayNotHasKey('browseReachMaxDistance', $this->settingsOf($id));
    }
}