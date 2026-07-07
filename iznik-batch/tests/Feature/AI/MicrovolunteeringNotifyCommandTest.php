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

    public function test_does_not_renotify_user_who_already_reviewed_message(): void
    {
        // Root cause of Discourse 9856: the notify cron re-notifies a user about a
        // message they have already reviewed (it never consults microactions), so a
        // rippling post whose messages_groups.arrival keeps refreshing keeps
        // re-lighting the "post to check" badge for the same person for ever.
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        // The reviewer has already checked this message.
        DB::table('microactions')->insert([
            'actiontype'     => 'CheckMessage',
            'userid'         => $reviewer,
            'msgid'          => $msgId,
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_still_notifies_a_different_user_who_has_not_reviewed(): void
    {
        // The microactions exclusion must be per-user: one reviewer having checked
        // the message must not suppress notifications to a different eligible reviewer.
        $groupId    = $this->createGroup(microvolunteering: true);
        $fromUser   = $this->createUser('Basic');
        $reviewed   = $this->createUser('Moderate');
        $unreviewed = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewed, $groupId);
        $this->addMembership($unreviewed, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        DB::table('microactions')->insert([
            'actiontype'     => 'CheckMessage',
            'userid'         => $reviewed,
            'msgid'          => $msgId,
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $reviewed,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
        $this->assertDatabaseHas('users_notifications', [
            'touser' => $unreviewed,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    public function test_stale_notification_for_already_reviewed_message_is_cleared(): void
    {
        // Second surface of Discourse 9856: pickCandidates only stops NEW duplicate
        // notifications from being created. It does nothing for an Exhort
        // notification that already exists (e.g. inserted before this exclusion
        // existed, or via any other race) - that row stays seen=0 forever, so the
        // badge never clears and its link keeps re-presenting the reviewed post.
        // Confirmed against production: 81 such stuck rows currently exist.
        $groupId  = $this->createGroup(microvolunteering: true);
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        // The reviewer has already checked this message...
        DB::table('microactions')->insert([
            'actiontype'     => 'CheckMessage',
            'userid'         => $reviewer,
            'msgid'          => $msgId,
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        // ...but a stale unseen notification for it is still sitting there.
        $notifId = DB::table('users_notifications')->insertGetId([
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);

        $this->artisan('microvolunteering:notify')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_notifications', [
            'id'   => $notifId,
            'seen' => 1,
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
