<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use Tests\TestCase;

class MessageGroupModelTest extends TestCase
{
    public function test_message_group_can_be_created(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (TestLocation)',
            'textbody' => 'Test message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
        ]);

        $messageGroup = MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $this->assertDatabaseHas('messages_groups', [
            'msgid' => $message->id,
            'groupid' => $group->id,
        ]);
    }

    public function test_message_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertInstanceOf(Message::class, $messageGroup->message);
        $this->assertEquals($message->id, $messageGroup->message->id);
    }

    public function test_group_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertInstanceOf(Group::class, $messageGroup->group);
        $this->assertEquals($group->id, $messageGroup->group->id);
    }

    public function test_approved_by_relationship(): void
    {
        $user = $this->createTestUser();
        $approver = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        MessageGroup::where('msgid', $message->id)
            ->update(['approvedby' => $approver->id]);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertInstanceOf(User::class, $messageGroup->approvedBy);
        $this->assertEquals($approver->id, $messageGroup->approvedBy->id);
    }

    public function test_approved_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $approvedMessages = MessageGroup::approved()->get();

        $this->assertTrue($approvedMessages->contains('msgid', $message->id));
    }

    public function test_pending_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Update to pending.
        MessageGroup::where('msgid', $message->id)
            ->update(['collection' => MessageGroup::COLLECTION_PENDING]);

        $pendingMessages = MessageGroup::pending()->get();

        $this->assertTrue($pendingMessages->contains('msgid', $message->id));
    }

    public function test_not_deleted_scope(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $notDeletedMessages = MessageGroup::notDeleted()->get();

        $this->assertTrue($notDeletedMessages->contains('msgid', $message->id));
    }

    public function test_not_deleted_scope_excludes_deleted(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Mark as deleted.
        MessageGroup::where('msgid', $message->id)
            ->update(['deleted' => 1]);

        $notDeletedMessages = MessageGroup::notDeleted()->get();

        $this->assertFalse($notDeletedMessages->contains('msgid', $message->id));
    }

    public function test_is_approved_returns_true_for_approved_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertTrue($messageGroup->isApproved());
    }

    public function test_is_approved_returns_false_for_pending_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        MessageGroup::where('msgid', $message->id)
            ->update(['collection' => MessageGroup::COLLECTION_PENDING]);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertFalse($messageGroup->isApproved());
    }

    public function test_collection_constants(): void
    {
        $this->assertEquals('Incoming', MessageGroup::COLLECTION_INCOMING);
        $this->assertEquals('Pending', MessageGroup::COLLECTION_PENDING);
        $this->assertEquals('Approved', MessageGroup::COLLECTION_APPROVED);
        $this->assertEquals('Spam', MessageGroup::COLLECTION_SPAM);
        $this->assertEquals('QueuedYahooUser', MessageGroup::COLLECTION_QUEUED_YAHOO);
        $this->assertEquals('Rejected', MessageGroup::COLLECTION_REJECTED);
        $this->assertEquals('QueuedUser', MessageGroup::COLLECTION_QUEUED_USER);
    }

    public function test_arrival_is_cast_to_datetime(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertInstanceOf(\Carbon\Carbon::class, $messageGroup->arrival);
    }

    public function test_deleted_is_cast_to_boolean(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        MessageGroup::where('msgid', $message->id)
            ->update(['deleted' => 1]);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertTrue($messageGroup->deleted);
    }

    public function test_autoreposts_is_cast_to_integer(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        MessageGroup::where('msgid', $message->id)
            ->update(['autoreposts' => '5']);

        $messageGroup = MessageGroup::where('msgid', $message->id)->first();

        $this->assertIsInt($messageGroup->autoreposts);
        $this->assertEquals(5, $messageGroup->autoreposts);
    }
}
