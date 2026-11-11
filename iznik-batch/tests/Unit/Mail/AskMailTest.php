<?php

namespace Tests\Unit\Mail;

use App\Mail\Stories\AskMail;
use Tests\TestCase;

/**
 * The "tell us your story" ask (resources/views/emails/mjml/stories/ask.blade.php).
 *
 * Only about one story in six arrives with a photo, and the photo is what makes a
 * story worth sharing. The ask therefore has to push for one, and for the
 * difference freegling made, rather than mention a photo as an afterthought.
 */
class AskMailTest extends TestCase
{
    private function renderView(): string
    {
        return view('emails.mjml.stories.ask', [
            'name' => 'Test Freegler',
            'storiesUrl' => 'https://www.ilovefreegle.org/stories',
            'unsubscribeUrl' => 'https://www.ilovefreegle.org/unsubscribe',
            'email' => 'reader@example.com',
        ])->render();
    }

    public function test_asks_for_a_photo_and_says_why(): void
    {
        $mjml = $this->renderView();

        $this->assertStringContainsString('Please add a photo', $mjml);
        $this->assertStringContainsString('get more people freegling', $mjml);
        $this->assertStringNotContainsString("you don't have to", $mjml);
    }

    public function test_asks_what_difference_freegling_made(): void
    {
        $mjml = $this->renderView();

        $this->assertStringContainsString('difference', $mjml);
    }

    public function test_mailable_builds_with_the_ask_view(): void
    {
        $mail = new AskMail(
            recipientName: 'Test Freegler',
            recipientEmail: 'reader@example.com',
            storiesUrl: 'https://www.ilovefreegle.org/stories',
            unsubscribeUrl: 'https://www.ilovefreegle.org/unsubscribe',
        );

        $this->assertSame('Tell us your Freegle story!', $mail->envelope()->subject);
        $this->assertSame($mail, $mail->build());
    }
}
