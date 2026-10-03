<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * Preheader (mj-preview) tests for chat email templates:
 * - chat/chaseup-mods.blade.php
 * - chat/spam-warning.blade.php
 *
 * Each test renders the Blade view (pre-MJML-compilation) and asserts the
 * dynamic text appears inside the <mj-preview>…</mj-preview> element.
 */
class ChatPreheaderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // chat/chaseup-mods.blade.php
    // -----------------------------------------------------------------------

    /**
     * Preheader includes member name and group name.
     */
    public function test_chaseup_mods_preheader_shows_member_and_group_name(): void
    {
        $html = view('emails.mjml.chat.chaseup-mods', [
            'groupName'   => 'Freegle Leeds',
            'memberName'  => 'Frank Member',
            'memberEmail' => 'frank@example.com',
            'textSummary' => 'Hello, I need help.',
            'chatUrl'     => 'https://modtools.org/chats/42',
            'email'       => 'mods@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Frank Member on Freegle Leeds - member conversation needs a reply</mj-preview>',
            $html
        );
    }

    // -----------------------------------------------------------------------
    // chat/spam-warning.blade.php
    // -----------------------------------------------------------------------

    /**
     * Preheader includes the spammer name and message subject when a subject is provided.
     */
    public function test_spam_warning_preheader_with_subject_shows_spammer_and_subject(): void
    {
        $html = view('emails.mjml.chat.spam-warning', [
            'spammerName'    => 'Suspicious Pete',
            'messageSubject' => 'OFFER: Free laptop (London)',
            'siteName'       => 'Freegle',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Be careful - you have been talking to &quot;Suspicious Pete&quot; about: OFFER: Free laptop (London)</mj-preview>',
            $html
        );
    }

    /**
     * Preheader omits the "about:" clause when no message subject is supplied.
     */
    public function test_spam_warning_preheader_without_subject_omits_about_clause(): void
    {
        $html = view('emails.mjml.chat.spam-warning', [
            'spammerName'    => 'Suspicious Pete',
            'messageSubject' => null,
            'siteName'       => 'Freegle',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Be careful - you have been talking to &quot;Suspicious Pete&quot;</mj-preview>',
            $html
        );
        $this->assertStringNotContainsString('about:', $html);
    }
}
