<?php

namespace Tests\Unit\Services;

use App\Services\PostcodeDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Behaviour of {@see PostcodeDirectoryService}, the importer of the ONS Postcode
 * Directory. Exercises create / update / skip decisions and the spatial index
 * against the real test database (geometry + stored functions).
 */
class PostcodeDirectoryServiceTest extends TestCase
{
    private PostcodeDirectoryService $service;

    private int $srid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PostcodeDirectoryService();
        $this->srid = (int) config('freegle.srid', 3857);
    }

    /** The ONSPD columns, in the order of the August 2026 release, up to the coordinates. */
    private const HEADER = ['pcd7', 'pcd8', 'pcds', 'dointr', 'doterm', 'cty26cd', 'lat', 'long', 'imd25ind'];

    /** Write an ONSPD style CSV, header first, and return its path. */
    private function writeCsv(array $rows, ?array $header = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'onspdtest');
        $fh = fopen($path, 'w');
        fputcsv($fh, $header ?? self::HEADER);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }

    /** A single ONSPD row. $terminated is the doterm, blank while the postcode is in use. */
    private function row(string $pc, string $terminated, $lat, $lng): array
    {
        return [str_replace(' ', '', $pc), $pc, $pc, '199002', $terminated, 'E10000015', $lat, $lng, '31728'];
    }

    private function insertPostcode(string $name, ?float $lat = null, ?float $lng = null): int
    {
        $id = DB::table('locations')->insertGetId([
            'name' => $name,
            'type' => 'Postcode',
            'osm_place' => 0,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        if ($lat !== null && $lng !== null) {
            $point = "POINT($lng $lat)";
            DB::statement('UPDATE locations SET geometry = ST_GeomFromText(?, ?) WHERE id = ?', [$point, $this->srid, $id]);
            DB::statement('INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, ?))', [$id, $point, $this->srid]);
        }

        return (int) $id;
    }

    public function test_creates_new_postcode_with_spatial_centroid_and_parent_link(): void
    {
        $parentId = $this->insertPostcode('AB1');

        $csv = $this->writeCsv([$this->row('AB1 2CD', '', 55.10, -3.20)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['updated']);

        $loc = DB::table('locations')->where('name', 'AB1 2CD')->first();
        $this->assertNotNull($loc);
        $this->assertSame('Postcode', $loc->type);
        $this->assertEqualsWithDelta(55.10, (float) $loc->lat, 0.001);
        $this->assertEqualsWithDelta(-3.20, (float) $loc->lng, 0.001);
        $this->assertSame($parentId, (int) $loc->postcodeid);
        $this->assertSame('ab12cd', $loc->canon);

        $this->assertDatabaseHas('locations_spatial', ['locationid' => $loc->id]);
    }

    public function test_updates_existing_postcode_when_latlng_moved_more_than_2dp(): void
    {
        $id = $this->insertPostcode('CB2 3EF', 52.20, 0.10);

        // Latitude moves from 52.20 to 52.25 (> 0.01 at 2dp).
        $csv = $this->writeCsv([$this->row('CB2 3EF', '', 52.25, 0.10)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['added']);

        $loc = DB::table('locations')->where('id', $id)->first();
        $this->assertEqualsWithDelta(52.25, (float) $loc->lat, 0.001);
    }

    public function test_skips_postcode_not_in_use(): void
    {
        $csv = $this->writeCsv([$this->row('ZZ9 9ZZ', '201905', 50.0, -1.0)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseMissing('locations', ['name' => 'ZZ9 9ZZ']);
    }

    public function test_skips_unchanged_postcode_within_2dp(): void
    {
        $id = $this->insertPostcode('DE1 4GH', 52.90, -1.47);

        // 52.901 / -1.473 both round to the stored 2dp values → no write.
        $csv = $this->writeCsv([$this->row('DE1 4GH', '', 52.901, -1.473)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['added']);
    }

    public function test_skips_postcode_with_no_grid_reference(): void
    {
        $csv = $this->writeCsv([$this->row('ZZ1 1ZZ', '', 99.999999, 0.000000)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(0, $result['added']);
        $this->assertDatabaseMissing('locations', ['name' => 'ZZ1 1ZZ']);
    }

    public function test_finds_columns_by_name_whatever_their_order(): void
    {
        $header = ['long', 'doterm', 'lat', 'oa21cd', 'pcds'];
        $csv = $this->writeCsv([['-3.20', '', '55.10', 'S00000001', 'AB1 3EF']], $header);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(1, $result['added']);
        $loc = DB::table('locations')->where('name', 'AB1 3EF')->first();
        $this->assertEqualsWithDelta(55.10, (float) $loc->lat, 0.001);
        $this->assertEqualsWithDelta(-3.20, (float) $loc->lng, 0.001);
    }

    public function test_refuses_a_csv_without_the_onspd_columns(): void
    {
        $csv = $this->writeCsv([['AB1 2CD', 'Yes', '55.1', '-3.2']], ['Postcode', 'In Use?', 'Latitude', 'Longitude']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("no 'pcds' column");
            $this->service->process($csv);
        } finally {
            @unlink($csv);
        }
    }

    /** A release zip as ONS lays it out: the UK file beside per-area copies and documents. */
    private function releaseZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'onspdzip');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('Documents/CTRY Country names and codes UK as at 05_25.csv', "CTRY25CD,CTRY25NM\n");
        $zip->addFromString('Data/multi_csv/ONSPD_AUG_2026_UK_AB.csv', "pcds,doterm,lat,long\nAB1 0AA,,57.1,-2.2\n");
        $zip->addFromString('Data/ONSPD_AUG_2026_UK.csv', "pcds,doterm,lat,long\nAB1 2CD,,55.1,-3.2\n");
        $zip->close();
        $bytes = file_get_contents($zipPath);
        @unlink($zipPath);

        return $bytes;
    }

    public function test_fetch_csv_extracts_the_whole_uk_file(): void
    {
        Http::fake(['*' => Http::response($this->releaseZip(), 200)]);

        $csvPath = $this->service->fetchCsv('https://example.test/onspd.zip');
        $this->assertFileExists($csvPath);
        $this->assertStringContainsString('AB1 2CD', file_get_contents($csvPath));
        $this->assertStringNotContainsString('AB1 0AA', file_get_contents($csvPath));

        // Only the CSV is left, so the command's cleanup of the file and its directory is complete.
        $this->assertSame(['onspd.csv'], array_values(array_diff(scandir(dirname($csvPath)), ['.', '..'])));

        @unlink($csvPath);
        @rmdir(dirname($csvPath));
    }

    public function test_fetch_csv_finds_the_newest_release_when_no_url_is_set(): void
    {
        config(['freegle.postcodes.zip_url' => '']);
        Http::fake([
            'www.arcgis.com/sharing/rest/search*' => Http::response(['results' => [
                ['id' => 'guide1', 'title' => 'ONS Postcode Directory (August 2026) User Guide'],
                ['id' => 'aug2026', 'title' => 'ONS Postcode Directory (August 2026)'],
                ['id' => 'may2026', 'title' => 'ONS Postcode Directory (May 2026)'],
            ]], 200),
            'www.arcgis.com/sharing/rest/content/items/aug2026/data' => Http::response($this->releaseZip(), 200),
            '*' => Http::response('', 404),
        ]);

        $csvPath = $this->service->fetchCsv();
        $this->assertStringContainsString('AB1 2CD', file_get_contents($csvPath));

        @unlink($csvPath);
        @rmdir(dirname($csvPath));
    }

    public function test_fetch_csv_reports_a_failed_download(): void
    {
        Http::fake(['*' => Http::response('challenge', 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $this->service->fetchCsv('https://example.test/onspd.zip');
    }
}
