<?php

namespace Tests\Feature\Paf;

use App\Services\PafService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PafService::applyLines, the batched apply paf:import-run uses. Lines are in the CSV PAF
 * layout AFD ship: 16 Royal Mail fields, then four of AFD's own that are ignored.
 */
class PafApplyLinesTest extends TestCase
{
    private PafService $service;

    private string $postcode;

    private int $postcodeId;

    private int $udprn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PafService();

        // Unique per test, so parallel workers and fixtures do not collide.
        $this->postcode = 'ZP' . random_int(10, 99) . ' ' . random_int(1, 9) . 'ZZ';
        $this->postcodeId = DB::table('locations')->insertGetId([
            'name' => $this->postcode,
            'type' => 'Postcode',
            'osm_place' => 0,
            'osm_amenity' => 0,
            'osm_shop' => 0,
        ]);
        $this->udprn = random_int(900000000, 999999999);
    }

    private function line(array $overrides = []): string
    {
        $fields = array_replace([
            0 => $this->postcode,
            1 => 'ABERDEEN',
            2 => '',
            3 => '',
            4 => 'Broad Street',
            5 => '',
            6 => ' ',
            7 => 'Marischal College',
            8 => '',
            9 => '',
            10 => 'Director Of Housing',
            11 => 'Aberdeen City Council',
            12 => (string) $this->udprn,
            13 => 'L',
            14 => ' ',
            15 => '1A',
            16 => '1901354',
            17 => '0',
            18 => '1',
            19 => '2',
        ], $overrides);

        // AFD's files have Windows line endings.
        return implode(',', $fields) . "\r\n";
    }

    private function stored(): array
    {
        $row = DB::table('paf_addresses')
            ->leftJoin('paf_posttown', 'paf_posttown.id', '=', 'paf_addresses.posttownid')
            ->leftJoin('paf_thoroughfaredescriptor', 'paf_thoroughfaredescriptor.id', '=', 'paf_addresses.thoroughfaredescriptorid')
            ->leftJoin('paf_buildingname', 'paf_buildingname.id', '=', 'paf_addresses.buildingnameid')
            ->leftJoin('paf_subbuildingname', 'paf_subbuildingname.id', '=', 'paf_addresses.subbuildingnameid')
            ->leftJoin('paf_departmentname', 'paf_departmentname.id', '=', 'paf_addresses.departmentnameid')
            ->leftJoin('paf_organisationname', 'paf_organisationname.id', '=', 'paf_addresses.organisationnameid')
            ->where('udprn', $this->udprn)
            ->first([
                'postcodeid', 'buildingnumber', 'postcodetype', 'suorganisationindicator', 'deliverypointsuffix',
                'posttown', 'thoroughfaredescriptor', 'buildingname', 'subbuildingname', 'departmentname',
                'organisationname',
            ]);

        return (array) $row;
    }

    public function test_inserts_a_new_address_with_each_field_in_its_place(): void
    {
        $result = $this->service->applyLines([$this->line()]);

        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => []], $result);
        $this->assertSame([
            'postcodeid' => $this->postcodeId,
            // A space means no building number, and no SU organisation indicator.
            'buildingnumber' => null,
            'postcodetype' => 'L',
            'suorganisationindicator' => null,
            'deliverypointsuffix' => '1A',
            'posttown' => 'ABERDEEN',
            'thoroughfaredescriptor' => 'Broad Street',
            'buildingname' => 'Marischal College',
            'subbuildingname' => null,
            'departmentname' => 'Director Of Housing',
            'organisationname' => 'Aberdeen City Council',
        ], $this->stored());
    }

    public function test_updates_a_changed_address_and_counts_an_unchanged_one_as_neither(): void
    {
        $this->service->applyLines([$this->line()]);

        $same = $this->service->applyLines([$this->line()]);
        $this->assertSame(0, $same['inserted']);
        $this->assertSame(0, $same['updated']);

        $changed = $this->service->applyLines([$this->line([6 => '12', 7 => '', 11 => 'Renamed Council'])]);
        $this->assertSame(0, $changed['inserted']);
        $this->assertSame(1, $changed['updated']);

        $stored = $this->stored();
        $this->assertSame(12, $stored['buildingnumber']);
        $this->assertNull($stored['buildingname']);
        $this->assertSame('Renamed Council', $stored['organisationname']);
        $this->assertSame(1, DB::table('paf_addresses')->where('udprn', $this->udprn)->count());
    }

    public function test_returns_lines_with_an_unknown_postcode_unapplied(): void
    {
        $unknown = $this->line([0 => 'ZZ99 9ZZ']);

        $result = $this->service->applyLines([$unknown, $this->line([12 => (string) ($this->udprn + 1)])]);

        $this->assertSame(1, $result['inserted']);
        $this->assertSame([rtrim($unknown, "\r\n")], $result['skipped']);
        $this->assertFalse(DB::table('paf_addresses')->where('udprn', $this->udprn)->exists());
    }

    public function test_ignores_lines_that_are_not_addresses(): void
    {
        $result = $this->service->applyLines(["\r\n", "not,an,address\r\n", $this->line([12 => 'abc'])]);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => []], $result);
    }

    public function test_reuses_lookup_rows_whatever_their_case(): void
    {
        $existing = DB::table('paf_posttown')->insertGetId(['posttown' => 'ZPTOWN' . $this->udprn]);

        $this->service->applyLines([$this->line([1 => 'zptown' . $this->udprn])]);

        $this->assertSame(
            $existing,
            DB::table('paf_addresses')->where('udprn', $this->udprn)->value('posttownid')
        );
    }

    public function test_applies_many_lines_sharing_lookup_values_in_one_batch(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = $this->line([6 => (string) ($i + 1), 7 => '', 12 => (string) ($this->udprn + $i)]);
        }

        $result = $this->service->applyLines($lines);

        $this->assertSame(50, $result['inserted']);
        $this->assertSame(
            1,
            DB::table('paf_addresses')
                ->whereBetween('udprn', [$this->udprn, $this->udprn + 49])
                ->distinct()
                ->count('thoroughfaredescriptorid')
        );
    }
}
