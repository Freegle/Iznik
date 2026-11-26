<?php

namespace Tests\Unit\Mail\Session;

use App\Mail\Session\VerifyEmailMail;
use Tests\TestCase;

class VerifyEmailMailTest extends TestCase
{
    public function test_can_be_constructed(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $this->assertInstanceOf(VerifyEmailMail::class, $mail);
    }

    public function test_has_correct_subject(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $envelope = $mail->envelope();
        $this->assertEquals('Please verify your email', $envelope->subject);
    }

    public function test_build_returns_self(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $result = $mail->build();

        $this->assertInstanceOf(VerifyEmailMail::class, $result);
    }

    public function test_sets_recipient_to_email(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $mail->build();

        $this->assertTrue($mail->hasTo('test@example.com'));
    }

    public function test_tracks_user_id(): void
    {
        $mail = new VerifyEmailMail(
            userId: 456,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        // Use reflection to call protected getRecipientUserId
        $method = new \ReflectionMethod($mail, 'getRecipientUserId');
        $method->setAccessible(true);

        $this->assertEquals(456, $method->invoke($mail));
    }

    public function test_has_empty_attachments(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $this->assertEmpty($mail->attachments());
    }

    public function test_envelope_has_noreply_sender(): void
    {
        $mail = new VerifyEmailMail(
            userId: 123,
            email: 'test@example.com',
            confirmUrl: 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        );

        $envelope = $mail->envelope();

        $this->assertEquals(
            config('freegle.mail.noreply_addr'),
            $envelope->from->address
        );
    }

    public function test_preheader_contains_email_address(): void
    {
        $html = view('emails.mjml.session.verify-email', [
            'email'      => 'newuser@example.com',
            'confirmUrl' => 'https://www.ilovefreegle.org/settings/confirmmail/abc123',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>Click to verify newuser@example.com and complete your Freegle sign-up</mj-preview>',
            $html
        );
    }
}
