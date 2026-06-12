<?php

namespace Tests\Unit\Services;

use App\Services\DoogalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Behaviour of {@see DoogalService} — the postcode importer migrated from V1
 * cli/doogal.php. Exercises create / update / skip decisions and the spatial
 * index against the real test database (geometry + stored functions).
 */
class DoogalServiceTest extends TestCase
{
    private DoogalService $service;

    private int $srid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DoogalService();
        $this->srid = (int) config('freegle.srid', 3857);
    }

    /** Write a Code-Point/Doogal style CSV (no header) and return its path. */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doogaltest');
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);

        return $path;
    }

    /** A single CSV row: Postcode, In Use?, Latitude, Longitude, ...padding. */
    private function row(string $pc, string $inUse, $lat, $lng): array
    {
        return [$pc, $inUse, $lat, $lng, '', '', '', '', '', '', '', '', 'England'];
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

        $csv = $this->writeCsv([$this->row('AB1 2CD', 'Yes', 55.10, -3.20)]);
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
        $csv = $this->writeCsv([$this->row('CB2 3EF', 'Yes', 52.25, 0.10)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['added']);

        $loc = DB::table('locations')->where('id', $id)->first();
        $this->assertEqualsWithDelta(52.25, (float) $loc->lat, 0.001);
    }

    public function test_skips_postcode_not_in_use(): void
    {
        $csv = $this->writeCsv([$this->row('ZZ9 9ZZ', 'No', 50.0, -1.0)]);
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
        $csv = $this->writeCsv([$this->row('DE1 4GH', 'Yes', 52.901, -1.473)]);
        $result = $this->service->process($csv);
        @unlink($csv);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['added']);
    }

    public function test_fetch_csv_downloads_and_extracts_zip(): void
    {
        // Build a small in-memory zip containing a CSV, and fake the HTTP fetch.
        $zipPath = tempnam(sys_get_temp_dir(), 'doogalzip');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('postcodes.csv', "Postcode,In Use?,Latitude,Longitude\nAB1 2CD,Yes,55.1,-3.2\n");
        $zip->close();
        $zipBytes = file_get_contents($zipPath);
        @unlink($zipPath);

        Http::fake(['*' => Http::response($zipBytes, 200)]);

        $csvPath = $this->service->fetchCsv('https://example.test/postcodes.zip');
        $this->assertFileExists($csvPath);
        $this->assertStringContainsString('AB1 2CD', file_get_contents($csvPath));

        @unlink($csvPath);
        @rmdir(dirname($csvPath));
    }
}
