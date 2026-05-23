<?php

namespace Tests\Unit\Mail\Newsfeed;

use App\Mail\Newsfeed\NewsfeedModNotifMail;
use Tests\TestCase;

/**
 * Tests for NewsfeedModNotifMail link correctness (V1 parity).
 *
 * Bug (topic 9676): the "View" and "View all chitchat" buttons in the moderator
 * chitchat notification email link to https://modtools.org/chitchat, but the
 * Modtools application has no /chitchat route — clicking the link produces a 404.
 *
 * V1 parity: iznik-server Newsfeed.php links to /chitchat/{id} (specific post),
 * not just /chitchat. Each per-post "View" button must use the post ID so mods
 * land directly on the reported post rather than the general chitchat listing.
 */
class NewsfeedModNotifMailTest extends TestCase
{
    private function makePosts(): array
    {
        return [
            [
                'id'      => 42,
                'label'   => 'Post',
                'preview' => 'Anyone know a good plumber nearby?',
                'added'   => '2026-05-22 10:30:00',
            ],
        ];
    }

    public function test_chitchat_link_goes_to_user_site_not_modtools(): void
    {
        $mail = new NewsfeedModNotifMail(
            recipientEmail: 'mod@example.com',
            posts: $this->makePosts(),
        );

        $html = $mail->render();

        $modSite  = config('freegle.sites.mod');   // https://modtools.org
        $userSite = config('freegle.sites.user');  // https://www.ilovefreegle.org

        $this->assertStringNotContainsString(
            $modSite . '/chitchat',
            $html,
            'Chitchat link must not go to ' . $modSite . '/chitchat — that route does not exist in the Modtools app'
        );

        $this->assertStringContainsString(
            $userSite . '/chitchat',
            $html,
            'Chitchat link must go to ' . $userSite . '/chitchat where the page actually exists'
        );
    }

    public function test_per_post_view_button_links_to_specific_post(): void
    {
        $mail = new NewsfeedModNotifMail(
            recipientEmail: 'mod@example.com',
            posts: $this->makePosts(),
        );

        $html = $mail->render();

        $userSite = config('freegle.sites.user');

        // V1 parity: each "View" button must link to /chitchat/{id} so mods
        // land on the specific post, not the general listing.
        $this->assertStringContainsString(
            $userSite . '/chitchat/42',
            $html,
            'Per-post "View" button must link to /chitchat/{id} matching V1 behaviour'
        );
    }

    public function test_view_all_chitchat_button_links_to_general_listing(): void
    {
        $mail = new NewsfeedModNotifMail(
            recipientEmail: 'mod@example.com',
            posts: $this->makePosts(),
        );

        $html = $mail->render();

        $userSite = config('freegle.sites.user');

        // The "View all chitchat" footer button goes to the general listing.
        $this->assertStringContainsString(
            $userSite . '/chitchat',
            $html,
            '"View all chitchat" button must link to ' . $userSite . '/chitchat'
        );
    }
}
