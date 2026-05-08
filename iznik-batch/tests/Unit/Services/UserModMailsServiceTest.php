<?php

namespace Tests\Unit\Services;

use App\Services\UserModMailsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserModMailsServiceTest extends TestCase
{
    private UserModMailsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserModMailsService;
    }

    // ===================================================================
    // updateModMails
    // ===================================================================

    private function insertLog(array $attrs): int
    {
        return DB::table('logs')->insertGetId(array_merge([
            'timestamp' => now()->subMinutes(5),
            'type' => 'Message',
            'subtype' => 'Rejected',
            'byuser' => null,
            'user' => null,
            'groupid' => null,
        ], $attrs));
    }

    public function test_update_records_rejected_message_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Rejected',
            'byuser' => $mod->id,
            'user' => $user->id,
            'groupid' => $group->id,
        ]);

        $this->service->updateModMails();

        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_records_deleted_message_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Deleted',
            'byuser' => $mod->id,
            'user' => $user->id,
            'groupid' => $group->id,
        ]);

        $this->service->updateModMails();

        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_records_replied_message_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();

        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Replied',
            'byuser' => $mod->id,
            'user' => $user->id,
        ]);

        $this->service->updateModMails();

        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_records_user_mailed_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();

        $this->insertLog([
            'type' => 'User',
            'subtype' => 'Mailed',
            'byuser' => $mod->id,
            'user' => $user->id,
        ]);

        $this->service->updateModMails();

        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_skips_log_where_byuser_equals_user(): void
    {
        $user = $this->createTestUser();

        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Rejected',
            'byuser' => $user->id,
            'user' => $user->id,
        ]);

        $this->service->updateModMails();

        // User acted on themselves — should not appear in users_modmails
        $this->assertEquals(0, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_skips_old_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();

        // Log is 15 minutes old — outside the 10-minute scan window
        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Rejected',
            'byuser' => $mod->id,
            'user' => $user->id,
            'timestamp' => now()->subMinutes(15),
        ]);

        $this->service->updateModMails();

        $this->assertEquals(0, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_skips_irrelevant_log_subtype(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();

        $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Approved',  // not a mod-action subtype
            'byuser' => $mod->id,
            'user' => $user->id,
        ]);

        $this->service->updateModMails();

        $this->assertEquals(0, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_update_does_not_insert_duplicate_log(): void
    {
        $mod = $this->createTestUser();
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $logId = $this->insertLog([
            'type' => 'Message',
            'subtype' => 'Rejected',
            'byuser' => $mod->id,
            'user' => $user->id,
            'groupid' => $group->id,
        ]);

        // Pre-insert so second call is a duplicate
        DB::table('users_modmails')->insert([
            'userid' => $user->id,
            'logid' => $logId,
            'timestamp' => now()->subMinutes(5),
            'groupid' => $group->id,
        ]);

        $this->service->updateModMails();

        // Still only one entry (INSERT IGNORE de-duplicates by logid)
        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    // ===================================================================
    // pruneOldEntries
    // ===================================================================

    public function test_prune_deletes_old_entries(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $logId = $this->insertLog(['user' => $user->id, 'groupid' => $group->id]);

        DB::table('users_modmails')->insert([
            'userid' => $user->id,
            'logid' => $logId,
            'timestamp' => now()->subDays(31),
            'groupid' => $group->id,
        ]);

        $deleted = $this->service->pruneOldEntries();

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertEquals(0, DB::table('users_modmails')->where('userid', $user->id)->count());
    }

    public function test_prune_keeps_recent_entries(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $logId = $this->insertLog(['user' => $user->id, 'groupid' => $group->id]);

        DB::table('users_modmails')->insert([
            'userid' => $user->id,
            'logid' => $logId,
            'timestamp' => now()->subDays(5),
            'groupid' => $group->id,
        ]);

        $this->service->pruneOldEntries();

        $this->assertEquals(1, DB::table('users_modmails')->where('userid', $user->id)->count());
    }
}
