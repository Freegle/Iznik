<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ChatProcessService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatProcessServiceTest extends TestCase
{
    protected ChatProcessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatProcessService();
    }


    // --- Ban handling (Discourse: replies silently destroyed) ---

    /**
     * A ban belongs to the relationship between the two people, not to every community a
     * post happens to be on. Rippling puts a post on communities the poster never chose,
     * so asking "is the sender banned on ANY group this post is on?" silently destroyed
     * replies from members in good standing wherever they were talking.
     *
     * Live example: a Battersea member 410m from the poster, a member of her own community
     * and never banned there, had his reply thrown away because the post had rippled into a
     * community he happened to be banned on. The offerer was never told, and he had no idea
     * he had been ignored.
     */
    public function test_reply_delivered_when_sender_banned_only_on_an_unrelated_group_the_post_reached(): void
    {
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);

        $shared = $this->createTestGroup();      // both belong here, replier in good standing
        $elsewhere = $this->createTestGroup();   // replier banned here; poster has no part in it
        $this->createMembership($poster, $shared);
        $this->createMembership($replier, $shared);

        // The post is on both: its own community, plus one it rippled into.
        $message = $this->createTestMessage($poster, $shared);
        DB::table('messages_groups')->insert([
            'msgid' => $message->id, 'groupid' => $elsewhere->id,
            'collection' => 'Approved', 'arrival' => now(), 'deleted' => 0, 'rippled_in' => 1,
        ]);

        DB::table('users_banned')->insert([
            'userid' => $replier->id, 'groupid' => $elsewhere->id, 'byuser' => $poster->id,
        ]);

        $msg = $this->createTestChatMessage($room, $replier, [
            'processingrequired' => 1, 'processingsuccessful' => 0,
            'platform' => 1, 'refmsgid' => $message->id,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->processingsuccessful,
            'a reply from someone in good standing where they are both members must be delivered');
    }

    /**
     * The protection that must survive: someone banned everywhere the two of them share is
     * blocked. That is a fact about the two people, so it holds regardless of which
     * communities the post reached or how it got there.
     */
    public function test_reply_suppressed_when_sender_banned_on_every_group_they_share(): void
    {
        $poster = $this->createTestUser();
        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($poster, $replier);

        $shared = $this->createTestGroup();
        $this->createMembership($poster, $shared);
        $this->createMembership($replier, $shared);

        $message = $this->createTestMessage($poster, $shared);

        DB::table('users_banned')->insert([
            'userid' => $replier->id, 'groupid' => $shared->id, 'byuser' => $poster->id,
        ]);

        $msg = $this->createTestChatMessage($room, $replier, [
            'processingrequired' => 1, 'processingsuccessful' => 0,
            'platform' => 1, 'refmsgid' => $message->id,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingsuccessful,
            'someone banned everywhere they share a community with the poster stays blocked');
        $this->assertEquals(ChatMessage::PROCESSFAIL_BANNED_IN_COMMON, $updated->processingfailreason,
            'support tools must be able to see WHY the reply never arrived');
    }

    /**
     * The silence was the worst part: a suppressed reply looked identical to one that was
     * never written, so it got misdiagnosed. Record why on the message itself.
     */
    public function test_spam_suppression_records_a_reason_for_support(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id, 'collection' => 'Spammer', 'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(ChatMessage::PROCESSFAIL_SPAMMER, $updated->processingfailreason);
    }

    // --- Basic processing ---

    public function test_message_with_processingrequired_gets_marked_processed(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $count = $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
        $this->assertEquals(0, $updated->reviewrequired);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_already_processed_message_is_not_touched(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $count = $this->service->processIncoming();

        $this->assertEquals(0, $count);
    }

    public function test_returns_count_of_processed_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $this->createTestChatMessage($room, $user1, ['processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1]);
        $this->createTestChatMessage($room, $user2, ['processingrequired' => 1, 'processingsuccessful' => 0, 'platform' => 1]);

        $count = $this->service->processIncoming();

        $this->assertEquals(2, $count);
    }

    // --- Spammer checks ---

    public function test_message_from_confirmed_spammer_fails_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(0, $updated->processingsuccessful);
    }

    public function test_message_from_pending_add_spammer_fails_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'PendingAdd',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(0, $updated->processingsuccessful);
    }

    public function test_message_from_whitelisted_user_processes_normally(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        DB::table('spam_users')->insert([
            'userid' => $user1->id,
            'collection' => 'Whitelisted',
            'added' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    // --- Roster update ---

    public function test_email_message_updates_sender_roster(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Create roster entry for user1 in this room
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_OFFLINE,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 0,  // email reply
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user1->id)->first();
        $this->assertEquals($msg->id, $roster->lastmsgseen);
        $this->assertEquals($msg->id, $roster->lastmsgemailed);
    }

    // --- Closed chat reopen ---

    public function test_closed_chat_is_reopened_after_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user2's roster entry is CLOSED
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_CLOSED,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user2->id)->first();
        $this->assertEquals(ChatRoster::STATUS_OFFLINE, $roster->status);
    }

    public function test_blocked_chat_stays_blocked_after_processing(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // user2's roster entry is BLOCKED
        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_BLOCKED,
            'lastmsgseen' => null,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $roster = DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $user2->id)->first();
        $this->assertEquals(ChatRoster::STATUS_BLOCKED, $roster->status);
    }

    // --- Review cascade ---

    public function test_message_held_when_previous_message_under_review(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Previous message from user2 is under review
        $this->createTestChatMessage($room, $user2, [
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // New message from user1 needs processing
        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertEquals(0, $updated->processingrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    // Regression: Discourse #9656. Once a member has a message held for review,
    // EVERY subsequent message must also be held until a mod clears them. V1's
    // chat_process daemon processed one message at a time, so the hold chain
    // propagated naturally. This service processes a whole burst at once, and the
    // chain query previously looked at the newest OTHER row (id != $id) — which,
    // mid-batch, is a later not-yet-processed message with reviewrequired = 0, so
    // the chain broke and innocuous messages between worry-word ones were
    // delivered. The chain must follow the immediately preceding message.
    public function test_hold_chain_propagates_across_a_burst_of_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // An earlier message from user1 is already held for review.
        $this->createTestChatMessage($room, $user1, [
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Two further innocuous messages arrive in the SAME batch (both pending).
        // The second has the higher id, so under the old id != $id query it is the
        // "newest other row" seen while processing the first.
        $first = $this->createTestChatMessage($room, $user1, [
            'message' => 'innocuous one',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);
        $second = $this->createTestChatMessage($room, $user1, [
            'message' => 'innocuous two',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $u1 = DB::table('chat_messages')->where('id', $first->id)->first();
        $u2 = DB::table('chat_messages')->where('id', $second->id)->first();
        $this->assertEquals(1, $u1->reviewrequired, 'message after a held one must also be held');
        $this->assertEquals(1, $u2->reviewrequired, 'hold chain must continue through the whole burst');
    }

    // --- Content checks for Moderated members (regression: Discourse #9706) ---
    //
    // V1 ChatMessage::process() ran Spam::checkReview() on Moderated members'
    // messages and held any that matched. That scan was dropped when chat
    // processing was migrated to ChatProcessService, letting graphic/spam chat
    // content through unflagged. These tests pin the restored behaviour.

    public function test_moderated_user_message_with_concern_keyword_is_held_for_review(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword' => 'testbadword_chat',
            'category' => 'review',
            'action' => 'flag',
            'match_mode' => 'literal',
            'scope' => 'global',
        ]);

        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hello there testbadword_chat have a look',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'Moderated member message matching a concern keyword should be held for review');
        // The specific check is surfaced as the reportreason so the modtools
        // review UI can tell the moderator WHY (a concern/worry word) instead of
        // the unhelpful "...no more information about why".
        $this->assertEquals('WorryWord', $updated->reportreason);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    public function test_held_message_reportreason_reflects_the_specific_check(): void
    {
        // A money symbol must be surfaced as reportreason 'Money', not the generic
        // 'Spam', so the review UI shows "It looks like it refers to money."
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'I can do it for £50 if you collect',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'A money symbol from a Moderated member should be held');
        $this->assertEquals('Money', $updated->reportreason, 'reportreason must name the specific check (Money), not generic Spam');
    }

    public function test_moderated_user_clean_message_is_not_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hi, is the lamp still available please?',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'Clean message from a Moderated member should pass through');
        $this->assertNull($updated->reportreason);
    }

    public function test_moderated_user_message_with_phone_number_is_not_held(): void
    {
        // Sharing a phone number to arrange a handover is normal, so chat
        // messages are deliberately NOT phone-number checked (V1 parity).
        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Text me on 07700 900123 to arrange',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'A bare phone number in chat should NOT be held for review');
        $this->assertNull($updated->reportreason);
    }

    public function test_unmoderated_user_message_is_not_content_checked(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword' => 'testbadword_chat',
            'category' => 'review',
            'action' => 'flag',
            'match_mode' => 'literal',
            'scope' => 'global',
        ]);

        $sender = $this->createTestUser(['chatmodstatus' => 'Unmoderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hello there testbadword_chat have a look',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'Unmoderated members bypass content checks (V1 parity)');
    }

    public function test_fully_moderated_user_message_is_always_held(): void
    {
        $sender = $this->createTestUser(['chatmodstatus' => 'Fully']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'Hi, is the lamp still available please?',
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired, 'Fully moderated members have every message held');
        $this->assertEquals('Spam', $updated->reportreason);
    }

    public function test_moderated_user_system_message_is_not_content_checked(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword' => 'testbadword_chat',
            'category' => 'review',
            'action' => 'flag',
            'match_mode' => 'literal',
            'scope' => 'global',
        ]);

        $sender = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message' => 'System note containing testbadword_chat',
            'type' => ChatMessage::TYPE_SYSTEM,
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired, 'System/templated messages are not content checked');
    }

    // --- Push notification enqueue (Discourse: chat push lost since 2026-05-08) ---
    //
    // V1 ChatMessage::process() called notifyMembers() after a message passed
    // spam/review/ban checks. That FCM-push side was dropped when chat_process.php
    // was migrated to ChatProcessService (commit 5cbb607b7), leaving users with
    // no push for new chat messages — only the delayed digest email. Restore
    // V1 parity by enqueuing a push_notify_chat_message background task for
    // every successfully processed, non-held message.

    public function test_successfully_processed_message_enqueues_push_notification_task(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $msg = $this->createTestChatMessage($room, $user1, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($task, 'Expected a push_notify_chat_message task to be queued for a successfully processed message');
        $data = json_decode($task->data, TRUE);
        $this->assertEquals($msg->id, $data['message_id']);
    }

    public function test_held_for_review_message_does_not_enqueue_push(): void
    {
        // Fully-moderated sender → every message is held automatically.
        $sender = $this->createTestUser(['chatmodstatus' => 'Fully']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->first();

        $this->assertNull($task, 'Held-for-review messages must not push — V1 invariant');

        // Sanity: message was processed and held, not discarded.
        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(1, $updated->reviewrequired);
        $this->assertEquals(1, $updated->processingsuccessful);
    }

    public function test_spammer_message_does_not_enqueue_push(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);

        DB::table('spam_users')->insert([
            'userid' => $sender->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $this->createTestChatMessage($room, $sender, [
            'processingrequired' => 1,
            'processingsuccessful' => 0,
            'platform' => 1,
        ]);

        $this->service->processIncoming();

        $task = DB::table('background_tasks')
            ->where('task_type', 'push_notify_chat_message')
            ->first();

        $this->assertNull($task, 'Spammer messages must not push');
    }
}
