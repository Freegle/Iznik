<?php

namespace Tests\Unit\Models;

use App\Models\Message;
use App\Models\MessageAttachment;
use Tests\TestCase;

class MessageAttachmentModelTest extends TestCase
{
    public function test_attachment_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
        ]);

        $this->assertDatabaseHas('messages_attachments', [
            'id' => $attachment->id,
            'msgid' => $message->id,
        ]);
    }

    public function test_message_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
        ]);

        $this->assertInstanceOf(Message::class, $attachment->message);
        $this->assertEquals($message->id, $attachment->message->id);
    }

    public function test_is_primary_returns_true_for_primary_attachment(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'primary' => true,
        ]);

        $this->assertTrue($attachment->isPrimary());
    }

    public function test_is_primary_returns_false_for_non_primary_attachment(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'primary' => false,
        ]);

        $this->assertFalse($attachment->isPrimary());
    }

    public function test_is_primary_returns_false_when_primary_not_set(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Not setting primary - it should default to false.
        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
        ]);

        $this->assertFalse($attachment->isPrimary());
    }

    public function test_archived_is_cast_to_boolean(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'archived' => 1,
        ]);

        $attachment->refresh();

        $this->assertTrue($attachment->archived);
    }

    public function test_rotated_is_cast_to_boolean(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'rotated' => 1,
        ]);

        $attachment->refresh();

        $this->assertTrue($attachment->rotated);
    }

    public function test_primary_is_cast_to_boolean(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $attachment = MessageAttachment::create([
            'msgid' => $message->id,
            'primary' => 1,
        ]);

        $attachment->refresh();

        $this->assertTrue($attachment->primary);
    }
}
