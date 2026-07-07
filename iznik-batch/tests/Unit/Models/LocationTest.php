<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsSpatialIndex;
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
    use SeedsSpatialIndex;

    private const TEST_PC_ID = 99000001;

    // Seeded postcodes are placed in empty open sea (central North Sea), well beyond
    // the KNN's largest 0.32°/~35km buffer from any real postcode. In CI the spatial
    // server builds its 'postcodes' index from a populated DB, so seeding at a real UK
    // location would let a real postcode out-compete the sentinel — and since
    // closestPostcode enriches by id from the *test* DB, a real id it returns isn't
    // present there and the lookup yields null. At sea the sentinel is unambiguously
    // nearest, so the seeded id is returned and enriches correctly.

    /**
     * Seed a known full postcode into both the test DB (for the by-id enrich)
     * and the spatial server's live "postcodes" index (for the KNN lookup).
     */
    private function seedPostcode(int $id, string $name, float $lat, float $lng): void
    {
        $srid = (int) config('freegle.srid', 3857);
        DB::table('locations')->updateOrInsert(['id' => $id], [
            'name'     => $name,
            'type'     => 'Postcode',
            'lat'      => $lat,
            'lng'      => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, $srid)),
        ]);
        $this->seedSpatialPoint('postcodes', $id, $lat, $lng);
    }

    public function test_closest_postcode_returns_result_for_known_coords(): void
    {
        $this->seedPostcode(self::TEST_PC_ID, 'EH1 1AA', 56.700, 3.000);

        try {
            $result = Location::closestPostcode(56.700, 3.000);

            $this->assertNotNull($result);
            $this->assertEquals(self::TEST_PC_ID, (int) $result->id);
            $this->assertEquals('EH1 1AA', $result->name);
            $this->assertNotEmpty($result->name);
        } finally {
            $this->removeSpatial('postcodes', [self::TEST_PC_ID]);
            DB::table('locations')->where('id', self::TEST_PC_ID)->delete();
        }
    }

    public function test_closest_postcode_returns_full_postcode(): void
    {
        $this->seedPostcode(self::TEST_PC_ID, 'SW1A 1AA', 56.800, 3.000);

        try {
            $result = Location::closestPostcode(56.800, 3.000);

            $this->assertNotNull($result);
            // Full postcodes have a space in them (e.g. "SW1A 1AA").
            $this->assertStringContainsString(' ', $result->name);
        } finally {
            $this->removeSpatial('postcodes', [self::TEST_PC_ID]);
            DB::table('locations')->where('id', self::TEST_PC_ID)->delete();
        }
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

    public function test_closest_postcode_returns_coordinates(): void
    {
        $this->seedPostcode(self::TEST_PC_ID, 'NG1 1AA', 56.900, 3.000);

        try {
            $result = Location::closestPostcode(56.900, 3.000);

            $this->assertNotNull($result);
            $this->assertEquals(self::TEST_PC_ID, (int) $result->id);
            $this->assertNotNull($result->lat);
            $this->assertNotNull($result->lng);
        } finally {
            $this->removeSpatial('postcodes', [self::TEST_PC_ID]);
            DB::table('locations')->where('id', self::TEST_PC_ID)->delete();
        }
    }
}
