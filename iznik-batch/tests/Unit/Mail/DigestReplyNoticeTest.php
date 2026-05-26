<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\DigestReplyNotice;
use Tests\TestCase;

class DigestReplyNoticeTest extends TestCase
{
    public function test_can_be_constructed(): void
    {
        $mail = new DigestReplyNotice('test@example.com', 'Test User', 12345);

        $this->assertInstanceOf(DigestReplyNotice::class, $mail);
    }

    public function test_build_returns_self(): void
    {
        $mail = new DigestReplyNotice('test@example.com', 'Test User', 12345);
        $result = $mail->build();

        $this->assertInstanceOf(DigestReplyNotice::class, $result);
    }

    public function test_has_correct_subject(): void
    {
        $mail = new DigestReplyNotice('test@example.com');
        $envelope = $mail->envelope();

        $this->assertEquals('How to reply to posts on Freegle', $envelope->subject);
    }

    public function test_can_construct_without_name_or_userid(): void
    {
        $mail = new DigestReplyNotice('test@example.com');
        $result = $mail->build();

        $this->assertInstanceOf(DigestReplyNotice::class, $result);
    }

    public function test_tracking_initialised(): void
    {
        $mail = new DigestReplyNotice('test@example.com', 'Test User', 12345);

        $tracking = $mail->getTracking();
        $this->assertNotNull($tracking);
        $this->assertEquals('DigestReplyNotice', $tracking->email_type);
    }
}
