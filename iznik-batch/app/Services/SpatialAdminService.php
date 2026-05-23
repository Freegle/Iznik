<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpatialAdminService
{
    private string $adminUrl;

    public function __construct()
    {
        $this->adminUrl = rtrim(config('freegle.spatial_admin_url', 'http://localhost:8195'), '/');
    }

    /**
     * Notify the spatial server to remove specific IDs from a dataset.
     *
     * Failures are logged as warnings and do not throw — the spatial server
     * will catch up on its next incremental sync or nightly rebuild.
     */
    public function removeItems(string $dataset, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        try {
            $response = Http::timeout(5)->post(
                "{$this->adminUrl}/v1/{$dataset}/remove",
                ['ids' => array_values($ids)]
            );

            if (!$response->successful()) {
                Log::warning("SpatialAdmin: remove {$dataset} HTTP {$response->status()}", [
                    'ids_count' => count($ids),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("SpatialAdmin: remove {$dataset} failed: {$e->getMessage()}", [
                'ids_count' => count($ids),
            ]);
        }
    }
}
