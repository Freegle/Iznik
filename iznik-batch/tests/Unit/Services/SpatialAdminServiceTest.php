<?php

namespace Tests\Unit\Services;

use App\Services\SpatialAdminService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpatialAdminServiceTest extends TestCase
{
    public function test_rebuild_dataset_posts_to_rebuild_endpoint(): void
    {
        Http::fake(['*/v1/jobs/rebuild' => Http::response(['status' => 'rebuilding'], 200)]);

        (new SpatialAdminService())->rebuildDataset('jobs');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/jobs/rebuild')
            && $request->method() === 'POST');
    }

    public function test_rebuild_dataset_swallows_http_error(): void
    {
        Http::fake(['*/v1/jobs/rebuild' => Http::response('boom', 500)]);

        // A failed rebuild must not throw — the delta and nightly rebuild are
        // backstops, and the sync must still complete.
        (new SpatialAdminService())->rebuildDataset('jobs');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/jobs/rebuild'));
    }

    public function test_rebuild_dataset_swallows_connection_exception(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('no route to host');
        });

        // Must not propagate the exception.
        (new SpatialAdminService())->rebuildDataset('jobs');

        $this->assertTrue(true);
    }

    public function test_upsert_items_posts_items_to_upsert_endpoint(): void
    {
        Http::fake(['*/v1/locations/upsert' => Http::response(['upserted' => 1], 200)]);

        (new SpatialAdminService())->upsertItems('locations', [[
            'id'    => 4242,
            'wkt'   => 'POLYGON((0 0, 0.01 0, 0.01 0.01, 0 0.01, 0 0))',
            'extra' => ['name' => 'Testville', 'type' => 'Polygon'],
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/locations/upsert')
            && $request->method() === 'POST'
            && $request['items'][0]['id'] === 4242
            && $request['items'][0]['extra']['type'] === 'Polygon');
    }

    public function test_upsert_items_is_noop_on_empty_array(): void
    {
        Http::fake();

        (new SpatialAdminService())->upsertItems('locations', []);

        Http::assertNothingSent();
    }

    public function test_upsert_items_swallows_http_error(): void
    {
        Http::fake(['*/v1/locations/upsert' => Http::response('boom', 500)]);

        // A failed seed must not throw — the periodic delta and nightly rebuild
        // remain as backstops.
        (new SpatialAdminService())->upsertItems('locations', [[
            'id' => 1, 'wkt' => 'POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', 'extra' => [],
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/locations/upsert'));
    }
}
