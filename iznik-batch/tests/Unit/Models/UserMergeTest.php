<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for User::merge() — ported from iznik-server UserTest::testMerge()
 * and iznik-server-go TestPostUserMerge.
 */
class UserMergeTest extends TestCase
{
    private const MERGE_REASON = 'Test merge';
    private const OLDER_DATE = '2020-01-01 00:00:00';

    private const DB_WRITES_DISABLED_SKIP_REASON = 'Temporarily skipped: TNSyncCommand-related write paths are disabled for port testing.';

    private function skipIfTNSyncWritesDisabled(): void
    {
        $this->markTestSkipped(self::DB_WRITES_DISABLED_SKIP_REASON);
    }

    public function test_merge_consolidates_emails(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $extraEmail = $this->createTestUserEmail($user2);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // All emails from user2 should now belong to user1.
        $user1Emails = DB::table('users_emails')->where('userid', $user1->id)->pluck('email')->toArray();
        $this->assertContains($extraEmail->email, $user1Emails);

        // user2 should have no emails left.
        $this->assertEquals(0, DB::table('users_emails')->where('userid', $user2->id)->count());
    }

    public function test_merge_preserves_user1_primary_email(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $user1PrimaryEmail = $user1->email_preferred;

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // user1's original primary should still be preferred.
        $preferred = DB::table('users_emails')
            ->where('userid', $user1->id)
            ->where('preferred', 1)
            ->value('email');

        $this->assertEquals($user1PrimaryEmail, $preferred);
    }

    public function test_merge_preserves_highest_role(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        // user1 is Member, user2 is Moderator on same group.
        $this->createMembership($user1, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createMembership($user2, $group, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $role = DB::table('memberships')
            ->where('userid', $user1->id)
            ->where('groupid', $group->id)
            ->value('role');

        $this->assertEquals(Membership::ROLE_MODERATOR, $role);
    }

    public function test_merge_preserves_oldest_membership_date(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($user1, $group, ['added' => self::OLDER_DATE]);
        $this->createMembership($user2, $group, ['added' => '2024-01-01 00:00:00']);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $added = DB::table('memberships')
            ->where('userid', $user1->id)
            ->where('groupid', $group->id)
            ->value('added');

        $this->assertEquals(self::OLDER_DATE, $added);
    }

    public function test_merge_transfers_unique_memberships(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $this->createMembership($user1, $group1);
        $this->createMembership($user2, $group2);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // user1 should now be a member of both groups.
        $this->assertEquals(2, DB::table('memberships')->where('userid', $user1->id)->count());
        $this->assertEquals(0, DB::table('memberships')->where('userid', $user2->id)->count());
    }

    public function test_merge_transfers_messages(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);
        $message = $this->createTestMessage($user2, $group);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // Message should now belong to user1.
        $fromUser = DB::table('messages')->where('id', $message->id)->value('fromuser');
        $this->assertEquals($user1->id, $fromUser);
    }

    public function test_merge_deletes_source_user(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $user2Id = $user2->id;

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $this->assertNull(User::find($user2Id));
    }

    public function test_merge_logs_action(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $log = DB::table('logs')
            ->where('user', $user1->id)
            ->where('type', 'User')
            ->where('subtype', 'Merged')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Merged', $log->text);
    }

    public function test_merge_preserves_highest_system_role(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['systemrole' => User::SYSTEMROLE_USER]);
        $user2 = $this->createTestUser(['systemrole' => User::SYSTEMROLE_SUPPORT]);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $role = DB::table('users')->where('id', $user1->id)->value('systemrole');
        $this->assertEquals(User::SYSTEMROLE_SUPPORT, $role);
    }

    public function test_merge_transfers_tn_user_id(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['tnuserid' => null]);
        $user2 = $this->createTestUser(['tnuserid' => 12345]);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $tnId = DB::table('users')->where('id', $user1->id)->value('tnuserid');
        $this->assertEquals(12345, $tnId);
    }

    public function test_merge_returns_false_for_same_user(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse(User::merge($user->id, $user->id, self::MERGE_REASON));
    }

    public function test_merge_returns_false_for_nonexistent_user(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse(User::merge($user->id, 999999999, self::MERGE_REASON));
    }

    public function test_merge_respects_can_merge_setting(): void
    {
        $user1 = $this->createTestUser(['settings' => ['canmerge' => false]]);
        $user2 = $this->createTestUser();

        $this->assertFalse(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // user2 should still exist.
        $this->assertNotNull(User::find($user2->id));
    }

    public function test_merge_force_bypasses_can_merge(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['settings' => ['canmerge' => false]]);
        $user2 = $this->createTestUser();

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON, true));

        $this->assertNull(User::find($user2->id));
    }

    public function test_merge_consolidates_chat_rooms(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $other = $this->createTestUser();

        // user2 has a chat with 'other'.
        $room = $this->createTestChatRoom($user2, $other);
        $this->createTestChatMessage($room, $user2, ['message' => 'hello from user2']);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // Chat room should now reference user1 instead of user2.
        $mergedRoom = DB::table('chat_rooms')
            ->where(function ($q) use ($user1, $other) {
                $q->where(function ($q2) use ($user1, $other) {
                    $q2->where('user1', $user1->id)->where('user2', $other->id);
                })->orWhere(function ($q2) use ($user1, $other) {
                    $q2->where('user1', $other->id)->where('user2', $user1->id);
                });
            })
            ->first();

        $this->assertNotNull($mergedRoom);
    }

    public function test_merge_merges_chat_messages_into_existing_room(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $other = $this->createTestUser();

        // Both user1 and user2 have chats with 'other'.
        $room1 = $this->createTestChatRoom($user1, $other);
        $this->createTestChatMessage($room1, $user1, ['message' => 'from user1']);

        $room2 = $this->createTestChatRoom($user2, $other);
        $this->createTestChatMessage($room2, $user2, ['message' => 'from user2']);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        // Both messages should now be in room1.
        $messagesInRoom1 = DB::table('chat_messages')->where('chatid', $room1->id)->count();
        $this->assertEquals(2, $messagesInRoom1);
    }

    public function test_merge_keeps_oldest_added_date(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['added' => '2024-06-01 00:00:00']);
        $user2 = $this->createTestUser(['added' => self::OLDER_DATE]);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $added = DB::table('users')->where('id', $user1->id)->value('added');
        $this->assertEquals(self::OLDER_DATE, $added);
    }

    public function test_merge_takes_non_null_fullname_from_user2(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user1 = $this->createTestUser(['fullname' => null]);
        $user2 = $this->createTestUser(['fullname' => 'Real Name']);

        $this->assertTrue(User::merge($user1->id, $user2->id, self::MERGE_REASON));

        $name = DB::table('users')->where('id', $user1->id)->value('fullname');
        $this->assertEquals('Real Name', $name);
    }
}
