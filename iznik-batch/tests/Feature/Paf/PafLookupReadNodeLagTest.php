<?php

namespace Tests\Feature\Paf;

use App\Services\PafService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LagsTheReadNode;
use Tests\TestCase;

/**
 * PafService::applyLines when the read node is behind the write node, as db2 is under the load of
 * a PAF import. A lookup value already on the write node, or added a moment ago, is not on the read
 * node yet. Reading lookups from the read node then inserted the value a second time, and the
 * import failed on live (20 October 2026) with "Duplicate entry 'Education Department' for key
 * 'paf_departmentname.departmentname'".
 */
class PafLookupReadNodeLagTest extends TestCase
{
    use LagsTheReadNode;

    /** The paf_* lookup tables applyLines fills, plus what it reads the postcode and address from. */
    private const TABLES = [
        'locations', 'paf_addresses', 'paf_posttown', 'paf_dependentlocality', 'paf_doubledependentlocality',
        'paf_thoroughfaredescriptor', 'paf_dependentthoroughfaredescriptor', 'paf_buildingname',
        'paf_subbuildingname', 'paf_pobox', 'paf_departmentname', 'paf_organisationname',
    ];

    public function test_a_lookup_value_the_read_node_has_not_seen_is_not_inserted_twice(): void
    {
        $suffix = (string) random_int(100000, 999999);
        $postcode = 'ZL' . random_int(10, 99) . ' ' . random_int(1, 9) . 'ZZ';
        $udprn = random_int(900000000, 999999999);
        $department = "Education Department {$suffix}";

        $this->withLaggingReadNode(self::TABLES, function (string $lag) use ($suffix, $postcode, $udprn, $department) {
            $db = DB::connection($lag);
            $locationId = $db->table('locations')->insertGetId([
                'name' => $postcode, 'type' => 'Postcode', 'osm_place' => 0, 'osm_amenity' => 0, 'osm_shop' => 0,
            ]);
            // The read node has the postcode, so the line is applied, but no lookup values at all.
            $db->getReadPdo()->prepare('INSERT INTO locations (id, name, type, osm_place, osm_amenity, osm_shop) VALUES (?, ?, ?, 0, 0, 0)')
                ->execute([$locationId, $postcode, 'Postcode']);
            // The write node already has this department, from an earlier batch.
            $existing = $db->table('paf_departmentname')->insertGetId(['departmentname' => $department]);

            $line = implode(',', [
                $postcode, "ZLTOWN{$suffix}", '', '', "Lag Street {$suffix}", '', '1', '', '', '',
                $department, "Lag Council {$suffix}", (string) $udprn, 'L', ' ', '1A', '1', '0', '1', '2',
            ]) . "\r\n";

            DB::setDefaultConnection($lag);
            try {
                $result = (new PafService())->applyLines([$line]);
                $address = $db->table('paf_addresses')->useWritePdo()->where('udprn', $udprn)->first();
            } finally {
                DB::setDefaultConnection('mysql');
                $db->table('paf_addresses')->where('udprn', $udprn)->delete();
                $db->table('paf_departmentname')->where('departmentname', $department)->delete();
                $db->table('paf_organisationname')->where('organisationname', "Lag Council {$suffix}")->delete();
                $db->table('paf_posttown')->where('posttown', "ZLTOWN{$suffix}")->delete();
                $db->table('paf_thoroughfaredescriptor')->where('thoroughfaredescriptor', "Lag Street {$suffix}")->delete();
                $db->table('locations')->where('id', $locationId)->delete();
            }

            $this->assertSame(1, $result['inserted']);
            $this->assertSame($existing, (int) $address->departmentnameid);
        });
    }
}
