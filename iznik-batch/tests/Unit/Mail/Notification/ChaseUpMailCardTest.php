<?php

namespace Tests\Unit\Mail\Notification;

use Tests\TestCase;

/**
 * Card tests for the notification chaseup email.
 *
 * The preheader is covered by ChaseUpMailPreheaderTest. These tests are about
 * the visible cards: each notification type has its own wording, its own call
 * to action, and either carries a quoted excerpt or does not. The preheader is
 * stripped before asserting, so a pass here means the text really is in a card
 * rather than only in the inbox preview line.
 */
class ChaseUpMailCardTest extends TestCase
{
    private function makeNotification(array $overrides = []): array
    {
        return array_merge([
            'type'       => 'CommentOnYourPost',
            'fromname'   => 'Alice',
            'fromimage'  => 'https://example.com/avatar.png',
            'newsfeed'   => [
                'id'      => 1,
                'type'    => 'Message',
                'message' => 'Hello there',
                'replyto' => null,
            ],
            'title'      => null,
            'text'       => null,
            'url'        => null,
            'id'         => 1,
            'timestamp'  => 'Mon, 1st September 9:15am',
            'trackedUrl' => 'https://www.ilovefreegle.org/chitchat/1',
        ], $overrides);
    }

    /**
     * Render the body only. Everything from <mj-preview> is dropped so an
     * assertion cannot be satisfied by the preheader.
     */
    private function renderCards(array $notifications, ?int $count = null): string
    {
        $html = view('emails.mjml.notification.chaseup', [
            'notifications'  => $notifications,
            'count'          => $count ?? count($notifications),
            'userSite'       => 'https://www.ilovefreegle.org',
            'chitchatUrl'    => 'https://www.ilovefreegle.org/chitchat',
            'settingsUrl'    => 'https://www.ilovefreegle.org/settings',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe',
            'email'          => 'test@example.com',
        ])->render();

        return preg_replace('#<mj-preview>.*?</mj-preview>#s', '', $html);
    }

    // -----------------------------------------------------------------------
    // Intro card
    // -----------------------------------------------------------------------

    public function test_intro_card_is_singular_for_one_notification(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        $this->assertStringContainsString('You have 1 notification', $html);
        $this->assertStringNotContainsString('You have 1 notifications', $html);
    }

    public function test_intro_card_is_plural_for_several_notifications(): void
    {
        $html = $this->renderCards([
            $this->makeNotification(['id' => 1]),
            $this->makeNotification(['id' => 2]),
        ]);

        $this->assertStringContainsString('You have 2 notifications', $html);
    }

    // -----------------------------------------------------------------------
    // Per-type wording
    // -----------------------------------------------------------------------

