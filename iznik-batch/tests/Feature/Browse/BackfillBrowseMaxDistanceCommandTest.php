<?php

namespace Tests\Feature\Browse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The backfill reconciles settings.browseMaxDistance (the derived cap the feed
 * and digest filters read) with settings.browseMaxMinutes (the slider's source
 * of truth). It must recompute stale caps from the routing endpoint, honour the
 * unlimited sentinel at the no-limit stop, and never touch a pair it cannot
 * recompute honestly.
 */
class BackfillBrowseMaxDistanceCommandTest extends TestCase
{
    private const UNLIMITED = 9007199254740991;

    private function userWith(array $settings, ?float $lat = 53.4, ?float $lng = -1.3): int
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

        return (int) DB::table('users')->insertGetId([
            'fullname' => 'Backfill Test '.uniqid(),
            'systemrole' => 'User',
            'lastlocation' => $locationId,
            'settings' => json_encode($settings),
        ]);
    }

    private function distanceOf(int $id): mixed
    {
        $settings = json_decode(DB::table('users')->where('id', $id)->value('settings'), true);

        return $settings['browseMaxDistance'] ?? null;
    }

    public function testRecomputesAStaleCapFromTheRoutingEndpoint(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        // The live case: slider at 25 minutes, cap stuck at a stale 1 mile.
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.5, $this->distanceOf($id));
    }

    public function testLeavesAConsistentPairAlone(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 8.2]);

        // 0.3 miles inside the default 0.5 epsilon - not worth churning.
        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(8.2, $this->distanceOf($id));
    }

    public function testFillsAMissingCapForACapWantingSlider(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 3.7])]);
        $id = $this->userWith(['browseMaxMinutes' => 10]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(3.7, $this->distanceOf($id));
    }

    public function testNoLimitStopGetsTheSharedSentinelWithoutARoutingCall(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 99])]);
        $id = $this->userWith(['browseMaxMinutes' => 30, 'browseMaxDistance' => 12]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(self::UNLIMITED, $this->distanceOf($id));
        Http::assertNothingSent();
    }

    public function testSkipsWhenTheRoutingLookupFails(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        // Never clobber on a lookup blip - the stale value stays until a run
        // that can recompute it honestly.
        $this->assertSame(1, $this->distanceOf($id));
    }

    public function testSkipsUsersWithNoKnownLocation(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1], null, null);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(1, $this->distanceOf($id));
        Http::assertNothingSent();
    }

    public function testOverridesALegacyMilesOnlyCapToUnlimited(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        // Pre-2026-07-10 miles-slider write: distance set, no minutes. The
        // time-based slider shows this member "no limit" - storage must match.
        $id = $this->userWith(['browseMaxDistance' => 5]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(self::UNLIMITED, $this->distanceOf($id));
        Http::assertNothingSent();
    }

    public function testLeavesALegacyAlreadyUnlimitedRowAlone(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        $id = $this->userWith(['browseMaxDistance' => self::UNLIMITED]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $this->assertSame(self::UNLIMITED, $this->distanceOf($id));
        Http::assertNothingSent();
    }

    public function testDryRunWritesNothing(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        $id = $this->userWith(['browseMaxMinutes' => 25, 'browseMaxDistance' => 1]);

        $this->artisan('browse:backfill-max-distance --dry-run')->assertSuccessful();

        $this->assertSame(1, $this->distanceOf($id));
    }

    public function testPreservesUnrelatedSettingsKeys(): void
    {
        Http::fake(['*' => Http::response(['reach_radius_miles' => 8.5])]);
        $id = $this->userWith([
            'browseMaxMinutes' => 25,
            'browseMaxDistance' => 1,
            'browseView' => 'mygroups',
            'browseSort' => 'Newest',
        ]);

        $this->artisan('browse:backfill-max-distance')->assertSuccessful();

        $settings = json_decode(DB::table('users')->where('id', $id)->value('settings'), true);
        $this->assertSame(8.5, $settings['browseMaxDistance']);
        $this->assertSame('mygroups', $settings['browseView']);
        $this->assertSame('Newest', $settings['browseSort']);
    }
}
