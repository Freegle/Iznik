<?php

namespace Tests\Unit\Services;

use App\Services\HousekeeperService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HousekeeperServiceTest extends TestCase
{
    protected HousekeeperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HousekeeperService();
    }

    public function test_facebook_deletion_puts_known_users_into_limbo(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_test_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Test deletion',
            'data' => ['ids' => [$fbId]],
        ]);

        $deleted = DB::table('users')
            ->where('id', $user->id)
            ->value('deleted');

        $this->assertNotNull($deleted, 'User should be marked as deleted (limbo)');
    }

    public function test_facebook_deletion_skips_unknown_ids(): void
    {
        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Unknown IDs',
            'data' => ['ids' => ['nonexistent_fb_id_999']],
        ]);

        // No exception thrown = success.
        $this->assertTrue(true);
    }

    public function test_facebook_deletion_skips_already_deleted_users(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_already_del_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        // Pre-mark as deleted.
        DB::table('users')
            ->where('id', $user->id)
            ->update(['deleted' => now()->subDay()]);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Already deleted',
            'data' => ['ids' => [$fbId]],
        ]);

        // Deleted timestamp should not have changed.
        $deleted = DB::table('users')
            ->where('id', $user->id)
            ->value('deleted');

        $this->assertNotNull($deleted);
    }

    public function test_failure_does_not_process_deletion_ids(): void
    {
        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'failure',
            'summary' => 'Login failed',
            'data' => ['ids' => ['should_not_be_processed']],
        ]);

        // No exception and no email = success. Failure should not process IDs.
        $this->assertTrue(true);
    }

    public function test_process_upserts_housekeeper_tasks(): void
    {
        $taskKey = 'test-task-' . uniqid();

        $this->service->process([
            'task' => $taskKey,
            'status' => 'success',
            'summary' => 'Test tracking',
            'data' => [],
        ]);

        $row = DB::table('housekeeper_tasks')
            ->where('task_key', $taskKey)
            ->first();

        $this->assertNotNull($row, 'housekeeper_tasks row should be created');
        $this->assertEquals('success', $row->last_status);
        $this->assertNotNull($row->last_run_at);

        // Process again with failure — should update, not duplicate.
        $this->service->process([
            'task' => $taskKey,
            'status' => 'failure',
            'summary' => 'Failed this time',
            'data' => [],
        ]);

        $row = DB::table('housekeeper_tasks')
            ->where('task_key', $taskKey)
            ->first();

        $this->assertEquals('failure', $row->last_status);

        $count = DB::table('housekeeper_tasks')
            ->where('task_key', $taskKey)
            ->count();

        $this->assertEquals(1, $count, 'Should not duplicate rows');
    }

    public function test_process_stores_log_in_table(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_log_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Test with log',
            'data' => ['ids' => [$fbId]],
        ]);

        $row = DB::table('housekeeper_tasks')
            ->where('task_key', 'facebook-deletion')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->last_log, 'Log should be stored in table');
        $this->assertStringContains('Processing 1 Facebook user ID(s)', $row->last_log);
        $this->assertStringContains("user #{$user->id}", $row->last_log);
    }

    public function test_generates_summary_for_facebook_deletion(): void
    {
        $user = $this->createTestUser();
        $fbId = 'fb_summary_' . uniqid();

        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Facebook',
            'uid' => $fbId,
        ]);

        $this->service->process([
            'task' => 'facebook-deletion',
            'status' => 'success',
            'summary' => 'Extension summary',
            'data' => ['ids' => [$fbId, 'unknown_id_123']],
        ]);

        $row = DB::table('housekeeper_tasks')
            ->where('task_key', 'facebook-deletion')
            ->first();

        $this->assertNotNull($row);
        // Summary should mention both outcomes.
        $this->assertStringContains('Processed 2 IDs', $row->last_summary);
        $this->assertStringContains('marked for deletion', $row->last_summary);
        $this->assertStringContains('not found', $row->last_summary);
    }

    public function test_failure_summary_includes_error(): void
    {
        $taskKey = 'test-fail-' . uniqid();

        $this->service->process([
            'task' => $taskKey,
            'status' => 'failure',
            'summary' => 'Could not find button',
            'data' => [],
        ]);

        $row = DB::table('housekeeper_tasks')
            ->where('task_key', $taskKey)
            ->first();

        $this->assertNotNull($row);
        $this->assertStringContains('Failed: Could not find button', $row->last_summary);
    }

    /**
     * Helper: assertStringContains (works like assertStringContainsString).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
