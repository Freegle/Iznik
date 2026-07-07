<?php

namespace Tests\Unit\Mail\Session;

use App\Mail\Session\UnsubscribeConfirmMail;
use Tests\TestCase;

class UnsubscribeConfirmMailTest extends TestCase
{
    public function test_can_be_constructed(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $this->assertInstanceOf(UnsubscribeConfirmMail::class, $mail);
    }

    public function test_has_correct_subject(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $envelope = $mail->envelope();
        $this->assertEquals('Please confirm you want to leave Freegle', $envelope->subject);
    }

    public function test_build_returns_self(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $result = $mail->build();

        $this->assertInstanceOf(UnsubscribeConfirmMail::class, $result);
    }

    public function test_sets_recipient_to_email(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $mail->build();

        $this->assertTrue($mail->hasTo('test@example.com'));
    }

    public function test_tracks_user_id(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 456,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $method = new \ReflectionMethod($mail, 'getRecipientUserId');
        $method->setAccessible(true);

        $this->assertEquals(456, $method->invoke($mail));
    }

    public function test_envelope_has_noreply_sender(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $envelope = $mail->envelope();

        $this->assertEquals(
            config('freegle.mail.noreply_addr'),
            $envelope->from->address
        );
    }

    public function test_envelope_has_support_reply_to(): void
    {
        $mail = new UnsubscribeConfirmMail(
            userId: 123,
            email: 'test@example.com',
            unsubUrl: 'https://www.ilovefreegle.org/unsubscribe/confirm/abc123',
        );

        $envelope = $mail->envelope();

        $this->assertCount(1, $envelope->replyTo);
        $this->assertEquals(
            config('freegle.mail.support_addr'),
            $envelope->replyTo[0]->address
        );
    }
}
