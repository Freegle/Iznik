<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Builds the UK deprivation quintile CSV (lat,lng,quintile) by:
 *   1. Downloading the mySociety composite UK IMD (code → quintile lookup)
 *   2. Fetching England & Wales LSOA centroids from ONS ArcGIS API
 *   3. Fetching Scotland Data Zone centroids from maps.gov.scot
 *   4. Fetching Northern Ireland SOA polygons from OpenDataNI and computing
 *      polygon centroids, transforming from Irish Grid (EPSG:29903) to WGS84
 *
 * This replaces the former Python script (build_uk_deprivation.py) so the
 * project has no Python dependency.
 */
class DeprivationDataService
{
    /**
     * Build the quintile CSV and write it to $outputPath.
     * Each row: lat,lng,quintile
     *
     * @throws RuntimeException on network or data errors
     */
    public function build(string $outputPath): void
    {
        Log::info('DeprivationDataService: downloading IMD lookup');
        $imdLookup = $this->downloadImdLookup();
        Log::info('DeprivationDataService: IMD entries', ['count' => count($imdLookup)]);

        $rows = [];

        Log::info('DeprivationDataService: fetching England & Wales centroids');
        $ewRows = $this->fetchEnglandWalesCentroids($imdLookup);
        Log::info('DeprivationDataService: E&W matched', ['count' => count($ewRows)]);
        array_push($rows, ...$ewRows);

        Log::info('DeprivationDataService: fetching Scotland centroids');
        $scotRows = $this->fetchScotlandCentroids($imdLookup);
        Log::info('DeprivationDataService: Scotland matched', ['count' => count($scotRows)]);
        array_push($rows, ...$scotRows);

        Log::info('DeprivationDataService: fetching Northern Ireland centroids');
        $niRows = $this->fetchNorthernIrelandCentroids($imdLookup);
        Log::info('DeprivationDataService: NI matched', ['count' => count($niRows)]);
        array_push($rows, ...$niRows);

        Log::info('DeprivationDataService: writing CSV', ['rows' => count($rows), 'path' => $outputPath]);
        $this->writeCsv($outputPath, $rows);
    }

    /**
     * Download mySociety composite UK IMD CSV and return [code => quintile] map.
     */
    private function downloadImdLookup(): array
    {
        $url = 'https://raw.githubusercontent.com/mysociety/composite_uk_imd/master/data/uk_index/UK_IMD_E.csv';
        $response = Http::timeout(60)->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Failed to download IMD CSV: HTTP {$response->status()}");
        }

        $lines = explode("\n", trim($response->body()));
        if (count($lines) < 2) {
            throw new RuntimeException("IMD CSV has no data rows");
        }

        $header = str_getcsv(array_shift($lines));
        $codeCol = array_search('lsoa', $header);
        if ($codeCol === false) {
            $codeCol = array_search('Code', $header);
        }
        if ($codeCol === false) {
            throw new RuntimeException("IMD CSV missing 'lsoa' or 'Code' column");
        }

        $quintileCol = array_search('UK_IMD_E_pop_quintile', $header);
        $decileCol   = array_search('E_expanded_decile', $header);
        if ($quintileCol === false && $decileCol === false) {
            throw new RuntimeException("IMD CSV missing quintile or decile column");
        }

