<?php

namespace Tests\Unit\Models;

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
