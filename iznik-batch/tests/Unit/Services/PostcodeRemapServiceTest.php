<?php

namespace Tests\Unit\Services;

use App\Services\PostcodeRemapService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostcodeRemapServiceTest extends TestCase
{
    private PostcodeRemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PostcodeRemapService::class);
    }

    public function test_find_nearest_area_returns_location_id_on_success(): void
    {
        Http::fake([
            '*/v1/locations/knn*' => Http::response(['results' => [['id' => 42, 'distance' => 0.00015625]]], 200),
        ]);

        $result = $this->service->findNearestArea(-1.5491, 53.8008);

        $this->assertSame(42, $result);
    }

    public function test_find_nearest_area_returns_null_when_no_match(): void
    {
        Http::fake([
            '*/v1/locations/knn*' => Http::response(['results' => []], 200),
        ]);

        $result = $this->service->findNearestArea(0.0, 0.0);

        $this->assertNull($result);
    }

    public function test_find_nearest_area_returns_null_when_server_unavailable(): void
    {
        Http::fake([
            '*/v1/locations/knn*' => Http::response([], 503),
        ]);

        $result = $this->service->findNearestArea(-1.5491, 53.8008);

        $this->assertNull($result);
    }

    public function test_find_nearest_area_queries_locations_knn_with_area_filter(): void
    {
        Http::fake([
            '*/v1/locations/knn*' => Http::response(['results' => [['id' => 99]]], 200),
        ]);

        $this->service->findNearestArea(-2.2426, 53.4808);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/locations/knn')
                && $request->data()['lng'] == -2.2426
                && $request->data()['lat'] == 53.4808
                && $request->data()['type'] === 'Area'
                && $request->data()['limit'] == 1;
        });
    }

    public function test_remap_postcodes_processes_zero_when_no_postcodes_exist(): void
    {
        Http::fake(['*/v1/locations/knn*' => Http::response(['results' => [['id' => 1]]], 200)]);

        $result = $this->service->remapPostcodes();

        $this->assertEquals(0, $result);
    }

    public function test_remap_postcodes_with_out_of_range_polygon_returns_zero(): void
    {
        Http::fake(['*/v1/locations/knn*' => Http::response(['results' => [['id' => 1]]], 200)]);

        $polygon = 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))';
        $result  = $this->service->remapPostcodes(null, $polygon);

        $this->assertEquals(0, $result);
    }
}