        $lookup = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);
            if (!isset($row[$codeCol])) {
                continue;
            }
            $code = trim((string) $row[$codeCol]);
            if ($quintileCol !== false && isset($row[$quintileCol])) {
                $q = (int) $row[$quintileCol];
            } elseif ($decileCol !== false && isset($row[$decileCol])) {
                $q = (int) ceil((int) $row[$decileCol] / 2);
                $q = max(1, min(5, $q));
            } else {
                continue;
            }
            if ($q >= 1 && $q <= 5) {
                $lookup[$code] = $q;
            }
        }

        return $lookup;
    }

    /**
     * Fetch England & Wales LSOA centroids from ONS ArcGIS, paginated.
     * Returns array of [lat, lng, quintile].
     */
    private function fetchEnglandWalesCentroids(array $imdLookup): array
    {
        $url = 'https://services1.arcgis.com/ESMARspQHYMw9BZ9/arcgis/rest/services/'
             . 'LSOA_Dec_2011_PWC_in_England_and_Wales_2022/FeatureServer/0/query';

        $rows   = [];
        $offset = 0;

        while (true) {
            $response = Http::timeout(60)->get($url, [
                'where'             => '1=1',
                'outFields'         => 'lsoa11cd',
                'returnGeometry'    => 'true',
                'outSR'             => '4326',
                'f'                 => 'json',
                'resultOffset'      => $offset,
                'resultRecordCount' => 2000,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("E&W ArcGIS API failed: HTTP {$response->status()}");
            }

            $data  = $response->json();
            $error = $data['error'] ?? null;
            if ($error) {
                throw new RuntimeException("E&W ArcGIS API error: " . json_encode($error));
            }

            foreach ($data['features'] ?? [] as $feature) {
                $code = (string) ($feature['attributes']['lsoa11cd'] ?? '');
                $geo  = $feature['geometry'] ?? [];
                $lng  = $geo['x'] ?? null;
                $lat  = $geo['y'] ?? null;
                if ($lat === null || $lng === null) {
                    continue;
                }
                $q = $imdLookup[$code] ?? null;
                if ($q !== null) {
                    $rows[] = [(float) $lat, (float) $lng, $q];
                }
            }

            if (empty($data['exceededTransferLimit'])) {
                break;
            }
            $offset += 2000;
        }

        return $rows;
    }

    /**
     * Fetch Scotland Data Zone centroids from maps.gov.scot, paginated by objectid.
     * Returns array of [lat, lng, quintile].
     */
    private function fetchScotlandCentroids(array $imdLookup): array
    {
        $url = 'https://maps.gov.scot/server/rest/services/ScotGov/StatisticalUnits/MapServer/4/query';

        $rows    = [];
        $lastOid = 0;

        while (true) {
            $response = Http::timeout(60)->get($url, [
                'where'             => "objectid > {$lastOid}",
                'outFields'         => 'objectid,datazone',
                'returnGeometry'    => 'true',
                'outSR'             => '4326',
                'orderByFields'     => 'objectid',
                'f'                 => 'json',
                'resultRecordCount' => 1000,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("Scotland API failed: HTTP {$response->status()}");
            }

            $data     = $response->json();
            $features = $data['features'] ?? [];

            if (empty($features)) {
                break;
            }

            $lastOid = max(array_column(array_column($features, 'attributes'), 'objectid'));

            foreach ($features as $feature) {
                $code = (string) ($feature['attributes']['datazone'] ?? '');
                $geo  = $feature['geometry'] ?? [];
                $lng  = $geo['x'] ?? null;
                $lat  = $geo['y'] ?? null;
                if ($lat === null || $lng === null) {
                    continue;
                }
                $q = $imdLookup[$code] ?? null;
                if ($q !== null) {
                    $rows[] = [(float) $lat, (float) $lng, $q];
                }
            }

            if (empty($data['exceededTransferLimit'])) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Fetch Northern Ireland SOA polygons from OpenDataNI, compute polygon centroids,
     * and transform from Irish Grid (EPSG:29903) to WGS84.
     * Returns array of [lat, lng, quintile].
     */
    private function fetchNorthernIrelandCentroids(array $imdLookup): array
    {
        $url = 'https://admin.opendatani.gov.uk/dataset/678697e1-ae71-41f3-abba-0ef5f3f352c2'
             . '/resource/80392e82-8bee-42de-a1e3-82d1cbaa983f/download/soa2001.json';

        $response = Http::timeout(120)->get($url);
        if (!$response->successful()) {
            throw new RuntimeException("NI OpenData API failed: HTTP {$response->status()}");
        }

        $geojson  = $response->json();
        $converter = new IrishGridConverter();
        $rows      = [];

        foreach ($geojson['features'] ?? [] as $feature) {
            $props = $feature['properties'] ?? [];
            $code  = (string) ($props['SOA_CODE'] ?? '');
            $geo   = $feature['geometry'] ?? [];
            $type  = $geo['type'] ?? '';
            $coords = $geo['coordinates'] ?? [];

            if ($type === 'Polygon') {
                [$projX, $projY] = $this->polygonCentroid($coords);
            } elseif ($type === 'MultiPolygon') {
                // Use the largest ring (by vertex count)
                usort($coords, fn($a, $b) => count($b[0]) - count($a[0]));
                [$projX, $projY] = $this->polygonCentroid($coords[0]);
            } else {
                continue;
            }

            $wgs84 = $converter->toWgs84($projX, $projY);
            if ($wgs84 === null) {
                continue;
            }
            [$lat, $lng] = $wgs84;

            $q = $imdLookup[$code] ?? null;
            if ($q !== null) {
                $rows[] = [$lat, $lng, $q];
            }
        }

        return $rows;
    }

    /**
     * Compute the signed-area centroid of the first ring of a GeoJSON polygon.
     * Coordinates in the ring are [x, y] pairs.
     * Returns [x, y].
     */
    private function polygonCentroid(array $rings): array
    {
        $ring = $rings[0];
        $n    = count($ring);
        $cx = $cy = $area = 0.0;

        for ($i = 0; $i < $n - 1; $i++) {
            [$x0, $y0] = $ring[$i];
            [$x1, $y1] = $ring[$i + 1];
            $cross  = $x0 * $y1 - $x1 * $y0;
            $area  += $cross;
            $cx    += ($x0 + $x1) * $cross;
            $cy    += ($y0 + $y1) * $cross;
        }

        $area *= 0.5;
        if (abs($area) < 1e-15) {
            $xs = array_column($ring, 0);
            $ys = array_column($ring, 1);
            return [array_sum($xs) / count($xs), array_sum($ys) / count($ys)];
        }

        return [$cx / (6 * $area), $cy / (6 * $area)];
    }

    /**
     * Write rows to a CSV file. Each row is [lat, lng, quintile].
     */
    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        if (!$handle) {
            throw new RuntimeException("Cannot open CSV output path: {$path}");
        }
        try {
            fputcsv($handle, ['lat', 'lng', 'quintile']);
            foreach ($rows as [$lat, $lng, $q]) {
                fputcsv($handle, [$lat, $lng, $q]);
            }
        } finally {
            fclose($handle);
        }
    }
}
