<?php

namespace Tests\Unit\Mail;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\MessageGroup;
use Tests\TestCase;

class MessageMailTest extends TestCase
{
    public function test_deadline_reached_can_be_constructed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertInstanceOf(DeadlineReached::class, $mail);
    }

    public function test_deadline_reached_has_message(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertSame($message->id, $mail->message->id);
    }

    public function test_deadline_reached_has_user(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_deadline_reached_has_correct_urls(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);

        $this->assertStringContainsString('/mypost/' . $message->id . '/extend', $mail->extendUrl);
        $this->assertStringContainsString('/mypost/' . $message->id . '/completed', $mail->completedUrl);
        $this->assertStringContainsString('/mypost/' . $message->id . '/withdraw', $mail->withdrawUrl);
    }

    public function test_deadline_reached_offer_has_taken_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
        ]);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals(Message::OUTCOME_TAKEN, $mail->outcomeType);
    }

    public function test_deadline_reached_wanted_has_received_outcome(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_WANTED,
        ]);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals(Message::OUTCOME_RECEIVED, $mail->outcomeType);
    }

    public function test_deadline_reached_build_returns_self(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $mail = new DeadlineReached($message, $user);
        $result = $mail->build();

        $this->assertInstanceOf(DeadlineReached::class, $result);
    }

    public function test_deadline_reached_has_correct_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Test Item (Location)',
        ]);

        $mail = new DeadlineReached($message, $user);
        $envelope = $mail->envelope();

        $this->assertEquals('Deadline reached: OFFER: Test Item (Location)', $envelope->subject);
    }

    public function test_deadline_reached_tracking_uses_recipients_group_for_cross_post(): void
    {
        // Message on groupA and groupB; recipient is a member of groupB only.
        // Tracking groupid must reference groupB, not the arbitrary first group.
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $groupB);

        $message = $this->createTestMessage($user, $groupA);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);
        $message = Message::with('groups')->find($message->id);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals($groupB->id, $mail->getTracking()->groupid);
        $this->assertNotEquals($groupA->id, $mail->getTracking()->groupid);
    }

    public function test_deadline_reached_falls_back_to_first_group_when_no_membership_overlap(): void
    {
        // Defensive: if the recipient is somehow not a member of any of the message's
        // groups, fall back to groups->first() rather than returning null.
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        $user = $this->createTestUser();
        // No memberships at all — complete mismatch.

        $message = $this->createTestMessage($user, $groupA);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $groupB->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);
        $message = Message::with('groups')->find($message->id);

        $mail = new DeadlineReached($message, $user);

        // Should not be null — falls back to groups->first().
        $this->assertNotNull($mail->getTracking()->groupid);
    }
}
