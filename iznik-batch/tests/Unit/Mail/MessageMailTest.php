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

    public function test_deadline_reached_names_the_origin_group_not_a_rippled_copy(): void
    {
        // Rippling adds messages_groups rows (rippled_in=1, arrival=ripple time)
        // and auto-joins the poster to those groups, so the old "recipient's
        // membership with the most recent arrival" pick told a poster their
        // deadline was on whichever distant group the post last rippled into.
        $user = $this->createTestUser();
        $origin = $this->createTestGroup();
        $rippled = $this->createTestGroup();
        $this->createMembership($user, $origin);
        $this->createMembership($user, $rippled);

        $message = $this->createTestMessage($user, $origin, [
            'arrival' => now()->subDays(2),
        ]);
        // A rippled-in copy with a NEWER arrival - the bait the old pick took.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $rippled->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
            'rippled_in' => 1,
        ]);

        $mail = new DeadlineReached($message->fresh(), $user);
        $mail->build();

        $mjmlData = (new \ReflectionProperty($mail, 'mjmlData'))->getValue($mail);
        $this->assertEquals($origin->nameshort, $mjmlData['groupName']);
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

    public function test_deadline_reached_tracking_uses_origin_group_for_cross_post(): void
    {
        // Message posted to groupA, also on groupB; recipient (the poster) is a
        // member of groupB only. Tracking must reference the ORIGIN group (where
        // they posted), not whichever copy their memberships happen to match -
        // under rippling that heuristic picked the most-recently-rippled group.
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
            'rippled_in' => 1,
        ]);
        $message = Message::with('groups')->find($message->id);

        $mail = new DeadlineReached($message, $user);

        $this->assertEquals($groupA->id, $mail->getTracking()->groupid);
        $this->assertNotEquals($groupB->id, $mail->getTracking()->groupid);
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
            'isOffer'      => true,
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
            'repostWhen'     => 'soon',
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
            'promisedTo'     => 'Dave',
            'completedUrl'   => 'https://example.com/completed',
            'repostUrl'      => 'https://example.com/repost',
            'withdrawUrl'    => 'https://example.com/withdraw',
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

    // ─── Wording ──────────────────────────────────────────────────────────────

    /** The data a mailable's build() hands to its MJML view. */
    private function mjmlData(\App\Mail\MjmlMailable $mail): array
    {
        $mail->build();
        $prop = new \ReflectionProperty(\App\Mail\MjmlMailable::class, 'mjmlData');
        $prop->setAccessible(true);

        return $prop->getValue($mail);
    }

    private function renderDeadline(string $type, ?string $deadlineDate = '28 October'): string
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'type'    => $type,
            'subject' => ($type === Message::TYPE_OFFER ? 'OFFER' : 'WANTED') . ': Thing (Town)',
        ]);

        $mail = new DeadlineReached($message, $user);

        return view('emails.mjml.message.deadline-reached', [
            'post'         => $message,
            'user'         => $user,
            'outcomeType'  => $mail->outcomeType,
            'isOffer'      => $type === Message::TYPE_OFFER,
            'deadlineDate' => $deadlineDate,
            'groupName'    => $group->nameshort,
            'extendUrl'    => 'https://example.com/extend',
            'completedUrl' => 'https://example.com/completed',
            'withdrawUrl'  => 'https://example.com/withdraw',
            'settingsUrl'  => 'https://example.com/settings',
            'email'        => $user->email_preferred,
        ])->render();
    }

    public function test_deadline_reached_offer_keeps_giving_wording(): void
    {
        $html = $this->renderDeadline(Message::TYPE_OFFER);

        $this->assertStringContainsString('find someone for your item', $html);
        $this->assertStringContainsString('found a new home', $html);
        $this->assertStringContainsString('give it away', $html);
        $this->assertStringContainsString('Mark as Taken', $html);
    }

    public function test_deadline_reached_wanted_uses_looking_wording(): void
    {
        $html = $this->renderDeadline(Message::TYPE_WANTED);

        $this->assertStringNotContainsString('find someone for your item', $html);
        $this->assertStringNotContainsString('found a new home', $html);
        $this->assertStringNotContainsString('give it away', $html);
        $this->assertStringContainsString('find what you are looking for', $html);
        $this->assertStringContainsString('Tell us you have received it', $html);
        $this->assertStringContainsString('no longer looking for it', $html);
        $this->assertStringContainsString('Mark as Received', $html);
    }

    public function test_deadline_reached_gives_the_deadline_date(): void
    {
        $html = $this->renderDeadline(Message::TYPE_OFFER, '28 October');

        $this->assertStringContainsString('reached the deadline you set, 28 October.', $html);
    }

    public function test_deadline_reached_without_a_date_keeps_the_plain_wording(): void
    {
        $html = $this->renderDeadline(Message::TYPE_OFFER, null);

        $this->assertStringContainsString('reached the deadline you set.', $html);
        $this->assertStringNotContainsString('you set,', $html);
    }

    public function test_deadline_reached_build_passes_the_deadline_as_day_and_month(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        $message->deadline = '2026-10-28';

        $this->assertSame('28 October', $this->mjmlData(new DeadlineReached($message, $user))['deadlineDate']);
    }

    public function test_deadline_reached_build_passes_is_offer(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, ['type' => Message::TYPE_WANTED]);

        $mail = new DeadlineReached($message, $user);

        $this->assertFalse($this->mjmlData($mail)['isOffer']);
    }

    private function renderPromised(?string $promisedTo): string
    {
        $mail = new \App\Mail\Message\ChaseUpPromised(
            messageId: 1,
            messageSubject: 'OFFER: Red Bicycle (Edinburgh)',
            messageType: Message::TYPE_OFFER,
            userId: 1,
            userName: 'Carol',
            userEmail: 'carol@example.com',
            promisedTo: $promisedTo,
        );

        return view('emails.mjml.message.chaseup-promised', $this->mjmlData($mail))->render();
    }

    public function test_chaseup_promised_names_who_it_was_promised_to(): void
    {
        $html = $this->renderPromised('Dave');

        $this->assertStringContainsString('You promised this to Dave. Has it been collected?', $html);
        $this->assertStringNotContainsString('My Posts', $html);
        $this->assertStringContainsString('Repost', $html);
        $this->assertStringContainsString('Withdraw', $html);
    }

    public function test_chaseup_promised_falls_back_to_someone(): void
    {
        $this->assertStringContainsString(
            'You promised this to someone. Has it been collected?',
            $this->renderPromised(null)
        );
    }

    private function renderWarning(?string $repostDate): string
    {
        $mail = new \App\Mail\Message\AutoRepostWarning(
            messageId: 1,
            messageSubject: 'OFFER: Garden Tools (Manchester)',
            messageType: Message::TYPE_OFFER,
            userId: 1,
            userName: 'Alice',
            userEmail: 'alice@example.com',
            repostDate: $repostDate,
        );

        return view('emails.mjml.message.autorepost-warning', $this->mjmlData($mail))->render();
    }

    public function test_autorepost_warning_gives_the_repost_day(): void
    {
        $html = $this->renderWarning('2026-10-31');

        $this->assertStringContainsString('repost your post', $html);
        $this->assertStringContainsString('on Saturday 31 October', $html);
        $this->assertStringNotContainsString('your message', $html);
    }

    public function test_autorepost_warning_preheader_gives_the_repost_day(): void
    {
        $this->assertStringContainsString(
            '<mj-preview>Will repost on Saturday 31 October: OFFER: Garden Tools (Manchester)',
            $this->renderWarning('2026-10-31')
        );
    }

    public function test_autorepost_warning_preheader_says_soon_without_a_date(): void
    {
        $this->assertStringContainsString(
            '<mj-preview>Will repost soon: OFFER: Garden Tools (Manchester)',
            $this->renderWarning(null)
        );
    }

    public function test_autorepost_warning_says_soon_without_a_date(): void
    {
        $html = $this->renderWarning(null);

        $this->assertStringContainsString('</strong> soon,', $html);
    }

    public function test_chaseup_says_post_not_message(): void
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

        $this->assertStringContainsString('happened to your post', $html);
        $this->assertStringNotContainsString('your message', $html);
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
