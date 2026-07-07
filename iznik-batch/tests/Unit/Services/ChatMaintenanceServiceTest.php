<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ChatMaintenanceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatMaintenanceServiceTest extends TestCase
{
    protected ChatMaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatMaintenanceService();
    }

    public function test_updates_valid_message_counts(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Create 2 valid messages and 1 invalid (review required).
        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(1),
        ]);
        $this->createTestChatMessage($room, $user2, [
            'date' => now()->subDays(1),
        ]);
        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(1),
            'reviewrequired' => 1,
        ]);

        $stats = $this->service->updateMessageCounts();

        $this->assertGreaterThanOrEqual(1, $stats['rooms_updated']);

        $room->refresh();
        $this->assertEquals(2, $room->msgvalid);
        $this->assertEquals(1, $room->msginvalid);
    }

    public function test_updates_latest_message_date(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        $latestDate = now()->subDays(2);

        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(5),
        ]);
        $this->createTestChatMessage($room, $user2, [
            'date' => $latestDate,
        ]);

        $this->service->updateMessageCounts();

        $room->refresh();
        $this->assertEquals(
            $latestDate->format('Y-m-d H:i:s'),
            $room->latestmessage->format('Y-m-d H:i:s')
        );
    }

    public function test_mod2mod_ignores_invalid_count(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2, [
            'chattype' => ChatRoom::TYPE_MOD2MOD,
        ]);

        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(1),
            'reviewrequired' => 1,
        ]);

        $this->service->updateMessageCounts();

        $room->refresh();
        // Mod2Mod chats should have invalidcount forced to 0.
        $this->assertEquals(0, $room->msginvalid);
    }

    public function test_reopens_closed_user2mod_chats(): void
    {
        $user1 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);

        // Create a User2Mod chat room.
        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user1->id,
            'groupid' => $group->id,
            'created' => now()->subDays(10),
            'latestmessage' => now()->subDays(1),
        ]);

        // Create a roster entry that was closed before the latest message.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_CLOSED,
            'date' => now()->subDays(5),
        ]);

        // Create a message so the room appears in the 31-day window.
        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(1),
        ]);

        $stats = $this->service->updateMessageCounts();

        $this->assertEquals(1, $stats['rooms_reopened']);

        $roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $user1->id)
            ->first();
        $this->assertEquals(ChatRoster::STATUS_AWAY, $roster->status);
    }

    public function test_does_not_reopen_non_user2mod_chats(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = $this->createTestChatRoom($user1, $user2, [
            'chattype' => ChatRoom::TYPE_USER2USER,
            'latestmessage' => now()->subDays(1),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_CLOSED,
            'date' => now()->subDays(5),
        ]);

        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(1),
        ]);

        $stats = $this->service->updateMessageCounts();

        $this->assertEquals(0, $stats['rooms_reopened']);
    }

    public function test_skips_rooms_with_no_recent_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = $this->createTestChatRoom($user1, $user2);

        // Create a message older than 31 days.
        $this->createTestChatMessage($room, $user1, [
            'date' => now()->subDays(40),
        ]);

        // Set room counts to known values to verify they are NOT updated.
        DB::table('chat_rooms')->where('id', $room->id)->update([
            'msgvalid' => 99,
            'msginvalid' => 99,
        ]);

        $this->service->updateMessageCounts();

        // Room counts should remain unchanged since the message is too old.
        $room->refresh();
        $this->assertEquals(99, $room->msgvalid);
        $this->assertEquals(99, $room->msginvalid);
    }
}
