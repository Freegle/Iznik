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
}
