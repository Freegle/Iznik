<?php

namespace Tests\Unit\Services;

use App\Services\PostcodeRemapService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostcodeRemapServiceTest extends TestCase
{
    private PostcodeRemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PostcodeRemapService::class);
    }

    /**
     * Test postgresAvailable returns true when PostgreSQL is reachable.
     */
    public function test_postgres_available_returns_true_when_connected(): void
    {
        // PostgreSQL should be available in the test environment.
        $this->assertTrue($this->service->postgresAvailable());
    }

    /**
     * Test ensurePostgresSchema creates required extensions.
     */
    public function test_ensure_postgres_schema_creates_postgis_extension(): void
    {
        // Call should succeed without exception.
        $this->service->ensurePostgresSchema();

        // Verify postgis is installed by checking a function exists.
        $result = DB::connection('pgsql')->selectOne(
            "SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'st_makepoint')"
        );

        $this->assertTrue((bool) $result->exists);
    }

    /**
     * Test remapPostcodes returns 0 when PostgreSQL is unavailable.
     */
    public function test_remap_postcodes_returns_zero_when_postgres_unavailable(): void
    {
        // Mock postgresAvailable to return false.
        $mock = $this->createPartialMock(PostcodeRemapService::class, ['postgresAvailable']);
        $mock->method('postgresAvailable')->willReturn(false);

        $result = $mock->remapPostcodes();

        $this->assertEquals(0, $result);
    }

    /**
     * Test findNearestArea returns null when no area found.
     */
    public function test_find_nearest_area_returns_null_when_no_match(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Call with a point in the middle of the ocean (no areas nearby).
        $result = $this->service->findNearestArea(0.0, 0.0);

        $this->assertNull($result);
    }

    /**
     * Test remapPostcodes processes no postcodes when table is empty.
     */
    public function test_remap_postcodes_processes_zero_when_no_postcodes_exist(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Call with no postcodes in database.
        $result = $this->service->remapPostcodes();

        // Should return 0 since no postcodes were found.
        $this->assertEquals(0, $result);
    }

    /**
     * Test remapPostcodes with location scope returns 0 when no postcodes in scope.
     */
    public function test_remap_postcodes_with_location_scope_returns_zero_when_no_postcodes_match(): void
    {
        // Ensure schema exists.
        $this->service->ensurePostgresSchema();

        // Create a test polygon WKT that definitely has no postcodes.
        $polygon = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';

        // Call with a polygon scope.
        $result = $this->service->remapPostcodes(null, $polygon);

        // Should return 0 since no postcodes match the scope.
        $this->assertEquals(0, $result);
    }
}
