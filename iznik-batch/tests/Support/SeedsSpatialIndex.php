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
    /**
     * How many neighbours to ask for when confirming a seed landed. Large enough that a test
     * seeding a batch of rows at one location still finds its own id among them, small enough
     * that the check stays cheap.
     */
    private const SPATIAL_PRESENCE_LIMIT = 50;

    private function spatialAdminUrl(): string
    {
        return rtrim(config('freegle.spatial_admin_url', 'http://localhost:8195'), '/');
    }

    /** The port the APPLICATION reads from - seeding on the admin port is only half the story. */
    private function spatialQueryUrl(): string
    {
        return rtrim(config('freegle.spatial_server_url', 'http://localhost:8194'), '/');
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
     * Upsert a point into a point dataset (postcodes, newsfeed, …), and do not return until
     * the code under test can actually SEE it.
     *
     * The upsert lands on the admin port and is visible immediately on the query port, so the
     * usual case costs one extra request. What this guards against is the server rebuilding
     * its index from MySQL - which it does on its own schedule, and which
     * scripts/setup-test-database.sh provokes - landing between the seed and the assertion. A
     * rebuild drops the injected id, the query then returns the nearest REAL row instead, and
     * the test fails with an id mismatch that looks like a product bug and is not one. Those
     * failures moved around between runs of identical code, which is what sent someone
     * looking at the wrong thing.
     *
     * Re-seeding on a miss is the whole fix: if a rebuild took the point, put it back. If the
     * index cannot hold it at all, fail HERE, in setup, naming the reason, rather than three
     * lines later as a confusing assertion failure.
     */
    protected function seedSpatialPoint(string $dataset, int $id, float $lat, float $lng): void
    {
        $wkt = sprintf('POINT(%F %F)', $lng, $lat);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->seedSpatial($dataset, $id, $wkt);

            if ($this->spatialIndexHas($dataset, $id, $lat, $lng)) {
                return;
            }

            usleep(200_000);
        }

        $this->fail(
            "Seeded id {$id} into the {$dataset} spatial index but the query port never "
            . 'returned it. The server is probably rebuilding its index from MySQL; this is '
            . 'a test-environment problem, not a failure of the code under test.'
        );
    }

    /**
     * Is this id PRESENT in the index near the seeded point?
     *
     * Present, deliberately, not nearest. Tests routinely seed several rows at one set of
     * coordinates (the newsfeed digest seeds every post at the same London point), and only
     * one of those can be the nearest - so asking for the nearest would report every
     * subsequent seed as missing and fail a test whose seeding was perfectly fine.
     *
     * Deliberately the same endpoint SpatialQueryService uses, on the same URL, so this asks
     * the question the code under test will ask. Any transport failure counts as "not
     * visible" and lets the caller retry.
     */
    private function spatialIndexHas(string $dataset, int $id, float $lat, float $lng): bool
    {
        try {
            $response = Http::timeout(5)->get(
                "{$this->spatialQueryUrl()}/v1/{$dataset}/knn",
                ['lat' => $lat, 'lng' => $lng, 'limit' => self::SPATIAL_PRESENCE_LIMIT]
            );

            if (! $response->successful()) {
                return false;
            }

            foreach ($response->json('results', []) ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Remove seeded ids from a dataset's live index (teardown).
     *
     * Best-effort: a failed/slow remove must never error the test. This runs in
     * teardown, so a propagated exception (e.g. cURL 28 when the spatial server
     * is briefly slow under CI load) would skip the rest of teardown — including
     * parent::tearDown()'s connection cleanup — leaving the DB connection holding
     * locks and flaking *subsequent* tests with "1205 Lock wait timeout" on the
     * next clearJobsTable() DELETE. Swallow failures and let the next seed/remove
     * or the nightly rebuild reconcile the index, exactly as the production
     * SpatialAdminService::removeItems does.
     */
    protected function removeSpatial(string $dataset, array $ids): void
    {
        try {
            Http::timeout(10)->post(
                "{$this->spatialAdminUrl()}/v1/{$dataset}/remove",
                ['ids' => array_values($ids)]
            );
        } catch (\Throwable $e) {
            // Index cleanup is best-effort; see the note above.
        }
    }
}
