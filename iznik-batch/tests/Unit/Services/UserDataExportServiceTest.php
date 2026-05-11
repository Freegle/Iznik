<?php

namespace Tests\Unit\Services;

use App\Services\UserDataExportService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDataExportServiceTest extends TestCase
{
    protected UserDataExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserDataExportService();
    }

    // --- Basic processing ---

    public function test_marks_export_as_started_and_completed(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNotNull($export->started);
        $this->assertNotNull($export->completed);
        $this->assertNotNull($export->data);
    }

    public function test_skips_already_completed_exports(): void
    {
        $user = $this->createTestUser();

        DB::table('users_exports')->insert([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
            'started' => now(),
            'completed' => now(),
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(0, $count);
    }

    public function test_returns_count_of_processed_exports(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $this->createTestUserEmail($user1);
        $this->createTestUserEmail($user2);

        DB::table('users_exports')->insert([
            ['userid' => $user1->id, 'tag' => 'tag1', 'requested' => now()],
            ['userid' => $user2->id, 'tag' => 'tag2', 'requested' => now()],
        ]);

        $count = $this->service->processAll();
        $this->assertEquals(2, $count);
    }

    public function test_purges_data_from_old_completed_exports(): void
    {
        $user = $this->createTestUser();

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'old-tag',
            'requested' => now()->subDays(10),
            'started' => now()->subDays(10),
            'completed' => now()->subDays(8),
            'data' => 'old export data',
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNull($export->data);
    }

    public function test_does_not_purge_recent_completed_exports(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'recent-tag',
            'requested' => now()->subDays(3),
            'started' => now()->subDays(3),
            'completed' => now()->subDays(2),
            'data' => 'recent export data',
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNotNull($export->data);
    }

    // --- Export data content ---

    public function test_export_contains_basic_user_info(): void
    {
        $user = $this->createTestUser(['fullname' => 'Test Exporter']);
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('basic', $json);
        $this->assertEquals($user->id, $json['basic']['id']);
        $this->assertEquals('Test Exporter', $json['basic']['fullname']);
    }

    public function test_export_contains_emails(): void
    {
        $user = $this->createTestUser();
        $email = $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('emails', $json);
        $this->assertNotEmpty($json['emails']);
        $this->assertContains($email->email, array_column($json['emails'], 'email'));
    }

    public function test_export_contains_memberships(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $this->service->processAll();

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $json = json_decode(gzinflate($export->data), true);

        $this->assertArrayHasKey('memberships', $json);
        $this->assertNotEmpty($json['memberships']);
        $this->assertEquals($group->id, $json['memberships'][0]['groupid']);
    }

    // --- Dry-run mode ---

    public function test_dry_run_does_not_write_export_data(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $exportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'test-tag',
            'requested' => now(),
        ]);

        $count = $this->service->processAll(dryRun: true);

        $this->assertEquals(1, $count);

        $export = DB::table('users_exports')->where('id', $exportId)->first();
        $this->assertNull($export->started);
        $this->assertNull($export->completed);
        $this->assertNull($export->data);
    }

    public function test_dry_run_does_not_purge_old_exports(): void
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);

        $oldExportId = DB::table('users_exports')->insertGetId([
            'userid' => $user->id,
            'tag' => 'old-tag',
            'requested' => now()->subDays(10),
            'completed' => now()->subDays(10),
            'data' => 'old-data',
        ]);

        $this->service->processAll(dryRun: true);

        $export = DB::table('users_exports')->where('id', $oldExportId)->first();
        $this->assertEquals('old-data', $export->data);
    }
}
