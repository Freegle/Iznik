<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for Location::closestPostcode() — ported from iznik-server-go
 * TestClosest (location_test.go) and iznik-server LocationTest.
 *
 * These tests depend on the locations and locations_spatial tables having
 * postcode data. The test database is populated by migrations + testenv.php.
 */
class LocationTest extends TestCase
{
    private const NO_POSTCODE_DATA = 'No postcode data in test database';
    /**
     * Check if test location data exists before running spatial tests.
     */
    private function hasLocationData(): bool
    {
        return DB::table('locations')
            ->where('type', 'Postcode')
            ->whereRaw("LOCATE(' ', name) > 0")
            ->exists();
    }

    public function test_closest_postcode_returns_result_for_known_coords(): void
    {
        if (!$this->hasLocationData()) {
            $this->markTestSkipped(self::NO_POSTCODE_DATA);
        }

        // Central Edinburgh — should find a postcode.
        $result = Location::closestPostcode(55.9533, -3.1883);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('lat', $result);
        $this->assertArrayHasKey('lng', $result);
        $this->assertNotEmpty($result['name']);
    }

    public function test_closest_postcode_returns_full_postcode(): void
    {
        if (!$this->hasLocationData()) {
            $this->markTestSkipped(self::NO_POSTCODE_DATA);
        }

        // Central London — well-populated area.
        $result = Location::closestPostcode(51.5074, -0.1278);

        $this->assertNotNull($result);
        // Full postcodes have a space in them (e.g. "SW1A 1AA").
        $this->assertStringContainsString(' ', $result['name']);
    }

    public function test_closest_postcode_returns_null_for_ocean(): void
    {
        // Middle of the Atlantic — no postcodes within 0.2 degrees.
        $result = Location::closestPostcode(30.0, -40.0);

        $this->assertNull($result);
    }

    /**
     * groupsNear must prefer polygon containment over centroid distance.
     *
     * Regression for Discourse #9763 — a Southwark repair-workshop event appeared on
     * Tower Hamlets Freegle because the implementation used centroid distance only.
     * When a group's polyindex polygon contains the event location, that group must
     * win even when another group has a closer centroid.
     */
    public function test_groups_near_returns_containing_group_over_closer_centroid(): void
    {
        $srid = config('freegle.srid', 3857);

        // Event location: Bermondsey, SE1 (Southwark area)
        $eventLat = 51.490;
        $eventLng = -0.090;

        // Group A (Southwark): centroid ~8 km from event, but polyindex POLYGON contains event.
        $southwark = $this->createTestGroup([
            'lat'      => 51.450,
            'lng'      => -0.200,
            'publish'  => 1,
            'listable' => 1,
        ]);
        // Override the auto-created POINT polyindex with a polygon that covers the event location.
        // WKT uses the same "lat/lng degree coordinates labeled SRID 3857" convention used
        // throughout the codebase for polyindex (ST_GeomFromText(poly, 3857) where poly is WGS84).
        DB::statement(
            "UPDATE `groups` SET polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
            ['POLYGON((-0.15 51.46, -0.15 51.52, -0.05 51.52, -0.05 51.46, -0.15 51.46))', $srid, $southwark->id]
        );

        // Group B (Tower Hamlets): centroid ~140 m from event (very close), but polyindex is
        // a POINT — ST_Contains(POINT, POINT) returns false for non-identical points.
        $towerHamlets = $this->createTestGroup([
            'lat'      => 51.491,
            'lng'      => -0.089,
            'publish'  => 1,
            'listable' => 1,
        ]);

        $groups = Location::groupsNear($eventLat, $eventLng, 50);

        // Southwark's polygon contains the event: it must be returned.
        $this->assertContains($southwark->id, $groups, 'Group whose polygon contains the event must be returned');
        // Tower Hamlets has no containing polygon: it must not shadow the correct group.
        $this->assertNotContains($towerHamlets->id, $groups, 'Group with no containing polygon must not appear when containment match exists');
    }

    public function test_closest_postcode_includes_area_data(): void
    {
        if (!$this->hasLocationData()) {
            $this->markTestSkipped(self::NO_POSTCODE_DATA);
        }

        // Nottingham — should have area data.
        $result = Location::closestPostcode(52.9548, -1.1581);

        if ($result === null) {
            $this->markTestSkipped('No postcode found near Nottingham in test data');
        }

        // If the postcode has an areaid, we should get area info.
        // Not all postcodes have area data, so only assert structure if present.
        if (isset($result['area'])) {
            $this->assertIsArray($result['area']);
            $this->assertArrayHasKey('id', $result['area']);
            $this->assertArrayHasKey('name', $result['area']);
        }
    }
}
