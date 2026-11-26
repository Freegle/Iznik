<?php

namespace Tests\Unit\Models;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Tests\TestCase;

class ChatImageModelTest extends TestCase
{
    public function test_chat_image_can_be_created(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Here is an image',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $image = ChatImage::create([
            'chatmsgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
        ]);

        $this->assertDatabaseHas('chat_images', ['id' => $image->id]);
    }

    public function test_chat_message_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Here is an image',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $image = ChatImage::create([
            'chatmsgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
        ]);

        $this->assertEquals($message->id, $image->chatMessage->id);
    }

    public function test_chat_room_relationship_via_message(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Here is an image',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        $image = ChatImage::create([
            'chatmsgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'archived' => false,
        ]);

        // Access chat room via the message.
        $this->assertEquals($room->id, $image->chatMessage->chatRoom->id);
    }
}
