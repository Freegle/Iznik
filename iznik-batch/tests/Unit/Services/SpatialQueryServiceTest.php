<?php

namespace Tests\Unit\Services;

use App\Services\SpatialQueryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Read-side client for the iznik-spatial-go "finder" service. Every caller
 * (NewsfeedDigestService, IncomingMailService, Location, Job) treats a
 * spatial-server error/timeout as "nothing nearby" rather than propagating a
 * failure, so the try/catch fallback-to-[] behaviour matters as much as the
 * happy path.
 */
class SpatialQueryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.spatial_server_url' => 'https://spatial.test']);
    }

    public function test_returns_ordered_ids_from_successful_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'results' => [
                    ['id' => 30],
                    ['id' => 10],
                    ['id' => 20],
                ],
            ], 200),
        ]);

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        // Order from the response is preserved, not re-sorted.
        $this->assertSame([30, 10, 20], $ids);
    }

    public function test_ids_are_cast_to_int(): void
    {
        Http::fake([
            '*' => Http::response(['results' => [['id' => '42']]], 200),
        ]);

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([42], $ids);
        $this->assertIsInt($ids[0]);
    }

    public function test_missing_results_key_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response(['other' => 'field'], 200),
        ]);

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_null_results_value_returns_empty_array(): void
    {
        // json('results', []) returns the actual `null` value stored under the
        // key rather than the default when the key IS present but null, so the
        // `?? []` fallback in the service is what saves this from a TypeError.
        Http::fake([
            '*' => Http::response(['results' => null], 200),
        ]);

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_empty_results_array_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response(['results' => []], 200),
        ]);

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_unsuccessful_response_logs_warning_and_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'boom'], 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                $this->stringContains('locations knn HTTP 500'),
                ['lat' => 51.5, 'lng' => -0.1]
            );

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_404_response_logs_warning_and_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response('Not Found', 404),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with($this->stringContains('HTTP 404'), \Mockery::any());

        $ids = (new SpatialQueryService())->nearestIds('jobs', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_connection_exception_is_caught_and_logs_warning(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });

        Log::shouldReceive('warning')
            ->once()
            ->with(
                $this->stringContains('locations knn failed: Connection timed out'),
                ['lat' => 51.5, 'lng' => -0.1]
            );

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_generic_throwable_is_caught_and_logs_warning(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('unexpected failure');
        });

        Log::shouldReceive('warning')
            ->once()
            ->with($this->stringContains('unexpected failure'), \Mockery::any());

        $ids = (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        $this->assertSame([], $ids);
    }

    public function test_request_url_and_default_params(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('locations', 51.5074, -0.1278);

        Http::assertSent(function ($request) {
            $params = $this->queryParams($request);

            return str_starts_with($request->url(), 'https://spatial.test/v1/locations/knn')
                && $request->method() === 'GET'
                && $params['lat'] === '51.5074'
                && $params['lng'] === '-0.1278'
                && $params['limit'] === '1'
                && !array_key_exists('type', $params);
        });
    }

    private function queryParams($request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_request_includes_custom_limit(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1, 5);

        Http::assertSent(function ($request) {
            return ($this->queryParams($request)['limit'] ?? null) === '5';
        });
    }

    public function test_type_param_included_when_provided(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1, 1, 'freegle');

        Http::assertSent(function ($request) {
            return ($this->queryParams($request)['type'] ?? null) === 'freegle';
        });
    }

    public function test_type_param_omitted_when_null(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        Http::assertSent(function ($request) {
            return !array_key_exists('type', $this->queryParams($request));
        });
    }

    public function test_dataset_segment_is_used_in_the_url_path(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('jobs', 51.5, -0.1);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/jobs/knn');
        });
    }

    public function test_configured_url_with_trailing_slash_is_trimmed(): void
    {
        config(['freegle.spatial_server_url' => 'https://spatial.test/']);

        Http::fake(['*' => Http::response(['results' => []], 200)]);

        (new SpatialQueryService())->nearestIds('locations', 51.5, -0.1);

        Http::assertSent(function ($request) {
            return !str_contains($request->url(), 'test//v1');
        });
    }
}
