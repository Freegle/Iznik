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

    // -------------------------------------------------------------------------
    // updateExpected: incremental behaviour
    // -------------------------------------------------------------------------

    /**
     * The whole point of the delta: a message that is still waiting, in a chat where
     * nothing has happened, must not be re-examined or rewritten on every run.
     */
    public function test_second_run_skips_chats_with_no_activity(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $first = $this->service->updateExpected();
        $this->assertTrue($first['full'], 'the first run has no cursor so must do everything');
        $this->assertEquals(1, $first['checked']);

        $second = $this->service->updateExpected();

        $this->assertFalse($second['full']);
        $this->assertEquals(0, $second['checked'], 'nothing happened in that chat, so nothing to re-check');

        // The answer is still on record, it just was not written again.
        $this->assertEquals(-1, DB::table('users_expected')->where('chatmsgid', $msg->id)->value('value'));
    }

    public function test_a_new_message_brings_its_chat_back_into_the_delta(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $this->service->updateExpected();

        // The reply the message was waiting for.
        $this->createTestChatMessage($room, $user2, ['date' => now()->subHour()]);

        $stats = $this->service->updateExpected();

        $this->assertFalse($stats['full']);
        $this->assertEquals(1, $stats['received']);
        $this->assertEquals(1, $msg->fresh()->replyreceived);
        $this->assertEquals(1, DB::table('users_expected')->where('chatmsgid', $msg->id)->value('value'));
    }

    /**
     * The hazard this design has to cover: a rippling-held reply is already in
     * chat_messages, so releasing it makes a reply deliverable without any new message
     * arriving. A cursor that only watched message ids would miss it, and the expecter
     * would keep being told nobody had replied.
     */
    public function test_releasing_a_held_reply_brings_its_chat_back_into_the_delta(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        // user2's reply exists but is held by rippling, so it does not count yet.
        $reply = $this->createTestChatMessage($room, $user2, ['date' => now()->subDays(1)]);
        $post = $this->createTestMessage($user1, $this->createTestGroup());
        DB::table('rippling_held_replies')->insert([
            'chatid'         => $room->id,
            'chatmsgid'      => $reply->id,
            'msgid'          => $post->id,
            'replieruserid'  => $user2->id,
            'status'         => 'held',
        ]);

        $first = $this->service->updateExpected();
        $this->assertEquals(1, $first['waiting'], 'a held reply must not count as received');

        // Release it - no new chat message is created, only the status changes.
        DB::table('rippling_held_replies')->where('chatmsgid', $reply->id)->update([
            'status'     => 'released',
            'releasedat' => now(),
        ]);

        $stats = $this->service->updateExpected();

        $this->assertFalse($stats['full']);
        $this->assertEquals(1, $stats['received'], 'the released reply must be picked up');
        $this->assertEquals(1, DB::table('users_expected')->where('chatmsgid', $msg->id)->value('value'));
    }

    public function test_full_run_rechecks_everything_regardless_of_activity(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $this->service->updateExpected();

        $stats = $this->service->updateExpected(false, true);

        $this->assertTrue($stats['full']);
        $this->assertEquals(1, $stats['checked']);
    }

    public function test_a_dry_run_does_not_move_the_cursor(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'date'          => now()->subDays(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);

        $this->service->updateExpected(true);

        // Still no cursor, so the next real run must do the full pass.
        $this->assertNull(DB::table('config')->where('key', 'chat.expected_cursor')->value('value'));
        $this->assertTrue($this->service->updateExpected()['full']);
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
