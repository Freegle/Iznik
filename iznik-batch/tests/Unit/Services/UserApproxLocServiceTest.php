<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\User;
use App\Services\UserApproxLocService;
use App\Support\GreatCircle;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Port of V1 Nearby::updateLocations() (iznik-server/include/user/Nearby.php, deleted in
 * c14a7125b). users_approxlocs is the privacy-blurred point cloud that drives the rippling
 * reach query, so these tests pin the two things its readers depend on: which members get a
 * row, and that the row's geometry is SRID 3857 in POINT(lng lat) order.
 */
class UserApproxLocServiceTest extends TestCase
{
    protected UserApproxLocService $service;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserApproxLocService();
        $this->group = $this->createTestGroup();
    }

    /**
     * An active member: has a membership and a lastaccess inside the cutoff. Returns the user.
     */
    private function activeMember(array $attributes = []): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $this->group);
        DB::table('users')->where('id', $user->id)->update(array_merge([
            'lastaccess' => now()->subDay(),
        ], $attributes));

        return $user->fresh();
    }

    private function makeLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'TestLoc '.uniqid('', true),
            'type' => 'Postcode',
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    private function setMyLocation(User $user, array $myLocation): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['mylocation' => $myLocation]),
        ]);
    }

    private function row(User $user): ?object
    {
        return DB::table('users_approxlocs')->where('userid', $user->id)->first();
    }

    // --- Which members get a row ---

    public function test_writes_a_blurred_row_for_an_active_member_from_mylocation(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['id' => 1, 'name' => 'SW1A 1AA', 'lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $row = $this->row($user);
        $this->assertNotNull($row, 'active member with a resolvable location should get a row');

        // Blurred, so not the raw point - but within a few hundred metres of it.
        $this->assertNotEquals(51.5010, (float) $row->lat);
        $this->assertLessThan(0.01, abs((float) $row->lat - 51.5010));
    }

    public function test_falls_back_to_lastlocation_when_there_is_no_mylocation(): void
    {
        $user = $this->activeMember();
        $locationId = $this->makeLocation(53.4084, -2.9916);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);

        $this->service->updateLocations();

        $row = $this->row($user);
        $this->assertNotNull($row);
        [$expectedLat, $expectedLng] = $this->blurred(53.4084, -2.9916);
        $this->assertEquals($expectedLat, (float) $row->lat);
        $this->assertEquals($expectedLng, (float) $row->lng);
    }

    public function test_mylocation_wins_over_lastlocation(): void
    {
        $user = $this->activeMember();
        $locationId = $this->makeLocation(53.4084, -2.9916);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
        $this->setMyLocation($user, ['id' => 2, 'name' => 'Elsewhere', 'lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        [$expectedLat, $expectedLng] = $this->blurred(51.5010, -0.1416);
        $row = $this->row($user);
        $this->assertEquals($expectedLat, (float) $row->lat);
        $this->assertEquals($expectedLng, (float) $row->lng);
    }

    public function test_mylocation_with_only_one_coordinate_falls_through_to_lastlocation(): void
    {
        $user = $this->activeMember();
        $locationId = $this->makeLocation(53.4084, -2.9916);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
        $this->setMyLocation($user, ['id' => 3, 'name' => 'Half a point', 'lat' => 51.5010, 'lng' => null]);

        $this->service->updateLocations();

        [$expectedLat, $expectedLng] = $this->blurred(53.4084, -2.9916);
        $row = $this->row($user);
        $this->assertNotNull($row);
        $this->assertEquals($expectedLat, (float) $row->lat);
        $this->assertEquals($expectedLng, (float) $row->lng);
    }

    public function test_skips_a_member_with_no_resolvable_location(): void
    {
        $user = $this->activeMember();

        $this->service->updateLocations();

        $this->assertNull($this->row($user));
    }

    /**
     * 1,629 locations on live sit at 0,0 — that is missing data, not a place in the Atlantic.
     * V1 guarded against it (`if ($lat || $lng)`) and so must this.
     */
    public function test_skips_a_member_whose_location_is_zero_zero(): void
    {
        $user = $this->activeMember();
        $locationId = $this->makeLocation(0, 0);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);

        $this->service->updateLocations();

        $this->assertNull($this->row($user));
    }

    /**
     * The guard is "both coordinates falsy", not "either coordinate is zero": the Greenwich
     * meridian runs through east London, so lng exactly 0 is a real place.
     */
    public function test_writes_a_member_on_the_greenwich_meridian(): void
    {
        $user = $this->activeMember();
        $locationId = $this->makeLocation(51.4934, 0);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);

        $this->service->updateLocations();

        $this->assertNotNull($this->row($user), 'lng 0 with a real lat is Greenwich, not missing data');
    }

    public function test_skips_a_member_whose_mylocation_is_zero_zero(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 0, 'lng' => 0]);

        $this->service->updateLocations();

        $this->assertNull($this->row($user));
    }

    public function test_skips_a_user_with_no_membership(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subDay(),
            'settings' => json_encode(['mylocation' => ['lat' => 51.5010, 'lng' => -0.1416]]),
        ]);

        $this->service->updateLocations();

        $this->assertNull($this->row($user));
    }

    public function test_skips_a_member_whose_lastaccess_predates_the_cutoff(): void
    {
        $user = $this->activeMember(['lastaccess' => now()->subDays(200)]);
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $this->assertNull($this->row($user));
    }

    // --- Row contents ---

    public function test_timestamp_is_the_users_lastaccess_not_now(): void
    {
        $lastaccess = now()->subDays(30)->startOfSecond();
        $user = $this->activeMember(['lastaccess' => $lastaccess]);
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $this->assertEquals(
            $lastaccess->format('Y-m-d H:i:s'),
            $this->row($user)->timestamp,
            'timestamp carries lastaccess - the prune and the readers key off it'
        );
    }

    public function test_position_is_srid_3857_in_lng_lat_order(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $geom = DB::table('users_approxlocs')
            ->where('userid', $user->id)
            ->selectRaw('ST_SRID(position) AS srid, ST_X(position) AS x, ST_Y(position) AS y, lat, lng')
            ->first();

        $this->assertEquals(3857, (int) $geom->srid);
        $this->assertEquals((float) $geom->lng, round((float) $geom->x, 4), 'ST_X must be lng');
        $this->assertEquals((float) $geom->lat, round((float) $geom->y, 4), 'ST_Y must be lat');
    }

    public function test_rerunning_updates_the_existing_row_rather_than_duplicating(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);
        $this->service->updateLocations();

        // Member moves.
        $this->setMyLocation($user, ['lat' => 53.4084, 'lng' => -2.9916]);
        $this->service->updateLocations();

        $this->assertEquals(1, DB::table('users_approxlocs')->where('userid', $user->id)->count());
        [$expectedLat] = $this->blurred(53.4084, -2.9916);
        $this->assertEquals($expectedLat, (float) $this->row($user)->lat);
    }

    // --- Blur parity with V1 Utils::blur ---

    public function test_blur_offsets_the_point_by_about_400_metres(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $row = $this->row($user);
        $metres = $this->distanceMetres(51.5010, -0.1416, (float) $row->lat, (float) $row->lng);
        $this->assertGreaterThan(350, $metres);
        $this->assertLessThan(450, $metres);
    }

    public function test_blur_is_deterministic_so_the_point_does_not_jitter(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();
        $first = $this->row($user);
        $this->service->updateLocations();
        $second = $this->row($user);

        $this->assertEquals($first->lat, $second->lat);
        $this->assertEquals($first->lng, $second->lng);
    }

    public function test_blur_rounds_to_four_decimal_places(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $this->service->updateLocations();

        $row = $this->row($user);
        $this->assertEquals(round((float) $row->lat, 4), (float) $row->lat);
        $this->assertEquals(round((float) $row->lng, 4), (float) $row->lng);
    }

    // --- Prune ---

    public function test_prunes_rows_whose_timestamp_predates_the_cutoff(): void
    {
        $stale = $this->activeMember();
        DB::table('users_approxlocs')->insert([
            'userid' => $stale->id,
            'lat' => 51.5,
            'lng' => -0.1,
            'position' => DB::raw("ST_SRID(POINT(-0.1, 51.5), 3857)"),
            'timestamp' => now()->subDays(250),
        ]);

        $stats = $this->service->updateLocations();

        $this->assertNull($this->row($stale), 'row older than the inactivity cutoff should be pruned');
        $this->assertGreaterThanOrEqual(1, $stats['pruned']);
    }

    public function test_keeps_rows_inside_the_cutoff(): void
    {
        $fresh = $this->activeMember();
        DB::table('users_approxlocs')->insert([
            'userid' => $fresh->id,
            'lat' => 51.5,
            'lng' => -0.1,
            'position' => DB::raw("ST_SRID(POINT(-0.1, 51.5), 3857)"),
            'timestamp' => now()->subDays(30),
        ]);

        $this->service->updateLocations();

        $this->assertNotNull($this->row($fresh));
    }

    // --- Dry run ---

    public function test_dry_run_writes_nothing(): void
    {
        $user = $this->activeMember();
        $this->setMyLocation($user, ['lat' => 51.5010, 'lng' => -0.1416]);

        $stats = $this->service->updateLocations(true);

        $this->assertNull($this->row($user));
        // Not an exact count: the suite shares a database, so earlier tests may have left their
        // own active members behind.
        $this->assertGreaterThanOrEqual(1, $stats['upserted'], 'dry run still reports what it would have written');
    }

    public function test_dry_run_does_not_prune(): void
    {
        $stale = $this->activeMember();
        DB::table('users_approxlocs')->insert([
            'userid' => $stale->id,
            'lat' => 51.5,
            'lng' => -0.1,
            'position' => DB::raw("ST_SRID(POINT(-0.1, 51.5), 3857)"),
            'timestamp' => now()->subDays(250),
        ]);

        $this->service->updateLocations(true);

        $this->assertNotNull($this->row($stale));
    }

    // --- Helpers ---

    /**
     * The expected blurred point, derived the V1 way: deterministic direction from the raw
     * coordinates, 400 m along a great circle, rounded to 4 dp.
     *
     * @return array{0:float,1:float}
     */
    private function blurred(float $lat, float $lng): array
    {
        $dir = ($lat * 1000 + $lng * 1000) % 360;
        $pos = GreatCircle::getPositionByDistance(UserApproxLocService::BLUR_USER, $dir, $lat, $lng);

        return [round($pos['lat'], 4), round($pos['lng'], 4)];
    }

    private function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
