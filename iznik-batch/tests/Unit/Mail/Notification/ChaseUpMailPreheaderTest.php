<?php

namespace Tests\Unit\Mail\Notification;

use Tests\TestCase;

/**
 * Preheader (mj-preview) tests for the notification chaseup email.
 *
 * The template builds a dynamic preview from the first notification in the
 * batch. These tests render the Blade/MJML view directly (before MJML
 * compilation) and assert the <mj-preview>...</mj-preview> tag contains the
 * expected dynamic text, which is what inbox clients display as the preview
 * line.
 */
class ChaseUpMailPreheaderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Minimal notification stub for use with view()->render().
     * Only the fields touched by the preview closure and the body template
     * are required; everything else defaults to safe fallbacks.
     */
    private function makeNotification(array $overrides = []): array
    {
        return array_merge([
            'type'        => 'CommentOnYourPost',
            'fromname'    => 'Alice',
            'fromimage'   => 'https://example.com/avatar.png',
            'newsfeed'    => [
                'id'      => 1,
                'type'    => 'Message',
                'message' => 'Hello there',
                'replyto' => null,
            ],
            'title'       => null,
            'text'        => null,
            'url'         => null,
            'id'          => 1,
            'timestamp'   => 'just now',
            'trackedUrl'  => 'https://www.ilovefreegle.org/chitchat/1',
        ], $overrides);
    }

    private function renderChaseUpView(array $notifications, int $count = 1): string
    {
        return view('emails.mjml.notification.chaseup', [
            'notifications'  => $notifications,
            'count'          => $count,
            'userSite'       => 'https://www.ilovefreegle.org',
            'chitchatUrl'    => 'https://www.ilovefreegle.org/chitchat',
            'settingsUrl'    => 'https://www.ilovefreegle.org/settings',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe',
            'email'          => 'test@example.com',
        ])->render();
    }

    // -----------------------------------------------------------------------
    // CommentOnYourPost preview
    // -----------------------------------------------------------------------

    public function test_preheader_shows_commenter_name_and_snippet_for_comment_on_post(): void
    {
        $notif = $this->makeNotification([
            'type'     => 'CommentOnYourPost',
            'fromname' => 'Alice',
            'newsfeed' => [
                'id'      => 1,
                'type'    => 'Message',
                'message' => 'This is a wonderful post!',
                'replyto' => null,
            ],
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('<mj-preview>', $html);
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('commented', $html);
        $this->assertStringContainsString('This is a wonderful post!', $html);
    }

    // -----------------------------------------------------------------------
    // CommentOnCommented preview
    // -----------------------------------------------------------------------

    public function test_preheader_shows_replier_name_for_comment_on_commented(): void
    {
        $notif = $this->makeNotification([
            'type'     => 'CommentOnCommented',
            'fromname' => 'Bob',
            'newsfeed' => [
                'id'      => 2,
                'type'    => 'Message',
                'message' => 'Agreed, great item!',
                'replyto' => ['message' => 'Original post'],
            ],
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('Bob', $html);
        $this->assertStringContainsString('replied', $html);
        $this->assertStringContainsString('Agreed, great item!', $html);
    }

    // -----------------------------------------------------------------------
    // LovedPost preview
    // -----------------------------------------------------------------------

    public function test_preheader_shows_lover_name_for_loved_post(): void
    {
        $notif = $this->makeNotification([
            'type'     => 'LovedPost',
            'fromname' => 'Carol',
            'newsfeed' => [
                'id'      => 3,
                'type'    => 'Message',
                'message' => 'OFFER: Vintage table (London)',
                'replyto' => null,
            ],
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('Carol', $html);
        $this->assertStringContainsString('loved your post', $html);
        $this->assertStringContainsString('OFFER: Vintage table (London)', $html);
    }

    // -----------------------------------------------------------------------
    // LovedComment preview
    // -----------------------------------------------------------------------

    public function test_preheader_shows_lover_name_for_loved_comment(): void
    {
        $notif = $this->makeNotification([
            'type'     => 'LovedComment',
            'fromname' => 'Dave',
            'newsfeed' => [
                'id'      => 4,
                'type'    => 'Message',
                'message' => 'That is so kind, thank you!',
                'replyto' => null,
            ],
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('Dave', $html);
        $this->assertStringContainsString('loved your comment', $html);
        $this->assertStringContainsString('That is so kind, thank you!', $html);
    }

    // -----------------------------------------------------------------------
    // Exhort preview
    // -----------------------------------------------------------------------

    public function test_preheader_shows_title_for_exhort_notification(): void
    {
        $notif = $this->makeNotification([
            'type'      => 'Exhort',
            'fromname'  => 'Freegle',
            'title'     => 'Tell us your story!',
            'newsfeed'  => null,
            'trackedUrl' => 'https://www.ilovefreegle.org/story',
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('Tell us your story!', $html);
    }

    // -----------------------------------------------------------------------
    // Fallback preview for unknown types
    // -----------------------------------------------------------------------

    public function test_preheader_shows_generic_fallback_for_unknown_type(): void
    {
        $notif = $this->makeNotification([
            'type'     => 'MembershipApproved',
            'fromname' => 'Eve',
            'newsfeed' => null,
            'url'      => 'Freegle Bristol',
            'trackedUrl' => 'https://www.ilovefreegle.org/chitchat',
        ]);

        $html = $this->renderChaseUpView([$notif]);

        $this->assertStringContainsString('You have a notification from Eve', $html);
    }

    // -----------------------------------------------------------------------
    // Multi-notification: "(and N more)" suffix
    // -----------------------------------------------------------------------

    public function test_preheader_appends_and_more_suffix_for_multiple_notifications(): void
    {
        $notif1 = $this->makeNotification([
            'type'     => 'CommentOnYourPost',
            'fromname' => 'Alice',
            'newsfeed' => [
                'id'      => 1,
                'type'    => 'Message',
                'message' => 'Great sofa!',
                'replyto' => null,
            ],
            'id' => 1,
        ]);
        $notif2 = $this->makeNotification([
            'type'     => 'LovedPost',
            'fromname' => 'Bob',
            'newsfeed' => [
                'id'      => 2,
                'type'    => 'Message',
                'message' => 'Nice item',
                'replyto' => null,
            ],
            'id' => 2,
        ]);
        $notif3 = $this->makeNotification([
            'type'     => 'LovedComment',
            'fromname' => 'Carol',
            'newsfeed' => [
                'id'      => 3,
                'type'    => 'Message',
                'message' => 'Thanks!',
                'replyto' => null,
            ],
            'id' => 3,
        ]);

        $html = $this->renderChaseUpView([$notif1, $notif2, $notif3], 3);

        // Preview is driven by the first notification.
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('commented', $html);
        // And the suffix tells the recipient there are more.
        $this->assertStringContainsString('(and 2 more)', $html);
    }

    // -----------------------------------------------------------------------
    // Empty notifications fallback
    // -----------------------------------------------------------------------

    public function test_preheader_shows_generic_fallback_when_notifications_empty(): void
    {
        $html = $this->renderChaseUpView([], 0);

        $this->assertStringContainsString('You have a notification', $html);
    }
}
