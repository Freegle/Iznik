<?php

namespace Tests\Unit\Models;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Tests\TestCase;

class ChatMessageModelTest extends TestCase
{
    public function test_chat_message_can_be_created(): void
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
            'message' => 'Test message',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertDatabaseHas('chat_messages', ['id' => $message->id]);
    }

    public function test_visible_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Visible message.
        $visible = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Visible',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        // Hidden message (review rejected).
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Hidden',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 1,
            'platform' => 1,
        ]);

        // Filter to just our test room to avoid interference from parallel tests.
        $visibleMessages = ChatMessage::visible()->where('chatid', $room->id)->get();

        $this->assertTrue($visibleMessages->contains('id', $visible->id));
        $this->assertEquals(1, $visibleMessages->count());
    }

    public function test_requiring_review_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $needsReview = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Needs review',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 0,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $reviewMessages = ChatMessage::requiringReview()->get();

        $this->assertTrue($reviewMessages->contains('id', $needsReview->id));
    }

    public function test_unseen_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $unseen = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Unseen',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $unseenMessages = ChatMessage::unseen()->get();

        $this->assertTrue($unseenMessages->contains('id', $unseen->id));
    }

    public function test_unmailed_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $unmailed = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Not mailed',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $unmailedMessages = ChatMessage::unmailed()->get();

        $this->assertTrue($unmailedMessages->contains('id', $unmailed->id));
    }

    public function test_expecting_reply_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $expectingReply = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Expecting reply',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'replyexpected' => 1,
            'replyreceived' => 0,
            'platform' => 1,
        ]);

        $messages = ChatMessage::expectingReply()->get();

        $this->assertTrue($messages->contains('id', $expectingReply->id));
    }

    public function test_recent_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $recent = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Recent',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(5),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $old = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Old',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(60),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $recentMessages = ChatMessage::recent(31)->get();

        $this->assertTrue($recentMessages->contains('id', $recent->id));
        $this->assertFalse($recentMessages->contains('id', $old->id));
    }

    public function test_is_visible(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $visibleMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Visible',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertTrue($visibleMessage->isVisible());

        $hiddenMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Hidden',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 1,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertFalse($hiddenMessage->isVisible());
    }

    public function test_is_system_message(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $systemMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'System',
            'type' => ChatMessage::TYPE_SYSTEM,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $defaultMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Default',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertTrue($systemMessage->isSystemMessage());
        $this->assertFalse($defaultMessage->isSystemMessage());
    }

    public function test_is_from_platform(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $platformMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'From platform',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $emailMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'From email',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 0,
        ]);

        $this->assertTrue($platformMessage->isFromPlatform());
        $this->assertFalse($emailMessage->isFromPlatform());
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

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertInstanceOf(ChatRoom::class, $message->chatRoom);
        $this->assertEquals($room->id, $message->chatRoom->id);
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

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertEquals($user1->id, $message->user->id);
    }

    public function test_ref_message_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);

        $refMessage = $this->createTestMessage($user1, $group);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $chatMessage = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Interested in this',
            'type' => ChatMessage::TYPE_INTERESTED,
            'refmsgid' => $refMessage->id,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertNotNull($chatMessage->refMessage);
        $this->assertEquals($refMessage->id, $chatMessage->refMessage->id);
    }

    public function test_reviewer_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $reviewer = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Test',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'reviewrequired' => 0,
            'reviewedby' => $reviewer->id,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertNotNull($message->reviewer);
        $this->assertEquals($reviewer->id, $message->reviewer->id);
    }

    public function test_images_relationship(): void
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
            'message' => 'Image message',
            'type' => ChatMessage::TYPE_IMAGE,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        // Create an image linked to this message.
        \App\Models\ChatImage::create([
            'chatid' => $room->id,
            'chatmsgid' => $message->id,
        ]);

        $this->assertEquals(1, $message->images()->count());
    }

    public function test_image_relationship(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
            'created' => now(),
        ]);

        // Create an image.
        $image = \App\Models\ChatImage::create([
            'chatid' => $room->id,
        ]);

        $message = ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Image message',
            'type' => ChatMessage::TYPE_IMAGE,
            'imageid' => $image->id,
            'date' => now(),
            'reviewrequired' => 0,
            'processingrequired' => 0,
            'processingsuccessful' => 1,
            'mailedtoall' => 0,
            'seenbyall' => 0,
            'reviewrejected' => 0,
            'platform' => 1,
        ]);

        $this->assertNotNull($message->image);
        $this->assertEquals($image->id, $message->image->id);
    }

    public function test_type_constants(): void
    {
        $this->assertEquals('Default', ChatMessage::TYPE_DEFAULT);
        $this->assertEquals('System', ChatMessage::TYPE_SYSTEM);
        $this->assertEquals('ModMail', ChatMessage::TYPE_MODMAIL);
        $this->assertEquals('Interested', ChatMessage::TYPE_INTERESTED);
        $this->assertEquals('Promised', ChatMessage::TYPE_PROMISED);
        $this->assertEquals('Reneged', ChatMessage::TYPE_RENEGED);
        $this->assertEquals('Completed', ChatMessage::TYPE_COMPLETED);
        $this->assertEquals('Image', ChatMessage::TYPE_IMAGE);
        $this->assertEquals('Address', ChatMessage::TYPE_ADDRESS);
        $this->assertEquals('Nudge', ChatMessage::TYPE_NUDGE);
        $this->assertEquals('Reminder', ChatMessage::TYPE_REMINDER);
        $this->assertEquals('ReportedUser', ChatMessage::TYPE_REPORTEDUSER);
    }
}
