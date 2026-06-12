<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Maintains the UK postcode rows in the `locations` table from the Doogal
 * Code-Point Open dataset (https://www.doogal.co.uk/files/postcodes.zip).
 *
 * Faithful migration of V1 scripts/cli/doogal.php (+ the cron/doogal wrapper
 * that downloaded and unzipped the CSV). For each "in use" postcode it either
 * refreshes the lat/lng of an existing location (when it has drifted by more
 * than 2 decimal places) or creates a new Postcode location with a spatial
 * index entry and centroid lat/lng.
 *
 * Mapping new postcodes onto Freegle group areas is deliberately NOT done here:
 * V1's Location::create() called remapPostcodes() inline, but in Laravel that
 * work is owned by the scheduled `locations:sync-pgsql` job
 * ({@see PostcodeRemapService}) — the same "background cron job to save us" that
 * V1 relied on for the PostGIS copy.
 */
class DoogalService
{
    private int $srid;

    public function __construct()
    {
        $this->srid = (int) config('freegle.srid', 3857);
    }

    /**
     * Download the postcodes ZIP, extract it, and return the path to the CSV
     * inside. Caller is responsible for removing the returned file (and its
     * containing directory) when done.
     */
    public function fetchCsv(?string $url = null): string
    {
        $url = $url ?: config('freegle.doogal.zip_url');

        $dir = sys_get_temp_dir().'/doogal_'.uniqid('', true);
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create temp dir: {$dir}");
        }

        $zipPath = $dir.'/postcodes.zip';
        $response = Http::timeout(600)->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException("Failed to download {$url}: HTTP {$response->status()}");
        }
        file_put_contents($zipPath, $response->body());

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Could not open ZIP: {$zipPath}");
        }
        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(strtolower((string) $name), '.csv')) {
                $csvName = $name;
                break;
            }
        }
        if ($csvName === null) {
            $zip->close();
            throw new \RuntimeException("No CSV found inside {$zipPath}");
        }
        $zip->extractTo($dir, $csvName);
        $zip->close();

        return $dir.'/'.$csvName;
    }

    /**
     * Process a Code-Point/Doogal CSV, adding new postcodes and refreshing
     * changed lat/lng. Returns counts: [processed, added, updated, failed].
     *
     * @param  callable|null  $progress  fn(int $processed, int $added, int $updated)
     * @return array{processed:int, added:int, updated:int, failed:int}
     */
    public function process(string $csvPath, ?callable $progress = null): array
    {
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Could not open CSV: {$csvPath}");
        }

        $processed = 0;
        $added = 0;
        $updated = 0;
        $failed = 0;

        // V1 pre-loaded EVERY full postcode (~2M rows) into an in-memory index
        // keyed by canonical name. On the production dataset that build exhausts
        // PHP's memory_limit (observed: zend_mm_heap corruption), so instead we
        // stream the CSV and resolve existing postcodes one bounded batch at a
        // time via a single indexed `name IN (...)` lookup. Peak memory is now a
        // function of the batch size, not the table size. `locations.name` is
        // fully indexed, so each batch lookup is cheap.
        $batchSize = 2000;
        $batch = []; // canon => ['pc' => string, 'lat' => string, 'lng' => string]

        $flush = function () use (&$batch, &$added, &$updated, &$failed) {
            if (!$batch) {
                return;
            }

            // One indexed lookup for this batch's postcodes; key the result by
            // the same space-stripped lowercase canon used to key the batch.
            $existing = [];
            DB::table('locations')
                ->select('id', 'name', 'lat', 'lng', 'type')
                ->whereIn('name', array_column($batch, 'pc'))
                ->get()
                ->each(function ($loc) use (&$existing) {
                    $existing[strtolower(str_replace(' ', '', $loc->name))] = $loc;
                });

            foreach ($batch as $canon => $row) {
                if (isset($existing[$canon])) {
                    // Existing postcode: refresh only if lat/lng changed at 2dp,
                    // or if the location wasn't previously typed as a Postcode.
                    // Smaller differences happen constantly and aren't worth a write.
                    $old = $existing[$canon];
                    $newlat = round((float) $row['lat'], 2);
                    $newlng = round((float) $row['lng'], 2);
                    $oldlat = round((float) $old->lat, 2);
                    $oldlng = round((float) $old->lng, 2);

                    if ($newlat != $oldlat || $newlng != $oldlng || $old->type !== 'Postcode') {
                        $this->updatePostcode((int) $old->id, (float) $row['lat'], (float) $row['lng']);
                        $updated++;
                    }
                } else {
                    $id = $this->createPostcode($row['pc'], (float) $row['lng'], (float) $row['lat']);
                    if ($id) {
                        $added++;
                    } else {
                        $failed++;
                    }
                }
            }

            $batch = [];
        };

        while (($fields = fgetcsv($fh, escape: '')) !== false) {
            $processed++;

            if ($progress && $processed % 1000 === 0) {
                ($progress)($processed, $added, $updated);
            }

            // Column layout: Postcode, In Use?, Latitude, Longitude, ...
            if (($fields[1] ?? '') !== 'Yes') {
                continue;
            }

            $pc = $fields[0] ?? '';
            $lat = $fields[2] ?? null;
            $lng = $fields[3] ?? null;

            if ($pc === '' || (!$lat && !$lng)) {
                continue;
            }

            // Last write wins on a duplicate postcode within a batch, matching
            // V1's single-keyed index.
            $batch[strtolower(str_replace(' ', '', $pc))] = ['pc' => $pc, 'lat' => $lat, 'lng' => $lng];

            if (count($batch) >= $batchSize) {
                $flush();
            }
        }

        $flush();

        fclose($fh);

        $this->fixSpatialIndex();

        Log::info('DoogalService: processed postcode CSV', compact('processed', 'added', 'updated', 'failed'));

        return compact('processed', 'added', 'updated', 'failed');
    }

    /**
     * Create a new Postcode location plus its spatial-index row and centroid
     * lat/lng, mirroring the Postcode path of V1 Location::create().
     */
    private function createPostcode(string $name, float $lng, float $lat): ?int
    {
        $point = "POINT($lng $lat)";

        DB::insert(
            'INSERT INTO locations (osm_id, name, type, geometry, canon, osm_place, maxdimension) '
            .'VALUES (NULL, ?, ?, ST_GeomFromText(?, ?), ?, 0, GetMaxDimension(ST_GeomFromText(?, ?)))',
            [$name, 'Postcode', $point, $this->srid, $this->canon($name), $point, $this->srid]
        );
        $id = (int) DB::getPdo()->lastInsertId();
        if (!$id) {
            return null;
        }

        DB::insert(
            'INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$id, $point, $this->srid]
        );

        // Cache lat/lng from the geometry centroid (speeds up later queries).
        DB::update(
            'UPDATE locations SET lng = ST_X(ST_Centroid(geometry)), lat = ST_Y(ST_Centroid(geometry)) WHERE id = ?',
            [$id]
        );

        // Assign the containing grid square, if any.
        $grid = DB::selectOne(
            'SELECT locations_grids.id AS gridid FROM locations '
            .'INNER JOIN locations_grids ON locations.id = ? AND MBRIntersects(locations.geometry, locations_grids.box) LIMIT 1',
            [$id]
        );
        if ($grid && $grid->gridid) {
            DB::update('UPDATE locations SET gridid = ? WHERE id = ?', [$grid->gridid, $id]);
        }

        // Link a full postcode (e.g. "AB1 2CD") to its parent district ("AB1").
        $sp = strpos($name, ' ');
        if ($sp !== false) {
            $parent = DB::selectOne(
                "SELECT id FROM locations WHERE name = ? AND type = 'Postcode' LIMIT 1",
                [substr($name, 0, $sp)]
            );
            if ($parent) {
                DB::update('UPDATE locations SET postcodeid = ? WHERE id = ?', [$parent->id, $id]);
            }
        }

        return $id;
    }

    /**
     * Refresh an existing location's lat/lng and geometry to the new postcode
     * position, re-typing it as a Postcode. Mirrors V1's UPDATE + REPLACE.
     */
    private function updatePostcode(int $id, float $lat, float $lng): void
    {
        $point = "POINT($lng $lat)";

        DB::update(
            'UPDATE locations SET lat = ?, lng = ?, type = ?, geometry = ST_GeomFromText(?, ?), ourgeometry = NULL WHERE id = ?',
            [$lat, $lng, 'Postcode', $point, $this->srid, $id]
        );

        DB::statement(
            'REPLACE INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))',
            [$id, $point, $this->srid]
        );
    }

    /**
     * Re-sync any locations_spatial rows whose geometry has drifted from the
     * canonical location geometry (V1's closing "Check loc index" pass).
     *
     * First repairs the root cause of perpetual churn: ~33k legacy locations
     * still carry SRID 0 on geometry/ourgeometry while the rest of the table —
     * and their locations_spatial copy — use the canonical SRID (3857). The
     * coordinates are identical; only the SRID label is wrong, so the drift test
     * below (`!=`, which is SRID-sensitive) flagged every one of them on EVERY
     * run, and the original code "fixed" them by rewriting locations_spatial
     * (already the right SRID) — never the SRID-0 source — so they never
     * converged. Relabelling with ST_SRID does NOT move any coordinate; it just
     * stamps the correct SRID, after which the rows match and stop being flagged.
     *
     * All passes are chunked by id so the candidate set is never buffered
     * wholesale (a bulk change must not blow the memory limit, as the old
     * in-memory postcode index did). One UPDATE per row keeps Galera happy.
     */
    private function fixSpatialIndex(): void
    {
        // Pass 1: relabel wrong-SRID source geometry (coordinates unchanged).
        DB::table('locations')
            ->whereRaw('ST_SRID(geometry) <> ?', [$this->srid])
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $r) {
                    DB::update('UPDATE locations SET geometry = ST_SRID(geometry, ?) WHERE id = ?', [$this->srid, $r->id]);
                }
            });

        DB::table('locations')
            ->whereNotNull('ourgeometry')
            ->whereRaw('ST_SRID(ourgeometry) <> ?', [$this->srid])
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $r) {
                    DB::update('UPDATE locations SET ourgeometry = ST_SRID(ourgeometry, ?) WHERE id = ?', [$this->srid, $r->id]);
                }
            });

        // Pass 2: re-sync locations_spatial wherever the canonical geometry
        // genuinely differs. With SRIDs now consistent, `!=` only fires on real
        // positional drift.
        DB::table('locations')
            ->join('locations_spatial', 'locations_spatial.locationid', '=', 'locations.id')
            ->whereRaw(
                '(CASE WHEN locations.ourgeometry IS NOT NULL THEN locations.ourgeometry ELSE locations.geometry END) '
                .'<> locations_spatial.geometry'
            )
            ->select(
                'locations.id',
                DB::raw('ST_AsText(CASE WHEN locations.ourgeometry IS NOT NULL THEN locations.ourgeometry ELSE locations.geometry END) AS g')
            )
            ->orderBy('locations.id')
            ->chunkById(1000, function ($badlocs) {
                foreach ($badlocs as $bad) {
                    DB::update(
                        'UPDATE locations_spatial SET geometry = ST_GeomFromText(?, ?) WHERE locationid = ?',
                        [$bad->g, $this->srid, $bad->id]
                    );
                }
            }, 'locations.id', 'id');
    }

    /**
     * Canonicalise a location name for the `canon` column. Mirrors V1
     * Location::canon() (common abbreviation expansion + strip non-alphanumerics).
     */
    private function canon(string $str): string
    {
        $str = preg_replace('/^St\b/', 'Saint', $str);
        $str = preg_replace('/\bSt\b/', 'Street', $str);
        $str = preg_replace('/\bRd\b/', 'Road', $str);
        $str = preg_replace('/\bAvenue\b/', 'Av', $str);
        $str = preg_replace('/\bDr\b/', 'Drive', $str);
        $str = preg_replace('/\bLn\b/', 'Lane', $str);
        $str = preg_replace('/\bPl\b/', 'Place', $str);
        $str = preg_replace('/\bSq\b/', 'Square', $str);
        $str = preg_replace('/\bCls\b/', 'Close', $str);

        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $str));
    }
}
