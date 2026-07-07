<?php

namespace Tests\Unit\Services;

use App\Models\Membership;
use App\Services\GroupMaintenanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GroupMaintenanceServiceTest extends TestCase
{
    protected GroupMaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GroupMaintenanceService();
    }

    public function test_updates_member_count(): void
    {
        $group = $this->createTestGroup();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);
        $this->createMembership($user3, $group);

        // Set stale counts.
        DB::table('groups')->where('id', $group->id)->update([
            'membercount' => 0,
            'modcount' => 0,
        ]);

        $stats = $this->service->updateMemberCounts();

        $this->assertGreaterThanOrEqual(1, $stats['groups_updated']);

        $group->refresh();
        $this->assertEquals(3, $group->membercount);
        $this->assertEquals(0, $group->modcount);
    }

    public function test_updates_mod_count(): void
    {
        $group = $this->createTestGroup();
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $this->createMembership($user1, $group, ['role' => Membership::ROLE_OWNER]);
        $this->createMembership($user2, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($user3, $group, ['role' => Membership::ROLE_MEMBER]);

        $stats = $this->service->updateMemberCounts();

        $group->refresh();
        $this->assertEquals(3, $group->membercount);
        $this->assertEquals(2, $group->modcount);
    }

    public function test_handles_group_with_no_members(): void
    {
        $group = $this->createTestGroup();

        DB::table('groups')->where('id', $group->id)->update([
            'membercount' => 99,
            'modcount' => 5,
        ]);

        $stats = $this->service->updateMemberCounts();

        $group->refresh();
        $this->assertEquals(0, $group->membercount);
        $this->assertEquals(0, $group->modcount);
    }

    // --- Skewed locations tests ---

    public function test_fix_skewed_locations_swaps_coordinates(): void
    {
        Mail::fake();

        // Create a location with swapped lat/lng (lat should be ~50-60, lng ~-8 to 2 for UK).
        // Skewed means lat < lng, so lat=1.5, lng=51.5 is wrong.
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'TestSkewed_' . uniqid(),
            'type' => 'Postcode',
            'lat' => 1.5,
            'lng' => 51.5,
        ]);

        $stats = $this->service->fixSkewedLocations();

        $this->assertGreaterThanOrEqual(1, $stats['locations_fixed']);

        $location = DB::table('locations')->where('id', $locationId)->first();
        $this->assertEquals(51.5, $location->lat);
        $this->assertEquals(1.5, $location->lng);
    }

    public function test_fix_skewed_locations_fixes_messages(): void
    {
        Mail::fake();

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $locationId = DB::table('locations')->insertGetId([
            'name' => 'TestSkewed_' . uniqid(),
            'type' => 'Postcode',
            'lat' => 1.5,
            'lng' => 51.5,
        ]);

        $message = $this->createTestMessage($user, $group);
        DB::table('messages')->where('id', $message->id)->update([
            'locationid' => $locationId,
            'lat' => 1.5,
            'lng' => 51.5,
        ]);

        $stats = $this->service->fixSkewedLocations();

        $this->assertGreaterThanOrEqual(1, $stats['messages_fixed']);

        $msg = DB::table('messages')->where('id', $message->id)->first();
        $this->assertEquals(51.5, $msg->lat);
        $this->assertEquals(1.5, $msg->lng);
    }

    public function test_fix_skewed_locations_skips_bf_postcodes(): void
    {
        Mail::fake();

        $locationId = DB::table('locations')->insertGetId([
            'name' => 'BF12345',
            'type' => 'Postcode',
            'lat' => 1.5,
            'lng' => 51.5,
        ]);

        $stats = $this->service->fixSkewedLocations();

        // BF postcodes should be skipped.
        $location = DB::table('locations')->where('id', $locationId)->first();
        $this->assertEquals(1.5, $location->lat);
        $this->assertEquals(51.5, $location->lng);
    }

    public function test_fix_skewed_locations_no_issues(): void
    {
        Mail::fake();

        $stats = $this->service->fixSkewedLocations();

        $this->assertEquals(0, $stats['locations_fixed']);
        $this->assertEquals(0, $stats['messages_fixed']);
        Mail::assertNothingSent();
    }
}
