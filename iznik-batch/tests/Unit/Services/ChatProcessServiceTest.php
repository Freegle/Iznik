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
}
