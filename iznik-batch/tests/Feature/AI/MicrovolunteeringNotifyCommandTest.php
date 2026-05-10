<?php

namespace Tests\Feature\AI;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MicrovolunteeringNotifyCommandTest extends TestCase
{
    private function createGroup(bool $microvolunteering = true): int
    {
        return DB::table('groups')->insertGetId([
            'nameshort'         => 'TestGroup' . uniqid(),
            'namefull'          => 'Test Group',
            'type'              => 'Freegle',
            'publish'           => 1,
            'onhere'            => 1,
            'microvolunteering' => $microvolunteering ? 1 : 0,
            'polyindex'         => DB::raw("ST_GeomFromText('POINT(-0.1 51.5)', 3857)"),
        ]);
    }

    private function createUser(string $trustlevel = 'Basic'): int
    {
        $userId = DB::table('users')->insertGetId([
            'fullname'   => 'Test User ' . uniqid(),
            'trustlevel' => $trustlevel,
            'lastaccess' => now(),
            'added'      => now(),
        ]);

        $email = 'test-' . uniqid() . '@example.com';
        DB::table('users_emails')->insert([
            'userid'    => $userId,
            'email'     => $email,
            'backwards' => strrev($email),
            'preferred' => 1,
            'added'     => now(),
        ]);

        return $userId;
    }

    private function addMembership(int $userId, int $groupId, string $role = 'Member'): void
    {
        DB::table('memberships')->insert([
            'userid'     => $userId,
            'groupid'    => $groupId,
            'role'       => $role,
            'collection' => 'Approved',
            'added'      => now(),
        ]);
    }

    private function createMessage(int $groupId, int $fromuser, string $collection = 'Approved'): int
    {
        $msgId = DB::table('messages')->insertGetId([
            'subject'  => 'OFFER: Test item (Test Area)',
            'message'  => 'Test item description.',
            'type'     => 'Offer',
            'fromuser' => $fromuser,
            'deleted'  => null,
            'heldby'   => null,
        ]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgId,
            'groupid'    => $groupId,
            'collection' => $collection,
            'arrival'    => now(),
        ]);

        return $msgId;
    }

    public function test_smoke_no_messages(): void
    {
        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);
    }

    public function test_notifies_moderate_user_for_pending_message(): void
    {
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_does_not_notify_basic_user_for_pending_message(): void
    {
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Basic');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_notifies_basic_member_for_approved_message(): void
    {
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Moderate');
        $reviewer = $this->createUser('Basic');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId, 'Member');

        $msgId = $this->createMessage($groupId, $fromUser, 'Approved');

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_skips_group_without_microvolunteering(): void
    {
        $groupId  = $this->createGroup(microvolunteering: false);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'type' => 'Exhort',
            'url'  => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_does_not_notify_message_author(): void
    {
        $groupId = $this->createGroup(microvolunteering: true);
        $author  = $this->createUser('Advanced');

        $this->addMembership($author, $groupId);

        $msgId = $this->createMessage($groupId, $author, 'Pending');

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $author,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_dry_run_does_not_insert(): void
    {
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $this->artisan('microvolunteering:notify', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_skips_user_already_notified_3_times(): void
    {
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        // Pre-insert 3 notifications for this user
        for ($i = 0; $i < 3; $i++) {
            DB::table('users_notifications')->insert([
                'touser' => $reviewer,
                'type'   => 'Exhort',
                'url'    => '/microvolunteering/message/' . $msgId,
            ]);
        }

        $before = DB::table('users_notifications')
            ->where('touser', $reviewer)
            ->where('type', 'Exhort')
            ->count();

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $after = DB::table('users_notifications')
            ->where('touser', $reviewer)
            ->where('type', 'Exhort')
            ->count();

        $this->assertSame($before, $after);
    }
}
