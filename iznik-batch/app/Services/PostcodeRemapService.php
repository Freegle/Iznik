<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Remaps postcodes to their nearest enclosing area using the spatial server.
 *
 * When a location area's geometry is created or modified, postcodes within
 * that geometry need to be reassigned to the correct (smallest, nearest) area.
 * This mirrors the V1 PHP Location::remapPostcodes() logic.
 *
 * Queries the iznik-spatial-go service (HTTP) instead of PostgreSQL/PostGIS.
 * The spatial server maintains its own SQLite R-tree index built from MySQL
 * and uses the same 12-level progressive buffer expansion algorithm as V1.
 */
class PostcodeRemapService
{
    private string $spatialServerUrl;

    public function __construct()
    {
        $this->spatialServerUrl = config('freegle.spatial_server_url', 'http://localhost:8194');
    }

    /**
     * Remap postcodes within a given WKT polygon to their nearest area.
     *
     * @param int|null $locationId The location that was modified (unused, kept for interface compatibility).
     * @param string|null $polygon WKT polygon to scope the remap. NULL = remap all.
     * @return int Number of postcodes remapped.
     */
    public function remapPostcodes(?int $locationId = NULL, ?string $polygon = NULL): int
    {
        $pcQuery = DB::table('locations_spatial')
            ->join('locations', 'locations_spatial.locationid', '=', 'locations.id')
            ->where('locations.type', 'Postcode')
            ->whereRaw("LOCATE(' ', locations.name) > 0")
            ->select(
                'locations.id as locations_id',
                'locations_spatial.locationid',
                'locations.name',
                'locations.lat',
                'locations.lng',
                'locations.areaid',
            );

        if ($polygon) {
            $srid = (int) config('freegle.srid', 3857);
            if ($locationId) {
                $pcQuery->where(function ($q) use ($polygon, $locationId, $srid) {
                    $q->whereRaw(
                        "ST_Contains(ST_GeomFromText(?, {$srid}), locations_spatial.geometry)",
                        [$polygon],
                    )->orWhere('locations.areaid', $locationId);
                });
            } else {
                $pcQuery->whereRaw(
                    "ST_Contains(ST_GeomFromText(?, {$srid}), locations_spatial.geometry)",
                    [$polygon],
                );
            }
        }

        $count   = 0;
        $updated = 0;

        $pcQuery->orderBy('locations.id')->chunkById(1000, function ($postcodes) use (&$count, &$updated) {
            foreach ($postcodes as $pc) {
                $newAreaId = $this->findNearestArea($pc->lng, $pc->lat);

                if ($newAreaId && $newAreaId != $pc->areaid) {
                    DB::update('UPDATE locations SET areaid = ? WHERE id = ?', [
                        $newAreaId,
                        $pc->locationid,
                    ]);
                    $updated++;
                }

                $count++;

                if ($count % 1000 === 0) {
                    Log::info("PostcodeRemapService: processed {$count}, updated {$updated}");
                }
            }
        }, 'locations.id', 'locations_id');

        Log::info("PostcodeRemapService: remapped {$updated}/{$count} postcodes");

        return $updated;
    }

    /**
     * Find the nearest area for a given point via the spatial server.
     *
     * @param float $lng Longitude
     * @param float $lat Latitude
     * @return int|null Location ID of the best matching area, or null.
     */
    public function findNearestArea(float $lng, float $lat): ?int
    {
        $response = Http::get("{$this->spatialServerUrl}/knn", [
            'lng' => $lng,
            'lat' => $lat,
        ]);

        if ($response->successful()) {
            $locationid = $response->json('locationid');
            return $locationid !== null ? (int) $locationid : null;
        }

        Log::warning("PostcodeRemapService: spatial server returned {$response->status()} for lng={$lng} lat={$lat}");
        return null;
    }
}
