<?php

namespace Tests\Unit\Services;

use App\Services\UserLocationRemapService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserLocationRemapServiceTest extends TestCase
{
    protected UserLocationRemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserLocationRemapService();
    }

    public function test_updates_location_name_when_changed(): void
    {
        $location = DB::table('locations')->insertGetId([
            'name' => 'New Location Name',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $user = $this->createTestUser();

        // Store old name in settings.
        $settings = json_encode([
            'mylocation' => [
                'id' => $location,
                'name' => 'Old Location Name',
                'lat' => 51.5,
                'lng' => -0.1,
            ],
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'settings' => $settings,
            'lastaccess' => now()->subHours(12),
        ]);

        $result = $this->service->remapLocations();

        $updated = DB::table('users')->where('id', $user->id)->value('settings');
        $decoded = json_decode($updated, true);
        $this->assertEquals('New Location Name', $decoded['mylocation']['name']);
        $this->assertGreaterThanOrEqual(1, $result['changed']);
    }

    public function test_skips_user_when_name_unchanged(): void
    {
        $location = DB::table('locations')->insertGetId([
            'name' => 'Same Name',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $user = $this->createTestUser();

        $settings = json_encode([
            'mylocation' => [
                'id' => $location,
                'name' => 'Same Name',
            ],
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'settings' => $settings,
            'lastaccess' => now()->subHours(12),
        ]);

        $result = $this->service->remapLocations();

        $this->assertEquals(0, $result['changed']);
    }

    public function test_skips_user_inactive_more_than_48h(): void
    {
        $location = DB::table('locations')->insertGetId([
            'name' => 'New Name',
            'type' => 'Polygon',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $user = $this->createTestUser();

        $settings = json_encode([
            'mylocation' => ['id' => $location, 'name' => 'Old Name'],
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'settings' => $settings,
            'lastaccess' => now()->subDays(3),
        ]);

        $result = $this->service->remapLocations();

        $this->assertEquals(0, $result['changed']);
    }

    public function test_skips_user_with_no_settings(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'settings' => null,
            'lastaccess' => now()->subHours(12),
        ]);

        $result = $this->service->remapLocations();

        $this->assertEquals(0, $result['changed']);
    }
}
