<?php

namespace Tests\Unit\Mail\Session;

use App\Mail\Session\ForgotPasswordMail;
use Tests\TestCase;

class ForgotPasswordMailTest extends TestCase
{
    public function test_can_be_constructed(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 123,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );

        $this->assertInstanceOf(ForgotPasswordMail::class, $mail);
    }

    public function test_has_correct_subject(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 123,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );

        $envelope = $mail->envelope();
        $this->assertEquals('Forgot your password?', $envelope->subject);
    }

    public function test_build_returns_self(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 123,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );

        $result = $mail->build();

        $this->assertInstanceOf(ForgotPasswordMail::class, $result);
    }

    public function test_sets_recipient_to_email(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 123,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );

        $mail->build();

        $this->assertTrue($mail->hasTo('test@example.com'));
    }

    public function test_tracks_user_id(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 456,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=456&k=abc',
        );

        $method = new \ReflectionMethod($mail, 'getRecipientUserId');
        $method->setAccessible(true);

        $this->assertEquals(456, $method->invoke($mail));
    }

    public function test_envelope_has_noreply_sender(): void
    {
        $mail = new ForgotPasswordMail(
            userId: 123,
            email: 'test@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );

        $envelope = $mail->envelope();

        $this->assertEquals(
            config('freegle.mail.noreply_addr'),
            $envelope->from->address
        );
    }
}
