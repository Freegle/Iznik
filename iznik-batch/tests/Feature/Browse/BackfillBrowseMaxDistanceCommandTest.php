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

    /**
     * Stored minutes with no browseMaxDistance is this command's OWN output rather than a
     * choice: the slider has always written both keys together. So it is re-derived, radius
     * and all, instead of being read back as a preference.
     */
    public function testFillsAMissingCapForAMemberOnTheBandDefault(): void
    {
        $this->fakeMedium(3.7);
        $id = $this->userWith(['browseMaxMinutes' => 10]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(30, $this->minutesOf($id));
        $this->assertSame(3.7, $this->distanceOf($id));
    }

    /**
     * The ratchet's aftermath. One run walked 17,584 dense members below their 20-minute cap,
     * most of them onto 10 minutes and a 1.5 mile radius, and because the budget rule keeps
     * whatever it reads, nothing ever widened them again. Their daily digest then found no
     * posts near enough to send and stopped arriving. Putting them back is this pass's job, so
     * a member who never chose goes back on their band cap however far below it they sit.
     */
    public function testANarrowedMemberWhoNeverChoseIsPutBackOnTheirBandCap(): void
    {
        $this->fakeLookups(400, 0.9, 4.8);
        $id = $this->userWith([
            'browseMaxMinutes' => 10,
            'browseReachMaxDistance' => 1.53,
            'browseDensityBand' => 'dense',
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(20, $this->minutesOf($id), 'back on the dense cap');
        $this->assertSame(4.8, $this->distanceOf($id), 'with the radius that travel time really buys');
        $this->assertNull($this->chosenDistanceOf($id), 'and still no choice invented for them');
    }

    /**
     * A member already on their band cap is left alone, though. This runs nightly over the
     * whole membership, so a write each for the members who are already right would be
     * 130,000 pointless saves and audit rows every night.
     */
    public function testAMemberAlreadyOnTheirBandCapIsNotRewritten(): void
    {
        $this->fakeLookups(400, 0.9, 4.8);
        $this->userWith([
            'browseMaxMinutes' => 20,
            'browseReachMaxDistance' => 4.8,
            'browseDensityBand' => 'dense',
        ]);

        $this->artisan('browse:backfill-max-distance')
            ->expectsOutputToContain('1 already consistent')
            ->assertSuccessful();
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
     * A rural member who has chosen 30 minutes keeps 30 minutes, and the "no limit" sentinel
     * they were left on is replaced by the radius 30 minutes actually reaches. Below the top
     * stop "no limit" is not an answer their choice can mean, so leaving it there is what has
     * members mailed posts from towns they would never travel to (Discourse 10096).
     *
     * This pass used to WIDEN them instead, to 45 - reading 30 as the top stop of the old
     * fixed 5-30 slider. That reading is true on the day the slider's range changes and on no
     * other day: as a standing rule it widens the same member again on every run, and for a
     * band that earns the ceiling the widest stop stores the sentinel. On live 2026-09-01 that
     * put 1,185 members on "no limit" in a single night. Widening is a one-off migration's job,
     * not a reconciler's.
     */
    public function testARuralMemberKeepsTheirChoiceAndLosesTheSentinel(): void
    {
        $this->fakeLookups(400, 16.1, 17.5);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(30, $this->minutesOf($id));
        $this->assertSame(17.5, $this->distanceOf($id));
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
     * An explicit choice is the travel time the member asked for, and it survives the pass.
     * Only the derived radius is recomputed.
     */
    public function testKeepsAnExplicitChoiceAndOnlyRecomputesTheRadius(): void
    {
        $this->fakeLookups(400, 16.1, 12.4);
        $id = $this->userWith(['browseMaxMinutes' => 15, 'browseMaxDistance' => 6]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(15, $this->minutesOf($id));
        $this->assertSame(12.4, $this->distanceOf($id));
    }

    /**
     * The pass runs monthly over the whole membership, so its budget rule has to be a
     * fixed point: whatever it writes, reading it again must give the same answer.
     *
     * It was not. The rule read a stored value as a FRACTION of the old fixed 5-30 slider
     * and stretched it onto the member's 5-45 band range - right once, when the slider
     * changed, but re-applied every month it walked each member's chosen travel time away
     * from them, in whichever direction their band pointed. Live, from the 2026-09-01 run:
     * 1,185 members were pushed onto the "no limit" sentinel, and the ratchet is visible in
     * the standing data as 31,916 sparse members piled on 45 and 7,747 dense members on 10.
     */
    public function testTheBudgetRuleIsAFixedPoint(): void
    {
        $command = app(\App\Console\Commands\Browse\BackfillBrowseMaxDistanceCommand::class);

        // Rural range 5-45: a chosen travel time is exactly what it says, run after run.
        foreach ([5, 15, 20, 30, 35, 45] as $chosen) {
            $this->assertSame($chosen, $command->budgetFor($chosen, 45));
            $this->assertSame(
                $chosen,
                $command->budgetFor($command->budgetFor($chosen, 45), 45),
                'a second pass must not move a member who has already been reconciled'
            );
        }

        // City range 5-20: a position the reach engine will not honour comes down onto the
        // cap, and stays there.
        $this->assertSame(5, $command->budgetFor(5, 20));
        $this->assertSame(20, $command->budgetFor(20, 20));
        $this->assertSame(20, $command->budgetFor(30, 20));
        $this->assertSame(20, $command->budgetFor($command->budgetFor(30, 20), 20));

        // Below the bottom stop is not a position the slider can express.
        $this->assertSame(5, $command->budgetFor(1, 45));

        // No stored value at all means the top stop.
        $this->assertSame(45, $command->budgetFor(null, 45));
    }

    /**
     * The same fixed point, end to end: a full pass over an already-reconciled member must
     * leave their budget where it is. A sparse member on 30 minutes used to come out on 45,
     * which for a band that earns the ceiling means the "no limit" sentinel - so the pass
     * itself was switching off the distance filtering it exists to apply.
     */
    public function testASecondFullPassLeavesTheBudgetAlone(): void
    {
        $this->fakeLookups(400, 16.1, 17.5);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => 17.5]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();
        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(30, $this->minutesOf($id));
        $this->assertSame(17.5, $this->distanceOf($id));
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

    /*
     * The SEPARATE outbound axis (myPostsMaxMinutes / myPostsMaxDistance): how far away people
     * can be and still see this member's posts, once they have set that apart from what they see.
     * Its stored radius drifts exactly as the inbound one does - it was derived from where they
     * lived when they chose it - so it needs the same reconciliation. Its range is the ripple
     * ceiling for everyone, not the member's band, so there is no rescale and no band here.
     */

    private function outboundDistanceOf(int $id): mixed
    {
        return $this->settingsOf($id)['myPostsMaxDistance'] ?? null;
    }

    private function outboundMinutesOf(int $id): mixed
    {
        return $this->settingsOf($id)['myPostsMaxMinutes'] ?? null;
    }

    public function testReconcilesAStaleOutboundRadius(): void
    {
        $this->fakeMedium(8.5);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 8.5,
            'myPostsMaxMinutes' => 25,
            'myPostsMaxDistance' => 1,
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->outboundDistanceOf($id), 'the stale 1 mile must be rederived');
        $this->assertSame(25, $this->outboundMinutesOf($id), 'the chosen travel time is untouched');
    }

    public function testNeverCreatesAnOutboundPair(): void
    {
        // Absence is what "linked" means - every outbound reader falls back to browseMaxDistance.
        // Writing a value here would split a member's two axes apart without them asking, which is
        // the one thing the split was designed not to do.
        $this->fakeMedium(8.5);
        $id = $this->userWith(['browseMaxMinutes' => 20, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertNull($this->outboundMinutesOf($id));
        $this->assertNull($this->outboundDistanceOf($id));
    }

    public function testLeavesAConsistentOutboundPairAlone(): void
    {
        $this->fakeMedium(8.5);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 8.5,
            'myPostsMaxMinutes' => 25,
            'myPostsMaxDistance' => 8.5,
        ]);

        $this->artisan('browse:backfill-max-distance')
            ->expectsOutputToContain('already consistent')
            ->assertSuccessful();

        $this->assertSame(8.5, $this->outboundDistanceOf($id));
    }

    public function testOutboundIsNotCappedByTheMembersBand(): void
    {
        // A dense/city member caps at 20 minutes INBOUND, but their posts still ripple to the
        // ceiling, so an outbound 45 is a legitimate choice and must survive. Clamping it to the
        // band would quietly cut their reach by more than half.
        $this->fakeLookups(400, 0.9, 7.4);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 7.4,
            'myPostsMaxMinutes' => 45,
            'myPostsMaxDistance' => 1,
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(45, $this->outboundMinutesOf($id), 'the band must not clamp the outbound axis');
    }

    public function testOutboundAtTheCeilingStoresTheNoLimitSentinel(): void
    {
        // The top stop on this axis always means "no limit": its range IS the ceiling, so unlike
        // the inbound axis there is no band below which a real radius has to be stored instead.
        $this->fakeLookups(400, 0.9, 7.4);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 7.4,
            'myPostsMaxMinutes' => 45,
            'myPostsMaxDistance' => 3,
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(9007199254740991, $this->outboundDistanceOf($id));
    }

    public function testAnOutboundOnlyCorrectionIsStillSaved(): void
    {
        // The inbound half is already consistent, so that path returns without writing. The
        // outbound correction has to be flushed anyway or it would be silently dropped - the
        // settings array is read once, before either half is touched.
        $this->fakeMedium(8.5);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 8.5,
            'myPostsMaxMinutes' => 25,
            'myPostsMaxDistance' => 2,
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->outboundDistanceOf($id), 'the outbound fix must survive');
        $this->assertSame(8.5, $this->chosenDistanceOf($id), 'the inbound half is unchanged');
    }

    public function testDryRunWritesNoOutboundCorrection(): void
    {
        $this->fakeMedium(8.5);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 8.5,
            'myPostsMaxMinutes' => 25,
            'myPostsMaxDistance' => 2,
        ]);

        $this->artisan('browse:backfill-max-distance', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, $this->outboundDistanceOf($id));
    }

    public function testARelinkedRowIsLeftAlone(): void
    {
        // "Link them again" stores JSON null in both outbound keys (PATCH /session replaces the
        // settings blob wholesale). That reads as unset everywhere, so this command must not
        // treat it as a pair to reconcile - which would resurrect a choice the member gave up.
        $this->fakeMedium(8.5);
        $id = $this->userWith([
            'browseMaxMinutes' => 20,
            'browseMaxDistance' => 8.5,
            'myPostsMaxMinutes' => null,
            'myPostsMaxDistance' => null,
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertNull($this->outboundMinutesOf($id));
        $this->assertNull($this->outboundDistanceOf($id));
    }

    /**
     * A member whose radius lookup fails is SKIPPED, and a skipped member keeps no band
     * limit at all - so a broken lookup does not shrink this command's effect, it voids it.
     * Measured on production 2026-08-15: BROWSE_TOWN_NEAR_URL was unset on the batch host, so
     * every call went to the compose-internal default which does not resolve there. 1,018 of
     * 2,260 scanned members failed, ZERO of 202,837 active members held a band radius, and the
     * command reported success every night regardless.
     */
    public function testFailsLoudlyWhenMostRadiusLookupsFail(): void
    {
        // Density answers (so members are banded medium and DO need a radius), but the
        // routing-backed radius endpoint is unreachable - the production failure exactly.
        $results = [];
        for ($i = 0; $i < 400; $i++) {
            $results[] = ['id' => $i + 1, 'extra' => ['lat' => 53.4 + (2.5 * ($i + 1) / 400) / 69.05, 'lng' => -1.3]];
        }
        Http::fake([
            '*userapproxlocs*' => Http::response(['results' => $results]),
            '*town/near*' => Http::response(null, 500),
        ]);

        // Enough to be systemic rather than a handful: the alarm needs both a high failure
        // RATE and an absolute floor, so that a --limit run or a single unlucky member
        // cannot fail the nightly job.
        foreach (range(1, 25) as $n) {
            $this->userWith(['browseMaxMinutes' => 25]);
        }

        $this->artisan('browse:backfill-max-distance')->assertFailed();
    }

    /**
     * The alarm must not fire on the honest case: a lookup can fail for one member without
     * meaning the endpoint is broken, and failing the whole nightly run over that would be
     * its own kind of noise.
     */
    public function testStaysSuccessfulWhenOnlyAFewLookupsFail(): void
    {
        $this->fakeMedium(8.5);
        foreach (range(1, 8) as $n) {
            $this->userWith(['browseMaxMinutes' => 25]);
        }

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();
    }

    /**
     * A single member whose lookup fails is left alone and the run still succeeds - existing,
     * intended behaviour. The alarm must not turn that into a nightly job failure, which is
     * why it needs an absolute floor as well as a rate.
     */
    public function testASingleFailedLookupDoesNotFailTheRun(): void
    {
        $results = [];
        for ($i = 0; $i < 400; $i++) {
            $results[] = ['id' => $i + 1, 'extra' => ['lat' => 53.4 + (2.5 * ($i + 1) / 400) / 69.05, 'lng' => -1.3]];
        }
        Http::fake([
            '*userapproxlocs*' => Http::response(['results' => $results]),
            '*town/near*' => Http::response(null, 500),
        ]);

        $this->userWith(['browseMaxMinutes' => 25]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();
    }

    /**
     * --missing-only exists because the invariant DECAYS. The full pass walks every user
     * (~2.9M rows on live) and is deliberately not scheduled, but browseReachMaxDistance has
     * no other writer, so a member who joins after a run gets no band limit ever - and since
     * posts ripple out to the widest budget and rely on each member being held to their own
     * band, that member sits permanently on "no limit" inbound. Narrowed this way the pass is
     * small enough to run regularly.
     */
    public function testMissingOnlyTouchesOnlyMembersWithNoBandLimit(): void
    {
        $this->fakeMedium(8.5);

        $needsOne = $this->userWith([]);                                  // nothing at all
        $hasDefault = $this->userWith(['browseReachMaxDistance' => 8.5]); // already covered
        $hasOwnChoice = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 8.5]);

        $this->artisan('browse:backfill-max-distance', ['--missing-only' => true])
            ->assertSuccessful();

        $this->assertArrayHasKey(
            'browseReachMaxDistance',
            $this->settingsOf($needsOne),
            'a member with no band limit must be given one'
        );

        // The other two must be left alone: this pass closes the gap, it does not
        // re-reconcile members who already have something.
        $this->assertSame(8.5, $this->settingsOf($hasDefault)['browseReachMaxDistance']);
        $this->assertSame(8.5, $this->settingsOf($hasOwnChoice)['browseMaxDistance']);
        $this->assertArrayNotHasKey('browseReachMaxDistance', $this->settingsOf($hasOwnChoice));
    }

    /**
     * Without the flag the full pass still reconciles members who already have settings, which
     * every other test in this file relies on. If --missing-only leaked into the default the
     * migration pass would quietly stop doing its job.
     */
    public function testTheFullPassStillReconcilesExistingSettings(): void
    {
        $this->fakeMedium(8.5);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->chosenDistanceOf($id), 'the full pass must still reconcile');
    }

    /**
     * The measured band is recorded, not just the budget derived from it. Nothing downstream
     * can recover it afterwards: a member on 20 minutes is either a city member on their cap
     * or a rural member who asked for less, and the two are the same number. The rural
     * overflow lane admits a member against the ring for THEIR band, so it needs the band.
     */
    public function testRecordsTheMeasuredDensityBand(): void
    {
        $this->fakeMedium(8.5);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame('medium', $this->settingsOf($id)['browseDensityBand'] ?? null);
    }

    /**
     * The case that decides whether recording the band is worth anything: a member whose
     * budget is ALREADY right. They are the majority - the whole point of a reconciling pass
     * is that most rows need no reconciling - and a correcting pass never writes to them. If
     * the band were only stamped alongside a correction, it would be missing for most of the
     * membership and the lane reading it would be near-inert while every run reported success.
     */
    public function testStampsTheBandOnAMemberWhoseBudgetIsAlreadyRight(): void
    {
        $this->fakeMedium();
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 8.2]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame('medium', $this->settingsOf($id)['browseDensityBand'] ?? null);
        // ...and the budget it left alone is still left alone.
        $this->assertSame(8.2, $this->distanceOf($id));
    }

    /** A member already carrying the right band is not rewritten for the sake of it. */
    public function testAnUpToDateBandIsNotRestamped(): void
    {
        $this->fakeMedium();
        $this->userWith([
            'browseMaxMinutes' => 25,
            'browseMaxDistance' => 8.2,
            'browseDensityBand' => 'medium',
        ]);

        $this->artisan('browse:backfill-max-distance')
            ->expectsOutputToContain('0 band stamped, 1 already consistent')
            ->assertSuccessful();
    }

    public function testDryRunDoesNotStampTheBand(): void
    {
        $this->fakeMedium();
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 8.2]);

        $this->artisan('browse:backfill-max-distance --dry-run')->assertSuccessful();

        $this->assertArrayNotHasKey('browseDensityBand', $this->settingsOf($id));
    }

    /**
     * A band that has changed - a member who moved, or a town that grew - is corrected. The
     * stamp is a measurement, not a one-off initialisation.
     */
    public function testAStaleBandIsCorrected(): void
    {
        $this->fakeMedium();
        $id = $this->userWith([
            'browseMaxMinutes' => 25,
            'browseMaxDistance' => 8.2,
            'browseDensityBand' => 'sparse',
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame('medium', $this->settingsOf($id)['browseDensityBand'] ?? null);
    }
}
