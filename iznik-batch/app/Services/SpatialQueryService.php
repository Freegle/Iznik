<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Read-side client for the iznik-spatial-go "finder" service.
 *
 * Replaces the per-table expanding-bounding-box MySQL nearest-neighbour loops
 * with a single call to the spatial server's R-tree KNN, which is exact and
 * needs one round-trip instead of up to a dozen MySQL queries.
 *
 * @see SpatialAdminService for the write/remove side.
 */
class SpatialQueryService
{
    private string $url;

    public function __construct()
    {
        $this->url = rtrim(config('freegle.spatial_server_url', 'http://localhost:8194'), '/');
    }

    /**
     * Return the external IDs of the nearest records in $dataset to (lat, lng),
     * nearest first, up to $limit. Returns [] if the spatial server errors or is
     * unreachable (logged) — callers treat that as "nothing nearby".
     *
     * @return int[]
     */
    public function nearestIds(string $dataset, float $lat, float $lng, int $limit = 1, ?string $type = null): array
    {
        $params = ['lat' => $lat, 'lng' => $lng, 'limit' => $limit];
        if ($type !== null) {
            $params['type'] = $type;
        }

        try {
            $response = Http::timeout(5)->get("{$this->url}/v1/{$dataset}/knn", $params);

            if ($response->successful()) {
                return array_map(
                    static fn ($r) => (int) $r['id'],
                    $response->json('results', []) ?? []
                );
            }

            Log::warning("SpatialQuery: {$dataset} knn HTTP {$response->status()}", [
                'lat' => $lat,
                'lng' => $lng,
            ]);
        } catch (\Throwable $e) {
            Log::warning("SpatialQuery: {$dataset} knn failed: {$e->getMessage()}", [
                'lat' => $lat,
                'lng' => $lng,
            ]);
        }

        return [];
    }
}
