<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Services\ChatExpectedService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatExpectedServiceTest extends TestCase
{
    protected ChatExpectedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatExpectedService();
    }

    // -------------------------------------------------------------------------
    // tidyDeletedUsersReplies
    // -------------------------------------------------------------------------

    public function test_tidy_deleted_users_clears_replyexpected(): void
    {
        $user = $this->createTestUser(['deleted' => now()->subHours(12)]);
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $user2);

        $msg = $this->createTestChatMessage($room, $user, [
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $count = $this->service->tidyDeletedUsersReplies();

        $this->assertEquals(1, $count);
        $this->assertEquals(0, $msg->fresh()->replyexpected);
    }

    public function test_tidy_deleted_users_skips_old_deletions(): void
    {
        // Deleted more than 24h ago — should NOT be cleared
        $user = $this->createTestUser(['deleted' => now()->subDays(3)]);
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $user2);

        $msg = $this->createTestChatMessage($room, $user, [
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $count = $this->service->tidyDeletedUsersReplies();

        $this->assertEquals(0, $count);
        $this->assertEquals(1, $msg->fresh()->replyexpected);
    }

    public function test_tidy_deleted_users_skips_already_received(): void
    {
        $user = $this->createTestUser(['deleted' => now()->subHours(6)]);
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $user2);

        $msg = $this->createTestChatMessage($room, $user, [
            'replyexpected' => 1,
            'replyreceived' => 1,
        ]);

        $count = $this->service->tidyDeletedUsersReplies();

        $this->assertEquals(0, $count);
    }

    // -------------------------------------------------------------------------
    // tidySpamUsersReplies
    // -------------------------------------------------------------------------

    public function test_tidy_spam_users_clears_replyexpected(): void
    {
        $spammer = $this->createTestUser();
        $victim = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $victim);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $spammer, [
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $count = $this->service->tidySpamUsersReplies();

        $this->assertEquals(1, $count);
        $this->assertEquals(0, $msg->fresh()->replyexpected);
    }

    public function test_tidy_spam_users_leaves_non_spammers(): void
    {
        $user = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $user2);

        $msg = $this->createTestChatMessage($room, $user, [
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $count = $this->service->tidySpamUsersReplies();

        $this->assertEquals(0, $count);
        $this->assertEquals(1, $msg->fresh()->replyexpected);
    }

    // -------------------------------------------------------------------------
    // updateExpected
    // -------------------------------------------------------------------------

    public function test_update_expected_marks_received_when_other_user_replied(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user1 sent a message expecting a reply
        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        // user2 replied after user1's message
        $this->createTestChatMessage($room, $user2, [
            'date' => now()->subDays(1),
        ]);

        $stats = $this->service->updateExpected();

        $this->assertEquals(1, $stats['received']);
        $this->assertEquals(0, $stats['waiting']);
        $this->assertEquals(1, $msg->fresh()->replyreceived);

        $row = DB::table('users_expected')->where('chatmsgid', $msg->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(1, $row->value);
        $this->assertEquals($user1->id, $row->expecter);
        $this->assertEquals($user2->id, $row->expectee);
    }

    public function test_update_expected_marks_waiting_when_no_reply(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $stats = $this->service->updateExpected();

        $this->assertEquals(0, $stats['received']);
        $this->assertEquals(1, $stats['waiting']);
        $this->assertEquals(0, $msg->fresh()->replyreceived);

        $row = DB::table('users_expected')->where('chatmsgid', $msg->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(-1, $row->value);
    }

    public function test_update_expected_skips_messages_older_than_lookback(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // 32 days old — outside LOOKBACK_DAYS=31
        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(32),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $stats = $this->service->updateExpected();

        $this->assertEquals(0, $stats['received']);
        $this->assertEquals(0, $stats['waiting']);
    }

    public function test_update_expected_updates_existing_row(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        // Pre-existing row with waiting value
        DB::table('users_expected')->insert([
            'expecter'  => $user1->id,
            'expectee'  => $user2->id,
            'chatmsgid' => $msg->id,
            'value'     => -1,
        ]);

        // user2 now replied
        $this->createTestChatMessage($room, $user2, ['date' => now()->subHours(1)]);

        $this->service->updateExpected();

        $row = DB::table('users_expected')->where('chatmsgid', $msg->id)->first();
        $this->assertEquals(1, $row->value);
    }

    // -------------------------------------------------------------------------
    // updateReplyTimes
    // -------------------------------------------------------------------------

    public function test_update_reply_times_does_nothing_for_empty_array(): void
    {
        $this->service->updateReplyTimes([]);

        // No exception and no rows inserted
        $this->assertEquals(0, DB::table('users_replytime')->count());
    }

    public function test_update_reply_times_inserts_null_when_no_messages(): void
    {
        $user = $this->createTestUser();

        $this->service->updateReplyTimes([$user->id]);

        $row = DB::table('users_replytime')->where('userid', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->replytime);
    }

    public function test_update_reply_times_calculates_median_delay(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $sentAt = now()->subDays(5);
        $repliedAt = $sentAt->copy()->addHours(2); // 7200 seconds

        $msg = $this->createTestChatMessage($room, $user1, [
            'date' => $sentAt,
            'type' => ChatMessage::TYPE_DEFAULT,
        ]);

        // user2 replied 2 hours later
        $this->createTestChatMessage($room, $user2, [
            'date' => $repliedAt,
            'type' => ChatMessage::TYPE_DEFAULT,
        ]);

        $this->service->updateReplyTimes([$user1->id]);

        $row = DB::table('users_replytime')->where('userid', $user1->id)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->replytime);
        // Should be approximately 7200 seconds (2 hours)
        $this->assertEqualsWithDelta(7200, $row->replytime, 60);
    }

    public function test_update_reply_times_stores_null_when_no_valid_delays(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user1 sent a message but user2 hasn't replied and user2 has no prior message
        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(5),
            'type' => ChatMessage::TYPE_DEFAULT,
        ]);

        $this->service->updateReplyTimes([$user1->id]);

        $row = DB::table('users_replytime')->where('userid', $user1->id)->first();
        $this->assertNull($row->replytime);
    }

    public function test_update_reply_times_updates_existing_record(): void
    {
        $user = $this->createTestUser();

        DB::table('users_replytime')->insert([
            'userid'    => $user->id,
            'replytime' => 99999,
        ]);

        $this->service->updateReplyTimes([$user->id]);

        $count = DB::table('users_replytime')->where('userid', $user->id)->count();
        $this->assertEquals(1, $count); // no duplicate
    }
}
