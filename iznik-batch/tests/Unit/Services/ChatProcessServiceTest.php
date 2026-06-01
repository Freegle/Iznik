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
        $this->assertEquals('Spam', $updated->reportreason);
        $this->assertEquals(1, $updated->processingsuccessful);
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

    // --- Postcode hold (Discourse #9656 post 38) ---
    //
    // Regex concern keywords can match UK postcode patterns (e.g. a scam-detection
    // regex that inadvertently covers postcode inward/outward codes). Sharing a
    // collection address is as legitimate as sharing a phone number, so postcodes
    // must be stripped before concern-keyword scanning in chat — same rationale as
    // the phone-number exclusion.

    public function test_moderated_user_message_with_postcode_is_not_held(): void
    {
        // A message containing only a UK postcode must NOT be held for review even
        // when a global regex concern keyword matches postcode patterns — sharing a
        // collection address is normal in chat.
        DB::table('concern_keywords')->insert([
            'keyword'    => '\b[A-Z]{1,2}\d\s+\d[A-Z]{2}\b',
            'category'   => 'review',
            'action'     => 'flag',
            'match_mode' => 'regex',
            'scope'      => 'global',
            'group_id'   => 0,
        ]);

        $sender    = $this->createTestUser(['chatmodstatus' => 'Moderated']);
        $recipient = $this->createTestUser();
        $room      = $this->createTestChatRoom($sender, $recipient);

        $msg = $this->createTestChatMessage($room, $sender, [
            'message'              => 'Please collect at AB1 2CD',
            'processingrequired'   => 1,
            'processingsuccessful' => 0,
            'platform'             => 1,
        ]);

        $this->service->processIncoming();

        $updated = DB::table('chat_messages')->where('id', $msg->id)->first();
        $this->assertEquals(0, $updated->reviewrequired,
            'A message containing a UK postcode should NOT be held for review in chat');
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
