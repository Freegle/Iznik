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

    /**
     * Ask the spatial server to fully rebuild a dataset's index from MySQL.
     *
     * Used after the WhatJobs sync RENAME-swaps the `jobs` table: the swap
     * drops rows for vanished/closed postings, but the spatial index's 5-min
     * delta only adds/updates (it never removes), so without an explicit
     * rebuild the KNN index keeps returning ids that are gone or now map to a
     * different posting until the nightly 03:00 rebuild — which is what made
     * job clicks stop converting to billable after the KNN cutover (#764).
     *
     * The server rebuild is asynchronous; this just kicks it off. Failures are
     * logged as warnings and do not throw — the periodic delta and the nightly
     * rebuild remain as backstops.
     */
    public function rebuildDataset(string $dataset): void
    {
        try {
            $response = Http::timeout(5)->post("{$this->adminUrl}/v1/{$dataset}/rebuild");

            if (!$response->successful()) {
                Log::warning("SpatialAdmin: rebuild {$dataset} HTTP {$response->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning("SpatialAdmin: rebuild {$dataset} failed: {$e->getMessage()}");
        }
    }
}
