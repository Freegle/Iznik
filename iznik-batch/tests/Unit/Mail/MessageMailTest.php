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

    // ─── Preheader (mj-preview) assertions ────────────────────────────────────

    public function test_deadline_reached_preheader_contains_post_subject(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Vintage Sofa (Bristol)',
        ]);

        $html = view('emails.mjml.message.deadline-reached', [
            'post'         => $message,
            'user'         => $user,
            'outcomeType'  => 'taken',
            'groupName'    => $group->nameshort,
            'extendUrl'    => 'https://example.com/extend',
            'completedUrl' => 'https://example.com/completed',
            'withdrawUrl'  => 'https://example.com/withdraw',
            'settingsUrl'  => 'https://example.com/settings',
            'email'        => $user->email_preferred,
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Deadline reached: OFFER: Vintage Sofa (Bristol)',
            $html,
            'deadline-reached preheader must contain the post subject'
        );
    }

    public function test_autorepost_warning_preheader_contains_message_subject(): void
    {
        $html = view('emails.mjml.message.autorepost-warning', [
            'messageSubject' => 'OFFER: Garden Tools (Manchester)',
            'userName'       => 'Alice',
            'outcomeType'    => 'taken',
            'isOffer'        => true,
            'completedUrl'   => 'https://example.com/completed',
            'withdrawUrl'    => 'https://example.com/withdraw',
            'promiseUrl'     => 'https://example.com/promise',
            'settingsUrl'    => 'https://example.com/settings',
            'email'          => 'alice@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Will repost soon: OFFER: Garden Tools (Manchester)',
            $html,
            'autorepost-warning preheader must contain the message subject'
        );
    }

    public function test_chaseup_preheader_contains_message_subject(): void
    {
        $html = view('emails.mjml.message.chaseup', [
            'messageSubject' => 'OFFER: Oak Bookcase (Leeds)',
            'userName'       => 'Bob',
            'outcomeType'    => 'taken',
            'repostUrl'      => 'https://example.com/repost',
            'completedUrl'   => 'https://example.com/completed',
            'withdrawUrl'    => 'https://example.com/withdraw',
            'chatsUrl'       => 'https://example.com/chats',
            'myPostsUrl'     => 'https://example.com/myposts',
            'settingsUrl'    => 'https://example.com/settings',
            'email'          => 'bob@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>What happened to: OFFER: Oak Bookcase (Leeds)',
            $html,
            'chaseup preheader must contain the message subject'
        );
    }

    public function test_chaseup_promised_preheader_contains_message_subject_and_suffix(): void
    {
        $html = view('emails.mjml.message.chaseup-promised', [
            'messageSubject' => 'OFFER: Red Bicycle (Edinburgh)',
            'userName'       => 'Carol',
            'outcomeType'    => 'taken',
            'completedUrl'   => 'https://example.com/completed',
            'repostUrl'      => 'https://example.com/repost',
            'withdrawUrl'    => 'https://example.com/withdraw',
            'myPostsUrl'     => 'https://example.com/myposts',
            'settingsUrl'    => 'https://example.com/settings',
            'email'          => 'carol@example.com',
        ])->render();

        $this->assertStringContainsString(
            'OFFER: Red Bicycle (Edinburgh)',
            $html,
            'chaseup-promised preheader must contain the message subject'
        );
        $this->assertStringContainsString(
            'has it been collected?',
            $html,
            'chaseup-promised preheader must include the "has it been collected?" suffix'
        );
    }

    public function test_mod_std_message_preheader_contains_body_snippet(): void
    {
        $html = view('emails.mjml.message.mod-std-message', [
            'body'           => 'Your post has been approved by the moderators. Welcome to Freegle!',
            'modName'        => 'Mod Dave',
            'groupName'      => 'Freegle Sheffield',
            'messageSubject' => 'OFFER: Dining Table (Sheffield)',
            'userSite'       => 'https://www.ilovefreegle.org',
            'email'          => 'member@example.com',
        ])->render();

        $this->assertStringContainsString(
            'Your post has been approved by the moderators',
            $html,
            'mod-std-message preheader must contain the start of the body text'
        );
    }
}
