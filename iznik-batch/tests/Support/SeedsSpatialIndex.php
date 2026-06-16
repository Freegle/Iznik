<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Helpers for integration tests that exercise the spatial server (closestPostcode,
 * Job::nearLocation, NewsfeedDigest, …).
 *
 * The spatial server keeps its own R-tree index (built from MySQL), so a row
 * inserted into the test DB is NOT automatically in the index. These helpers
 * push a known geometry straight into the live index via the admin upsert
 * endpoint, so a test can: insert the row in the test DB (for the by-id enrich),
 * seed the index here, call the code under test, assert, then removeSpatial() in
 * teardown. Decoupled from which DB the server normally indexes.
 */
trait SeedsSpatialIndex
{
    private function spatialAdminUrl(): string
    {
        return rtrim(config('freegle.spatial_admin_url', 'http://localhost:8195'), '/');
    }

    /**
     * Upsert one geometry (WKT) into a dataset's live index.
     */
    protected function seedSpatial(string $dataset, int $id, string $wkt): void
    {
        Http::timeout(5)
            ->post("{$this->spatialAdminUrl()}/v1/{$dataset}/upsert", [
                'items' => [['id' => $id, 'wkt' => $wkt]],
            ])
            ->throw();
    }

    /**
     * Upsert a point into a point dataset (postcodes, newsfeed, …).
     */
    protected function seedSpatialPoint(string $dataset, int $id, float $lat, float $lng): void
    {
        $this->seedSpatial($dataset, $id, sprintf('POINT(%F %F)', $lng, $lat));
    }

    /**
     * Remove seeded ids from a dataset's live index (teardown).
     */
    protected function removeSpatial(string $dataset, array $ids): void
    {
        Http::timeout(5)->post(
            "{$this->spatialAdminUrl()}/v1/{$dataset}/remove",
            ['ids' => array_values($ids)]
        );
    }
}
