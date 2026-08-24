<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * Preheader (mj-preview) tests for chat email templates:
 * - chat/chaseup-mods.blade.php
 * - chat/review-pending.blade.php
 * - chat/review-summary.blade.php
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
    // chat/review-pending.blade.php
    // -----------------------------------------------------------------------

    /**
     * Preheader uses the plural "messages" form when count > 1.
     */
    public function test_review_pending_preheader_shows_count_and_group_name_plural(): void
    {
        $html = view('emails.mjml.chat.review-pending', [
            'groupName'   => 'Freegle Manchester',
            'count'       => 3,
            'reviewUrl'   => 'https://modtools.org/chats/review',
            'mentorsAddr' => 'mentors@ilovefreegle.org',
            'email'       => 'mods@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>3 messages between members need reviewing on Freegle Manchester</mj-preview>',
            $html
        );
    }

    /**
     * Preheader uses the singular "message" form when count == 1.
     */
    public function test_review_pending_preheader_singular_when_count_is_one(): void
    {
        $html = view('emails.mjml.chat.review-pending', [
            'groupName'   => 'Freegle Bristol',
            'count'       => 1,
            'reviewUrl'   => 'https://modtools.org/chats/review',
            'mentorsAddr' => 'mentors@ilovefreegle.org',
            'email'       => 'mods@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>1 message between members need reviewing on Freegle Bristol</mj-preview>',
            $html
        );
    }

    // -----------------------------------------------------------------------
    // chat/review-summary.blade.php
    // -----------------------------------------------------------------------

    /**
     * Preheader shows the total count with plural "messages".
     */
    public function test_review_summary_preheader_shows_total_plural(): void
    {
        $html = view('emails.mjml.chat.review-summary', [
            'total'   => 7,
            'summary' => 'Freegle Leeds: 4 messages, Freegle Bradford: 3 messages',
            'email'   => 'admin@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>7 messages across groups waiting for review</mj-preview>',
            $html
        );
    }

    /**
     * Preheader uses the singular "message" form when total == 1.
     */
    public function test_review_summary_preheader_singular_when_total_is_one(): void
    {
        $html = view('emails.mjml.chat.review-summary', [
            'total'   => 1,
            'summary' => 'Freegle Leeds: 1 message',
            'email'   => 'admin@example.com',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>1 message across groups waiting for review</mj-preview>',
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
            '<mj-preview>Be careful - you have been talking to Suspicious Pete about: OFFER: Free laptop (London)</mj-preview>',
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
            '<mj-preview>Be careful - you have been talking to Suspicious Pete</mj-preview>',
            $html
        );
        $this->assertStringNotContainsString('about:', $html);
    }
}
