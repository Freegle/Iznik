<?php

namespace Tests\Unit\Models;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Tests\TestCase;

class ChatRosterModelTest extends TestCase
{
    public function test_chat_roster_can_be_created(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);

        $this->assertDatabaseHas('chat_roster', ['id' => $roster->id]);
    }

    public function test_online_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $online = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);

        $offline = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_OFFLINE,
            'date' => now(),
        ]);

        $results = ChatRoster::online()->get();

        $this->assertTrue($results->contains('id', $online->id));
        $this->assertFalse($results->contains('id', $offline->id));
    }

    public function test_not_blocked_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $online = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);

        $blocked = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_BLOCKED,
            'date' => now(),
        ]);

        // V1 PHP also filters Closed — verify our scope matches.
        $closed = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user3->id,
            'status' => ChatRoster::STATUS_CLOSED,
            'date' => now(),
        ]);

        $results = ChatRoster::notBlocked()->get();

        $this->assertTrue($results->contains('id', $online->id));
        $this->assertFalse($results->contains('id', $blocked->id));
        $this->assertFalse($results->contains('id', $closed->id), 'Closed roster entries should be excluded by notBlocked scope');
    }

    public function test_chat_room_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);

        $this->assertEquals($room->id, $roster->chatRoom->id);
    }

    public function test_user_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
        ]);

        $this->assertEquals($user1->id, $roster->user->id);
    }

    public function test_needs_email_with_null_lastmsgemailed(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
            'lastmsgemailed' => null,
        ]);

        $this->assertTrue($roster->needsEmail(100));
    }

    public function test_needs_email_with_lower_lastmsgemailed(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
            'lastmsgemailed' => 50,
        ]);

        $this->assertTrue($roster->needsEmail(100));
    }

    public function test_needs_email_returns_false_when_up_to_date(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
            'lastmsgemailed' => 100,
        ]);

        $this->assertFalse($roster->needsEmail(50));
        $this->assertFalse($roster->needsEmail(100));
    }

    public function test_status_constants(): void
    {
        $this->assertEquals('Online', ChatRoster::STATUS_ONLINE);
        $this->assertEquals('Away', ChatRoster::STATUS_AWAY);
        $this->assertEquals('Offline', ChatRoster::STATUS_OFFLINE);
        $this->assertEquals('Closed', ChatRoster::STATUS_CLOSED);
        $this->assertEquals('Blocked', ChatRoster::STATUS_BLOCKED);
    }

    public function test_with_unread_messages_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Create a message in the room.
        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Test message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Create roster entry with lower lastmsgseen.
        $roster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'status' => ChatRoster::STATUS_ONLINE,
            'date' => now(),
            'lastmsgseen' => $message->id - 1,
        ]);

        $results = ChatRoster::withUnreadMessages()->get();

        // The roster should be found as it has unread messages.
        $this->assertTrue($results->contains('id', $roster->id));
    }
}
