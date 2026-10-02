<?php

namespace Tests\Unit\Mail;

use App\Mail\Chat\SpamWarningMail;
use Tests\TestCase;

/**
 * The suspected scammer's name is quoted everywhere the warning shows it. A generic
 * name such as "A Freegler" otherwise reads as part of the sentence, and the member
 * cannot tell who the warning is about (Discourse 10174/22).
 */
class SpamWarningMailTest extends TestCase
{
    private function mail(): SpamWarningMail
    {
        return new SpamWarningMail(
            $this->createTestUser(),
            'A Freegler',
            'OFFER: Sofa (Leeds LS1)',
            'mods@example.com',
            'Freegle Leeds Volunteers'
        );
    }

    public function test_subject_quotes_the_name(): void
    {
        $this->assertStringEndsWith('about "A Freegler"', $this->mail()->envelope()->subject);
    }

    public function test_html_body_and_preview_quote_the_name(): void
    {
        $html = view('emails.mjml.chat.spam-warning', [
            'recipient'      => $this->createTestUser(),
            'spammerName'    => 'A Freegler',
            'messageSubject' => 'OFFER: Sofa (Leeds LS1)',
            'siteName'       => 'Freegle',
        ])->render();

        $this->assertStringContainsString('<mj-preview>Be careful - you have been talking to &quot;A Freegler&quot;', $html);
        $this->assertStringContainsString('<strong>"A Freegler"</strong>', $html);
    }

    public function test_text_body_quotes_the_name(): void
    {
        $text = view('emails.text.chat.spam-warning', [
            'spammerName'    => 'A Freegler',
            'messageSubject' => 'OFFER: Sofa (Leeds LS1)',
        ])->render();

        $this->assertStringContainsString('talking to "A Freegler".', $text);
    }
}
