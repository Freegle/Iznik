<?php

namespace Tests\Unit\Mail\Newsfeed;

use App\Mail\Newsfeed\NewsfeedModNotifMail;
use Tests\TestCase;

/**
 * AssertFlip failing test for the broken chitchat link in NewsfeedModNotifMail.
 *
 * Bug (topic 9676): the "View" and "View all chitchat" buttons in the moderator
 * chitchat notification email link to https://modtools.org/chitchat, but the
 * Modtools application has no /chitchat route — clicking the link produces a 404.
 *
 * The legacy iznik-server code correctly linked to https://www.ilovefreegle.org/chitchat
 * (the user site), where the /chitchat page exists. The batch migration accidentally
 * changed the destination to the mod site, which has no such page.
 *
 * AssertFlip Step 2 — inverted assertions that FAIL on buggy code, PASS after the fix.
 * The fix: change chitchatUrl to use config('freegle.sites.user') instead of
 * config('freegle.sites.mod').
 */
class NewsfeedModNotifMailTest extends TestCase
{
    private function makePosts(): array
    {
        return [
            [
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

        // The Modtools app has no /chitchat route: clicking the link returns a 404.
        // After the fix, the button must link to the user site where /chitchat exists.
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

    public function test_view_all_chitchat_button_uses_user_site(): void
    {
        $mail = new NewsfeedModNotifMail(
            recipientEmail: 'mod@example.com',
            posts: $this->makePosts(),
        );

        $html = $mail->render();

        $userSite = config('freegle.sites.user');

        // Both the per-post "View" buttons and the "View all chitchat" button
        // must link to the user site — the only place where /chitchat exists.
        $this->assertStringContainsString(
            $userSite . '/chitchat',
            $html,
            '"View all chitchat" button must link to ' . $userSite . '/chitchat'
        );
    }
}