    public function test_comment_on_your_post_card_names_the_commenter_and_quotes_them(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'CommentOnYourPost',
            'fromname' => 'Alice',
            'newsfeed' => [
                'id'      => 7,
                'type'    => 'Message',
                'message' => 'This is a wonderful post!',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringContainsString('<strong>Alice</strong>', $html);
        $this->assertStringContainsString('commented on your post', $html);
        $this->assertStringContainsString('This is a wonderful post!', $html);
        $this->assertStringContainsString('View thread', $html);
    }

    public function test_comment_on_commented_card_names_the_thread_it_replied_on(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'CommentOnCommented',
            'fromname' => 'Bob',
            'newsfeed' => [
                'id'      => 8,
                'type'    => 'Message',
                'message' => 'Agreed, great item!',
                'replyto' => ['id' => 3, 'message' => 'Anyone got a spare kettle?'],
            ],
        ])]);

        $this->assertStringContainsString('<strong>Bob</strong>', $html);
        $this->assertStringContainsString('replied on', $html);
        $this->assertStringContainsString('Anyone got a spare kettle?', $html);
        $this->assertStringContainsString('Agreed, great item!', $html);
    }

    public function test_comment_on_commented_card_copes_with_a_missing_parent_message(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'CommentOnCommented',
            'fromname' => 'Bob',
            'newsfeed' => [
                'id'      => 8,
                'type'    => 'Message',
                'message' => 'Agreed!',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringContainsString('replied on', $html);
        $this->assertStringContainsString('your thread', $html);
    }

    public function test_loved_post_card_quotes_the_post(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'LovedPost',
            'fromname' => 'Carol',
            'newsfeed' => [
                'id'      => 9,
                'type'    => 'Message',
                'message' => 'OFFER: Vintage table (London)',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringContainsString('<strong>Carol</strong>', $html);
        $this->assertStringContainsString('loved your post', $html);
        $this->assertStringContainsString('OFFER: Vintage table (London)', $html);
    }

    public function test_loved_noticeboard_post_card_has_no_quote(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'LovedPost',
            'fromname' => 'Carol',
            'newsfeed' => [
                'id'      => 10,
                'type'    => 'Noticeboard',
                'message' => 'ignored for noticeboards',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringContainsString('loved your noticeboard post', $html);
        $this->assertStringNotContainsString('ignored for noticeboards', $html);
        $this->assertStringNotContainsString('border-left', $html);
    }

    public function test_loved_comment_card_quotes_the_comment(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'LovedComment',
            'fromname' => 'Dave',
            'newsfeed' => [
                'id'      => 11,
                'type'    => 'Message',
                'message' => 'That is so kind, thank you!',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringContainsString('<strong>Dave</strong>', $html);
        $this->assertStringContainsString('loved your comment', $html);
        $this->assertStringContainsString('That is so kind, thank you!', $html);
    }

    public function test_exhort_card_leads_with_its_own_title_and_its_own_call_to_action(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'Exhort',
            'fromname' => 'Freegle',
            'title'    => 'Tell us your story!',
            'text'     => 'We love hearing how it went.',
            'newsfeed' => null,
            'url'      => '/stories',
        ])]);

        $this->assertStringContainsString('<strong>Tell us your story!</strong>', $html);
        $this->assertStringContainsString('We love hearing how it went.', $html);
        $this->assertStringContainsString('Take a look', $html);
        $this->assertStringNotContainsString('View thread', $html);
    }

    public function test_membership_pending_card_says_it_is_waiting(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'MembershipPending',
            'fromname' => 'Freegle',
            'newsfeed' => null,
            'url'      => 'Edinburgh Freegle',
        ])]);

        $this->assertStringContainsString('Your application to Edinburgh Freegle needs approval', $html);
        $this->assertStringContainsString('Go to Freegle', $html);
    }

    public function test_membership_approved_card_says_they_are_in(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'MembershipApproved',
            'fromname' => 'Freegle',
            'newsfeed' => null,
            'url'      => 'Edinburgh Freegle',
        ])]);

        $this->assertStringContainsString('has been approved', $html);
        $this->assertStringContainsString('Edinburgh Freegle', $html);
        $this->assertStringContainsString('Go to Freegle', $html);
    }

    public function test_membership_rejected_card_says_so_plainly(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'MembershipRejected',
            'fromname' => 'Freegle',
            'newsfeed' => null,
            'url'      => 'Edinburgh Freegle',
        ])]);

        $this->assertStringContainsString('Sorry, your application to Edinburgh Freegle', $html);
        $this->assertStringContainsString('Go to Freegle', $html);
    }

    public function test_unknown_type_still_gets_a_usable_card(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'type'     => 'SomethingWeHaveNotWrittenYet',
            'fromname' => 'Zed',
            'newsfeed' => null,
        ])]);

        $this->assertStringContainsString('You have a notification from Zed', $html);
        $this->assertStringContainsString('Go to Freegle', $html);
    }

    // -----------------------------------------------------------------------
    // Layout
    // -----------------------------------------------------------------------

    public function test_card_shows_the_sender_avatar_as_a_circle(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'fromimage' => 'https://example.com/avatars/alice.png',
        ])]);

        $this->assertStringContainsString('https://example.com/avatars/alice.png', $html);
        $this->assertStringContainsString('border-radius="50%"', $html);
    }

    /**
     * The brand band carries the Freegle logo, so an absent avatar cannot be
     * checked by looking for any image at all. The round mask is the thing that
     * only an avatar has.
     */
    public function test_card_omits_the_avatar_when_there_is_none(): void
    {
        $html = $this->renderCards([$this->makeNotification(['fromimage' => null])]);

        $this->assertStringNotContainsString('border-radius="50%"', $html);
        $this->assertStringContainsString('commented on your post', $html);
    }

    public function test_brand_band_carries_the_logo(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        $this->assertStringContainsString(config('freegle.branding.logo_url'), $html);
        $this->assertStringContainsString('Your notifications', $html);
    }

    /**
     * MJML gives a column with no width an equal share of the section, not the
     * leftover next to a fixed-width sibling, so an unsized text column here
     * would render at 300px and wrap after a few words. Both columns must
     * therefore say how wide they are, and together they must fill the 600px
     * body rather than overflow it.
     */
    public function test_both_card_columns_are_sized_explicitly(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        preg_match_all('/<mj-column width="(\d+)px"/', $html, $matches);

        $this->assertCount(2, $matches[1], 'Both card columns should carry a pixel width');
        $this->assertSame(600, array_sum(array_map('intval', $matches[1])));
    }

    /**
     * The avatar column has to be wider than the picture it holds, or the text
     * next to it starts the moment the picture ends and the card looks cramped.
     */
    public function test_avatar_column_leaves_space_before_the_text(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        preg_match('/<mj-column width="(\d+)px" vertical-align="top">\s*<mj-image[^>]*width="(\d+)px"[^>]*padding="0 0 0 (\d+)px"/s', $html, $m);

        $this->assertNotEmpty($m, 'Avatar column and image should be measurable');

        $gap = (int) $m[1] - (int) $m[2] - (int) $m[3];
        $this->assertGreaterThanOrEqual(12, $gap, 'Avatar needs clear space before the text column');
    }

    public function test_card_shows_the_timestamp(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'timestamp' => 'Mon, 1st September 9:15am',
        ])]);

        $this->assertStringContainsString('Mon, 1st September 9:15am', $html);
    }

    public function test_card_links_to_the_tracked_url(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'trackedUrl' => 'https://www.ilovefreegle.org/chitchat/42?src=notif',
        ])]);

        $this->assertStringContainsString('href="https://www.ilovefreegle.org/chitchat/42?src=notif"', $html);
    }

    public function test_message_text_is_escaped(): void
    {
        $html = $this->renderCards([$this->makeNotification([
            'newsfeed' => [
                'id'      => 12,
                'type'    => 'Message',
                'message' => '<script>alert(1)</script>',
                'replyto' => null,
            ],
        ])]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_every_notification_gets_its_own_card(): void
    {
        $html = $this->renderCards([
            $this->makeNotification(['id' => 1, 'fromname' => 'Alice']),
            $this->makeNotification(['id' => 2, 'fromname' => 'Bob']),
            $this->makeNotification(['id' => 3, 'fromname' => 'Carol']),
        ]);

        $this->assertSame(3, substr_count($html, 'commented on your post'));
        $this->assertStringContainsString('<strong>Alice</strong>', $html);
        $this->assertStringContainsString('<strong>Bob</strong>', $html);
        $this->assertStringContainsString('<strong>Carol</strong>', $html);
    }

    // -----------------------------------------------------------------------
    // Closing block and footer
    // -----------------------------------------------------------------------

    public function test_closing_button_points_at_chitchat(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        $this->assertStringContainsString('<mj-button href="https://www.ilovefreegle.org/chitchat"', $html);
        $this->assertStringContainsString('See what', $html);
    }

    public function test_footer_carries_the_unsubscribe_and_settings_links(): void
    {
        $html = $this->renderCards([$this->makeNotification()]);

        $this->assertStringContainsString('https://www.ilovefreegle.org/unsubscribe', $html);
        $this->assertStringContainsString('https://www.ilovefreegle.org/settings', $html);
        $this->assertStringContainsString('test@example.com', $html);
    }

    public function test_renders_with_no_notifications_at_all(): void
    {
        $html = $this->renderCards([], 0);

        $this->assertStringContainsString('You have 0 notifications', $html);
        $this->assertStringContainsString('See what', $html);
    }
}
