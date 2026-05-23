<?php

namespace Tests\Feature\Paf;

use App\Services\PafService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PafCommandsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/paf_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        array_map('unlink', glob("{$this->tmpDir}/*"));
        @rmdir($this->tmpDir);
    }

    public function test_load_fails_if_input_not_provided(): void
    {
        $this->artisan('paf:load')
            ->assertExitCode(1);
    }

    public function test_load_fails_if_input_file_not_found(): void
    {
        $this->artisan('paf:load --input=/nonexistent/file.csv')
            ->assertExitCode(1);
    }

    public function test_update_fails_if_input_not_provided(): void
    {
        $this->artisan('paf:update')
            ->assertExitCode(1);
    }

    public function test_update_fails_if_input_file_not_found(): void
    {
        $this->artisan('paf:update --input=/nonexistent/file.csv')
            ->assertExitCode(1);
    }

    public function test_preprocess_fails_if_input_not_provided(): void
    {
        $this->artisan('paf:preprocess-csv')
            ->assertExitCode(1);
    }

    public function test_preprocess_fails_if_output_not_provided(): void
    {
        $input = "{$this->tmpDir}/input.csv";
        file_put_contents($input, "SW1A 1AA,,some,data\n");

        $this->artisan("paf:preprocess-csv --input={$input}")
            ->assertExitCode(1);
    }

    public function test_preprocess_replaces_empty_fields_with_null_sentinel(): void
    {
        $input = "{$this->tmpDir}/input.csv";
        $output = "{$this->tmpDir}/output.csv";

        file_put_contents($input, "SW1A 1AA,,LONDON,,,,123,,,,,,12345678,S,Y,2A\n");

        $service = new PafService();
        $service->preprocessCsv($input, $output);

        $result = file_get_contents($output);
        $this->assertStringContainsString('\N', $result);
    }

    public function test_load_processes_file_with_unknown_postcodes(): void
    {
        $input = "{$this->tmpDir}/input.csv";
        // A line with an unknown postcode — command should succeed but skip it
        file_put_contents($input, "ZZ99 9ZZ,TESTTOWN,,,TESTRD,,123,,,,,,99999999,S,Y,2A\n");

        $this->artisan("paf:load --input={$input}")
            ->assertExitCode(0);
    }

    public function test_load_inserts_record_when_postcode_known(): void
    {
        // Insert a known postcode into locations
        $postcode = 'SW1A 1AA';
        $postcodeId = DB::table('locations')->insertGetId([
            'name' => $postcode,
            'type' => 'Postcode',
            'osm_place' => 0,
            'osm_amenity' => 0,
            'osm_shop' => 0,
        ]);

        $input = "{$this->tmpDir}/input.csv";
        // postcode, posttown, dep_locality, double_dep_locality, thoroughfare, dep_thoroughfare,
        // building_number, building_name, sub_building_name, po_box, dept_name, org_name,
        // udprn, postcode_type, su_org_indicator, delivery_point_suffix
        file_put_contents($input, "{$postcode},LONDON,,,TESTROAD,,42,,,,,,99999991,S,N,3B\n");

        $this->artisan("paf:load --input={$input}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('paf_addresses', [
            'postcodeid' => $postcodeId,
            'udprn' => 99999991,
            'buildingnumber' => 42,
        ]);
    }
}
