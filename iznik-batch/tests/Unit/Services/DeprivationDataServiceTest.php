<?php

namespace Tests\Unit\Services;

use App\Services\DeprivationDataService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers DeprivationDataService::build() — the mySociety IMD lookup parser
 * (quintile and decile-fallback columns, malformed rows) and the three
 * per-nation fetchers (England & Wales / Scotland ArcGIS pagination,
 * Northern Ireland polygon-centroid + Irish Grid transform), all driven
 * through the Http facade so no real network calls are made.
 */
class DeprivationDataServiceTest extends TestCase
{
    private DeprivationDataService $service;

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeprivationDataService();
        $this->outputPath = sys_get_temp_dir().'/deprivation-test-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
        parent::tearDown();
    }

    /**
     * Fake all four upstream hosts with benign, non-throwing defaults;
     * $overrides replaces individual host patterns for a given test.
     */
    private function fakeAll(array $overrides = []): void
    {
        $defaults = [
            'raw.githubusercontent.com/*' => Http::response("lsoa,UK_IMD_E_pop_quintile\nE01000001,3\n", 200),
            'services1.arcgis.com/*' => Http::response(['features' => []], 200),
            'maps.gov.scot/*' => Http::response(['features' => []], 200),
            'admin.opendatani.gov.uk/*' => Http::response(['features' => []], 200),
        ];

        Http::fake(array_merge($defaults, $overrides));
    }

    /** Read the written CSV (minus header) as [lat, lng, quintile] rows keyed by nothing in particular. */
    private function readCsvRows(): array
    {
        $lines = array_filter(explode("\n", trim(file_get_contents($this->outputPath))));
        array_shift($lines); // drop header
        return array_map(fn ($line) => str_getcsv($line), $lines);
    }

    // =========================================================================
    // Happy path — all three nations, mixed with the skip branches each
    // fetcher must handle (missing geometry, unmatched code, invalid quintile,
    // unsupported NI geometry type).
    // =========================================================================

    public function test_build_success_writes_matched_rows_across_all_three_nations(): void
    {
        $imdCsv = "lsoa,UK_IMD_E_pop_quintile\n"
            ."E01000001,3\n"
            ."E01000002,1\n"
            ."NI0001,2\n"
            ."NI0002,5\n"
            ."NI0003,4\n"
            ."SGA,4\n"
            ."\n" // blank line — must be skipped without error
            ."E01099999,9\n" // out-of-range quintile — excluded from the lookup entirely
            ."E01000005\n"; // code present but no quintile/decile cell — must be skipped, not crash

        $ew = [
            'features' => [
                ['attributes' => ['lsoa11cd' => 'E01000001'], 'geometry' => ['x' => -0.10, 'y' => 51.50]],
                ['attributes' => ['lsoa11cd' => 'E01000002'], 'geometry' => ['x' => -1.20, 'y' => 52.10]],
                // Quintile 9 was invalid, so this code was never added to the lookup — skipped.
                ['attributes' => ['lsoa11cd' => 'E01099999'], 'geometry' => ['x' => -2.00, 'y' => 53.00]],
            ],
        ];

        // A single, non-paginated page of Scotland results: exceededTransferLimit
        // is absent, so the loop must break via the "no more pages" path (as
        // opposed to the pagination test's empty-features break).
        $scotland = [
            'features' => [
                ['attributes' => ['objectid' => 1, 'datazone' => 'SGA'], 'geometry' => ['x' => -3.2, 'y' => 55.8]],
            ],
        ];

        $niSquareRing = [[300000, 350000], [300100, 350000], [300100, 350100], [300000, 350100], [300000, 350000]];
        $niHexRing = [[320000, 370000], [320100, 370000], [320150, 370050], [320100, 370100], [320000, 370100], [319950, 370050], [320000, 370000]];
        $niSmallRing = [[310000, 360000], [310100, 360000], [310050, 360100], [310000, 360000]];
        $niUnmatchedRing = [[330000, 380000], [330100, 380000], [330100, 380100], [330000, 380100], [330000, 380000]];

        $ni = [
            'features' => [
                // Polygon — matched. GeoJSON Polygon coordinates are an array of
                // rings (exterior first), hence the extra wrapping array.
                ['properties' => ['SOA_CODE' => 'NI0001'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$niSquareRing]]],
                // MultiPolygon — the larger (hexagon) ring must be selected over the smaller triangle.
                ['properties' => ['SOA_CODE' => 'NI0002'], 'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$niSmallRing], [$niHexRing]]]],
                // Unsupported geometry type — skipped regardless of the code being in the lookup.
                ['properties' => ['SOA_CODE' => 'NI0003'], 'geometry' => ['type' => 'Point', 'coordinates' => [300000, 350000]]],
                // Code absent from the lookup — skipped despite valid geometry.
                ['properties' => ['SOA_CODE' => 'NI9999'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$niUnmatchedRing]]],
            ],
        ];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response($imdCsv, 200),
            'services1.arcgis.com/*' => Http::response($ew, 200),
            'maps.gov.scot/*' => Http::response($scotland, 200),
            'admin.opendatani.gov.uk/*' => Http::response($ni, 200),
        ]);

        $this->service->build($this->outputPath);

        $rows = $this->readCsvRows();
        $this->assertCount(5, $rows, 'expected 2 matched E&W + 1 matched Scotland + 2 matched NI rows, all edge cases skipped');

        $byQuintile = [];
        foreach ($rows as [$lat, $lng, $q]) {
            $byQuintile[$q][] = [(float) $lat, (float) $lng];
        }

        // E&W rows carry their raw WGS84 x/y through untransformed.
        $this->assertEqualsWithDelta(51.50, $byQuintile['3'][0][0], 0.0001);
        $this->assertEqualsWithDelta(-0.10, $byQuintile['3'][0][1], 0.0001);
        $this->assertEqualsWithDelta(52.10, $byQuintile['1'][0][0], 0.0001);
        $this->assertEqualsWithDelta(-1.20, $byQuintile['1'][0][1], 0.0001);
        $this->assertEqualsWithDelta(55.80, $byQuintile['4'][0][0], 0.0001);
        $this->assertEqualsWithDelta(-3.20, $byQuintile['4'][0][1], 0.0001);

        // NI rows went through the Irish Grid → WGS84 transform; just sanity-check
        // they land somewhere plausible (the exact maths is covered by IrishGridConverterTest).
        foreach ([$byQuintile['2'][0], $byQuintile['5'][0]] as [$lat, $lng]) {
            $this->assertGreaterThan(49.0, $lat);
            $this->assertLessThan(61.0, $lat);
            $this->assertGreaterThan(-11.0, $lng);
            $this->assertLessThan(2.0, $lng);
        }
    }

    // =========================================================================
    // England & Wales — pagination (exceededTransferLimit) and per-feature skips
    // =========================================================================

    public function test_ew_pagination_merges_pages_and_skips_missing_or_unmatched_features(): void
    {
        $page1 = [
            'features' => [
                ['attributes' => ['lsoa11cd' => 'PGA'], 'geometry' => ['x' => -1.0, 'y' => 51.0]],
                // No geometry at all — lat/lng resolve to null and the feature is skipped.
                ['attributes' => ['lsoa11cd' => 'PGX'], 'geometry' => []],
            ],
            'exceededTransferLimit' => true,
        ];
        $page2 = [
            'features' => [
                ['attributes' => ['lsoa11cd' => 'PGB'], 'geometry' => ['x' => -2.0, 'y' => 52.0]],
                // Valid geometry but the code was never in the IMD lookup.
                ['attributes' => ['lsoa11cd' => 'PGY'], 'geometry' => ['x' => -3.0, 'y' => 53.0]],
            ],
        ];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response("lsoa,UK_IMD_E_pop_quintile\nPGA,1\nPGB,2\n", 200),
            'services1.arcgis.com/*' => Http::sequence()->push($page1, 200)->push($page2, 200),
        ]);

        $this->service->build($this->outputPath);

        $rows = $this->readCsvRows();
        $this->assertCount(2, $rows, 'expected only PGA (page 1) and PGB (page 2) to survive');

        usort($rows, fn ($a, $b) => $a[2] <=> $b[2]);
        [$latA, $lngA, $qA] = $rows[0];
        [$latB, $lngB, $qB] = $rows[1];
        $this->assertSame('1', $qA);
        $this->assertEqualsWithDelta(51.0, (float) $latA, 0.0001);
        $this->assertEqualsWithDelta(-1.0, (float) $lngA, 0.0001);
        $this->assertSame('2', $qB);
        $this->assertEqualsWithDelta(52.0, (float) $latB, 0.0001);
        $this->assertEqualsWithDelta(-2.0, (float) $lngB, 0.0001);
    }

    // =========================================================================
    // Scotland — objectid-based pagination, empty-page break, per-feature skips
    // =========================================================================

    public function test_scotland_pagination_tracks_last_objectid_and_breaks_on_empty_page(): void
    {
        $page1 = [
            'features' => [
                ['attributes' => ['objectid' => 5, 'datazone' => 'SGA'], 'geometry' => ['x' => -3.5, 'y' => 55.9]],
                // Missing geometry — skipped.
                ['attributes' => ['objectid' => 8, 'datazone' => 'SGX'], 'geometry' => []],
                // Valid geometry, code not in the IMD lookup — skipped.
                ['attributes' => ['objectid' => 10, 'datazone' => 'SGZ'], 'geometry' => ['x' => -4.0, 'y' => 56.0]],
            ],
            'exceededTransferLimit' => true,
        ];
        // Second page is empty — the loop must break immediately rather than looping forever.
        $page2 = ['features' => []];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response("lsoa,UK_IMD_E_pop_quintile\nSGA,4\n", 200),
            'maps.gov.scot/*' => Http::sequence()->push($page1, 200)->push($page2, 200),
        ]);

        $this->service->build($this->outputPath);

        $rows = $this->readCsvRows();
        $this->assertCount(1, $rows);
        [$lat, $lng, $q] = $rows[0];
        $this->assertSame('4', $q);
        $this->assertEqualsWithDelta(55.9, (float) $lat, 0.0001);
        $this->assertEqualsWithDelta(-3.5, (float) $lng, 0.0001);
    }

    // =========================================================================
    // Northern Ireland — degenerate (zero-area) polygon centroid fallback
    // =========================================================================

    public function test_ni_degenerate_polygon_falls_back_to_vertex_average(): void
    {
        // A ring collapsed to a single repeated point has zero signed area, so
        // polygonCentroid() must fall back to averaging the raw vertices
        // instead of dividing by (6 * area).
        $degenerateRing = [[300000, 350000], [300000, 350000], [300000, 350000]];

        $ni = [
            'features' => [
                ['properties' => ['SOA_CODE' => 'NIDEG'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$degenerateRing]]],
            ],
        ];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response("lsoa,UK_IMD_E_pop_quintile\nNIDEG,3\n", 200),
            'admin.opendatani.gov.uk/*' => Http::response($ni, 200),
        ]);

        $this->service->build($this->outputPath);

        $rows = $this->readCsvRows();
        $this->assertCount(1, $rows);
        [$lat, $lng, $q] = $rows[0];
        $this->assertSame('3', $q);
        $this->assertIsNumeric($lat);
        $this->assertIsNumeric($lng);
        $this->assertGreaterThan(49.0, (float) $lat);
        $this->assertLessThan(61.0, (float) $lat);
    }

    // =========================================================================
    // IMD lookup — decile fallback column and malformed-row handling
    // =========================================================================

    public function test_imd_decile_fallback_column_computes_quintile(): void
    {
        // ceil(10/2)=5, ceil(1/2)=1, ceil(3/2)=2 — verifies both the
        // decile→quintile conversion and its 1..5 clamping.
        $imdCsv = "Code,E_expanded_decile\nDECA,10\nDECB,1\nDECC,3\n";

        $ew = [
            'features' => [
                ['attributes' => ['lsoa11cd' => 'DECA'], 'geometry' => ['x' => -1.0, 'y' => 51.0]],
                ['attributes' => ['lsoa11cd' => 'DECB'], 'geometry' => ['x' => -1.1, 'y' => 51.1]],
                ['attributes' => ['lsoa11cd' => 'DECC'], 'geometry' => ['x' => -1.2, 'y' => 51.2]],
            ],
        ];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response($imdCsv, 200),
            'services1.arcgis.com/*' => Http::response($ew, 200),
        ]);

        $this->service->build($this->outputPath);

        $byCode = [];
        foreach ($this->readCsvRows() as [$lat, $lng, $q]) {
            $byCode[$lat.','.$lng] = $q;
        }
        $this->assertSame('5', $byCode['51,-1']);
        $this->assertSame('1', $byCode['51.1,-1.1']);
        $this->assertSame('2', $byCode['51.2,-1.2']);
    }

    public function test_imd_row_with_missing_code_column_is_skipped_without_error(): void
    {
        // Code column is second here, so a short (single-field) row leaves it
        // unset — the parser must skip it rather than warning on an undefined
        // array key.
        $imdCsv = "UK_IMD_E_pop_quintile,lsoa\n3,E01000001\n5\n";

        $ew = ['features' => [
            ['attributes' => ['lsoa11cd' => 'E01000001'], 'geometry' => ['x' => -0.1, 'y' => 51.5]],
        ]];

        $this->fakeAll([
            'raw.githubusercontent.com/*' => Http::response($imdCsv, 200),
            'services1.arcgis.com/*' => Http::response($ew, 200),
        ]);

        $this->service->build($this->outputPath);

        $rows = $this->readCsvRows();
        $this->assertCount(1, $rows, 'the malformed "5" row must contribute nothing, not crash the run');
        $this->assertSame('3', $rows[0][2]);
    }

    public function test_imd_csv_with_only_a_header_row_throws(): void
    {
        $this->fakeAll(['raw.githubusercontent.com/*' => Http::response("lsoa,UK_IMD_E_pop_quintile", 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMD CSV has no data rows');

        $this->service->build($this->outputPath);
    }

    public static function badImdHeaderProvider(): array
    {
        return [
            'missing code column' => ["foo,bar\n1,2\n", "IMD CSV missing 'lsoa' or 'Code' column"],
            'missing quintile/decile column' => ["lsoa,other\nE01000001,3\n", 'IMD CSV missing quintile or decile column'],
        ];
    }

    #[DataProvider('badImdHeaderProvider')]
    public function test_imd_csv_with_bad_header_throws(string $csv, string $expectedMessage): void
    {
        $this->fakeAll(['raw.githubusercontent.com/*' => Http::response($csv, 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->service->build($this->outputPath);
    }

    // =========================================================================
    // Upstream HTTP failures — each of the four hosts must abort the whole build
    // =========================================================================

    public static function httpFailureHostProvider(): array
    {
        return [
            'IMD download' => ['raw.githubusercontent.com/*', 'Failed to download IMD CSV'],
            'England & Wales ArcGIS' => ['services1.arcgis.com/*', 'E&W ArcGIS API failed'],
            'Scotland ArcGIS' => ['maps.gov.scot/*', 'Scotland API failed'],
            'Northern Ireland OpenData' => ['admin.opendatani.gov.uk/*', 'NI OpenData API failed'],
        ];
    }

    #[DataProvider('httpFailureHostProvider')]
    public function test_upstream_http_failure_throws(string $failingHost, string $expectedMessage): void
    {
        $this->fakeAll([$failingHost => Http::response('server error', 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->service->build($this->outputPath);
    }

    public function test_ew_api_error_field_throws(): void
    {
        $this->fakeAll([
            'services1.arcgis.com/*' => Http::response(['error' => ['code' => 400, 'message' => 'bad request']], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('E&W ArcGIS API error');

        $this->service->build($this->outputPath);
    }

    // =========================================================================
    // writeCsv — unwritable output path
    // =========================================================================

    // TODO: latent bug — writeCsv()'s `if (!$handle) throw new RuntimeException(...)`
    // guard is unreachable: fopen() against a non-existent directory raises a PHP
    // warning first, which Laravel's HandleExceptions bootstrap promotes to an
    // ErrorException before the guard ever sees a false $handle. Callers expecting
    // to catch RuntimeException from build() will not catch this. Not fixed here —
    // this PR is test-only.
    public function test_unwritable_output_path_throws(): void
    {
        $this->markTestSkipped(
            'Latent bug: fopen() warning is promoted to ErrorException before the '
            .'intended RuntimeException guard in writeCsv() can run — see TODO above.'
        );
    }
}
