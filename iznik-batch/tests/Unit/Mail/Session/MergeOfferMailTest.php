<?php

namespace Tests\Unit\Mail\Session;

use App\Mail\Session\MergeOfferMail;
use Tests\TestCase;

class MergeOfferMailTest extends TestCase
{
    private function makeMail(): MergeOfferMail
    {
        return new MergeOfferMail(
            recipientUserId: 123,
            recipientName: 'Test User',
            recipientEmail: 'test@example.com',
            name1: 'Account One',
            email1: 'one@example.com',
            name2: 'Account Two',
            email2: 'two@example.com',
            mergeUrl: 'https://www.ilovefreegle.org/merge/abc123',
        );
    }

    public function test_can_be_constructed(): void
    {
        $mail = $this->makeMail();
        $this->assertInstanceOf(MergeOfferMail::class, $mail);
    }

    public function test_has_correct_subject(): void
    {
        $mail = $this->makeMail();
        $envelope = $mail->envelope();
        $this->assertEquals('You have multiple Freegle accounts - please read', $envelope->subject);
    }

    public function test_build_returns_self(): void
    {
        $mail = $this->makeMail();
        $result = $mail->build();
        $this->assertInstanceOf(MergeOfferMail::class, $result);
    }

    public function test_sets_recipient_to_email(): void
    {
        $mail = $this->makeMail();
        $mail->build();
        $this->assertTrue($mail->hasTo('test@example.com'));
    }

    public function test_tracks_user_id(): void
    {
        $mail = $this->makeMail();

        $method = new \ReflectionMethod($mail, 'getRecipientUserId');
        $method->setAccessible(true);

        $this->assertEquals(123, $method->invoke($mail));
    }

    public function test_envelope_has_noreply_sender(): void
    {
        $mail = $this->makeMail();
        $envelope = $mail->envelope();

        $this->assertEquals(
            config('freegle.mail.noreply_addr'),
            $envelope->from->address
        );
    }

    public function test_preheader_contains_both_email_addresses(): void
    {
        $html = view('emails.mjml.session.merge-offer', [
            'name1'    => 'Account One',
            'email1'   => 'one@example.com',
            'name2'    => 'Account Two',
            'email2'   => 'two@example.com',
            'mergeUrl' => 'https://www.ilovefreegle.org/merge/abc123',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>We think one@example.com and two@example.com may be the same person - merge them?</mj-preview>',
            $html
        );
    }
}
