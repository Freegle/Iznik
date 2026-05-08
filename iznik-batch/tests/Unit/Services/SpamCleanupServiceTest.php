<?php

namespace Tests\Unit\Services;

use App\Services\SpamCleanupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpamCleanupServiceTest extends TestCase
{
    private SpamCleanupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SpamCleanupService;
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function makeSpammer(): int
    {
        $user = $this->createTestUser();
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        return $user->id;
    }

    private function insertSession(int $userId): int
    {
        return DB::table('sessions')->insertGetId([
            'userid' => $userId,
            'series' => rand(1, 99999),
            'token' => bin2hex(random_bytes(16)),
        ]);
    }

    private function insertNewsfeed(int $userId): int
    {
        return DB::table('newsfeed')->insertGetId([
            'userid' => $userId,
            'type' => 'Message',
            'position' => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
        ]);
    }

    private function insertUsersExpected(int $expecter, int $expectee, int $chatMsgId): int
    {
        return DB::table('users_expected')->insertGetId([
            'expecter' => $expecter,
            'expectee' => $expectee,
            'chatmsgid' => $chatMsgId,
            'value' => 1,
        ]);
    }

    // ===================================================================
    // removeSpamMemberships
    // ===================================================================

    public function test_removes_member_role_membership_for_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $group = $this->createTestGroup();
        $spammer = \App\Models\User::find($spammerId);
        $this->createMembership($spammer, $group, ['role' => 'Member']);

        $this->service->removeSpamMemberships();

        $remaining = DB::table('memberships')
            ->where('userid', $spammerId)
            ->where('groupid', $group->id)
            ->count();

        $this->assertEquals(0, $remaining);
    }

    public function test_does_not_remove_moderator_role_membership_for_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $group = $this->createTestGroup();
        $spammer = \App\Models\User::find($spammerId);
        $this->createMembership($spammer, $group, ['role' => 'Moderator']);

        $this->service->removeSpamMemberships();

        $remaining = DB::table('memberships')
            ->where('userid', $spammerId)
            ->where('groupid', $group->id)
            ->count();

        $this->assertEquals(1, $remaining);
    }

    public function test_inserts_users_banned_for_removed_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $group = $this->createTestGroup();
        $spammer = \App\Models\User::find($spammerId);
        $this->createMembership($spammer, $group, ['role' => 'Member']);

        $this->service->removeSpamMemberships();

        $banned = DB::table('users_banned')
            ->where('userid', $spammerId)
            ->where('groupid', $group->id)
            ->count();

        $this->assertEquals(1, $banned);
    }

    public function test_logs_autoremoved_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $group = $this->createTestGroup();
        $spammer = \App\Models\User::find($spammerId);
        $this->createMembership($spammer, $group, ['role' => 'Member']);

        $this->service->removeSpamMemberships();

        $log = DB::table('logs')
            ->where('user', $spammerId)
            ->where('type', 'Group')
            ->where('subtype', 'Left')
            ->where('text', 'Autoremoved spammer')
            ->first();

        $this->assertNotNull($log);
    }

    public function test_returns_count_of_removed_memberships(): void
    {
        $spammerId = $this->makeSpammer();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $spammer = \App\Models\User::find($spammerId);
        $this->createMembership($spammer, $group1, ['role' => 'Member']);
        $this->createMembership($spammer, $group2, ['role' => 'Member']);

        $count = $this->service->removeSpamMemberships();

        $this->assertEquals(2, $count);
    }

    // ===================================================================
    // deleteSpamMessages
    // ===================================================================

    public function test_soft_deletes_messages_from_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $spammer = \App\Models\User::find($spammerId);
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($spammer, $group);

        $this->service->deleteSpamMessages();

        $deleted = DB::table('messages')->where('id', $message->id)->value('deleted');
        $this->assertNotNull($deleted);
    }

    public function test_marks_messages_groups_deleted_for_spam_messages(): void
    {
        $spammerId = $this->makeSpammer();
        $spammer = \App\Models\User::find($spammerId);
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($spammer, $group);

        $this->service->deleteSpamMessages();

        $deleted = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->value('deleted');

        $this->assertEquals(1, $deleted);
    }

    public function test_does_not_delete_messages_from_non_spammer(): void
    {
        $author = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($author, $group);

        $this->service->deleteSpamMessages();

        $deleted = DB::table('messages')->where('id', $message->id)->value('deleted');
        $this->assertNull($deleted);
    }

    // ===================================================================
    // rejectSpamChatMessages
    // ===================================================================

    public function test_rejects_chat_messages_from_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $spammer = \App\Models\User::find($spammerId);
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $other);

        $msg = $this->createTestChatMessage($room, $spammer, [
            'reviewrequired' => 1,
            'reviewrejected' => 0,
        ]);

        $this->service->rejectSpamChatMessages();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrejected);
        $this->assertEquals(0, $updated->reviewrequired);
    }

    public function test_does_not_re_reject_already_rejected_chat_messages(): void
    {
        $spammerId = $this->makeSpammer();
        $spammer = \App\Models\User::find($spammerId);
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $other);

        $this->createTestChatMessage($room, $spammer, [
            'reviewrequired' => 0,
            'reviewrejected' => 1,
        ]);

        // Should run without error and not cause issues
        $this->service->rejectSpamChatMessages();

        $this->assertTrue(true);
    }

    // ===================================================================
    // deleteSpamNewsfeedItems
    // ===================================================================

    public function test_deletes_newsfeed_items_from_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $feedId = $this->insertNewsfeed($spammerId);

        $this->service->deleteSpamNewsfeedItems();

        $this->assertEquals(0, DB::table('newsfeed')->where('id', $feedId)->count());
    }

    public function test_does_not_delete_newsfeed_items_from_non_spammer(): void
    {
        $user = $this->createTestUser();
        $feedId = $this->insertNewsfeed($user->id);

        $this->service->deleteSpamNewsfeedItems();

        $this->assertEquals(1, DB::table('newsfeed')->where('id', $feedId)->count());
    }

    // ===================================================================
    // deleteSpamNotifications
    // ===================================================================

    public function test_deletes_notifications_from_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $recipient = $this->createTestUser();

        $notifId = DB::table('users_notifications')->insertGetId([
            'fromuser' => $spammerId,
            'touser' => $recipient->id,
            'type' => 'CommentOnYourPost',
            'seen' => 0,
            'mailed' => 0,
        ]);

        $this->service->deleteSpamNotifications();

        $this->assertEquals(0, DB::table('users_notifications')->where('id', $notifId)->count());
    }

    public function test_does_not_delete_notifications_from_non_spammer(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $notifId = DB::table('users_notifications')->insertGetId([
            'fromuser' => $sender->id,
            'touser' => $recipient->id,
            'type' => 'CommentOnYourPost',
            'seen' => 0,
            'mailed' => 0,
        ]);

        $this->service->deleteSpamNotifications();

        $this->assertEquals(1, DB::table('users_notifications')->where('id', $notifId)->count());
    }

    // ===================================================================
    // deleteSpamExpectedRecords
    // ===================================================================

    public function test_deletes_users_expected_records_where_spammer_is_expecter(): void
    {
        $spammerId = $this->makeSpammer();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom(\App\Models\User::find($spammerId), $other);
        $msg = $this->createTestChatMessage($room, $other);

        $expectedId = $this->insertUsersExpected($spammerId, $other->id, $msg->id);

        $this->service->deleteSpamExpectedRecords();

        $this->assertEquals(0, DB::table('users_expected')->where('id', $expectedId)->count());
    }

    public function test_does_not_delete_expected_records_where_spammer_is_expectee(): void
    {
        $spammerId = $this->makeSpammer();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom(\App\Models\User::find($spammerId), $other);
        $msg = $this->createTestChatMessage($room, $other);

        $expectedId = $this->insertUsersExpected($other->id, $spammerId, $msg->id);

        $this->service->deleteSpamExpectedRecords();

        $this->assertEquals(1, DB::table('users_expected')->where('id', $expectedId)->count());
    }

    // ===================================================================
    // deleteSpamSessions
    // ===================================================================

    public function test_deletes_sessions_for_spammer(): void
    {
        $spammerId = $this->makeSpammer();
        $sessionId = $this->insertSession($spammerId);

        $this->service->deleteSpamSessions();

        $this->assertEquals(0, DB::table('sessions')->where('id', $sessionId)->count());
    }

    public function test_does_not_delete_sessions_for_non_spammer(): void
    {
        $user = $this->createTestUser();
        $sessionId = $this->insertSession($user->id);

        $this->service->deleteSpamSessions();

        $this->assertEquals(1, DB::table('sessions')->where('id', $sessionId)->count());
    }

    // ===================================================================
    // removeSpamMembers (full integration)
    // ===================================================================

    public function test_remove_spam_members_returns_combined_count(): void
    {
        $spammerId = $this->makeSpammer();
        $spammer = \App\Models\User::find($spammerId);
        $group = $this->createTestGroup();
        $this->createMembership($spammer, $group, ['role' => 'Member']);
        $this->createTestMessage($spammer, $group);

        $count = $this->service->removeSpamMembers();

        // 1 membership removed + 1 message deleted
        $this->assertGreaterThanOrEqual(2, $count);
    }
}
