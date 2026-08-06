<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
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

    public function __construct(private SpatialAdminService $spatialAdmin)
    {
        $this->spatialServerUrl = config('freegle.spatial_server_url', 'http://localhost:8194');
    }

    /**
     * Remap postcodes within a given WKT polygon to their nearest area.
     *
     * @param int|null $locationId The area whose geometry changed. When given, the
     *   area is seeded into THIS process's spatial index before the KNN lookups
     *   below, so a brand-new area is found immediately (see seedArea()).
     * @param string|null $polygon WKT polygon to scope the remap. NULL = remap all.
     * @return int Number of postcodes remapped.
     */
    public function remapPostcodes(?int $locationId = NULL, ?string $polygon = NULL): int
    {
        // In production each container runs its own spatial-knn instance with an
        // independent in-memory index (one per DB server, plus one on the batch
        // container). The write-time upsert in the Go API seeds the API's instance,
        // NOT the one this remap queries, so without seeding here findNearestArea()
        // cannot see a just-drawn area until the 15-minute delta. The area then gets
        // no postcodes and looks unsaved until the nightly full remap (Discourse #9950).
        if ($locationId) {
            $this->seedArea($locationId);
        }

        $pcQuery = DB::table('locations_spatial')
            ->join('locations', 'locations_spatial.locationid', '=', 'locations.id')
            ->where('locations.type', 'Postcode')
            // LOCATE(' ', name) > 0 is "name contains a space"; verified identical on
            // edge cases (empty string, leading/trailing space) against LIKE '% %'.
            ->where('locations.name', 'like', '% %')
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
            // keep-raw: ST_Contains/ST_GeomFromText are spatial functions with no
            // query-builder equivalent.
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
                    DB::table('locations')
                        ->where('id', $pc->locationid)
                        ->update(['areaid' => $newAreaId]);
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
     * Seed one area's current geometry into the spatial index this process
     * queries, so findNearestArea() can return it straight away instead of
     * waiting for the next delta sync. Non-fatal: a failure just falls back to
     * the delta and the nightly full remap.
     */
    private function seedArea(int $locationId): void
    {
        // keep-raw: ST_AsText and COALESCE are functions with no query-builder equivalent.
        $row = DB::table('locations')
            ->where('id', $locationId)
            ->select('name', 'type')
            ->selectRaw('ST_AsText(COALESCE(ourgeometry, geometry)) AS wkt')
            ->first();

        if (!$row || empty($row->wkt)) {
            return;
        }

        $this->spatialAdmin->upsertItems('locations', [[
            'id'    => $locationId,
            'wkt'   => $row->wkt,
            'extra' => ['name' => $row->name, 'type' => $row->type],
        ]]);
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
        // type=Area restricts the expanding-buffer search to non-postcode
        // locations, matching the V1 PostGIS candidate pool (which synced
        // everything except postcodes).
        try {
            $response = Http::get("{$this->spatialServerUrl}/v1/locations/knn", [
                'lng'   => $lng,
                'lat'   => $lat,
                'limit' => 1,
                'type'  => 'Area',
            ]);
        } catch (ConnectionException $e) {
            // A transient blip on the spatial server (e.g. mid-rebuild) must not
            // abort the whole remap. Treat it like a failed lookup: skip this
            // postcode (return null => no update) so the run continues.
            Log::warning("PostcodeRemapService: spatial server unreachable for lng={$lng} lat={$lat}: {$e->getMessage()}");
            return null;
        }

        if ($response->successful()) {
            $results = $response->json('results', []);
            return !empty($results) ? (int) $results[0]['id'] : null;
        }

        Log::warning("PostcodeRemapService: spatial server returned {$response->status()} for lng={$lng} lat={$lat}");
        return null;
    }
}
