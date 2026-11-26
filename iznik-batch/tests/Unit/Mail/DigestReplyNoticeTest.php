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

    public function test_preheader_contains_reply_instructions(): void
    {
        // The mj-preview for this email is a static instruction telling the
        // recipient how to reply to a specific post in the digest. Assert the
        // literal text appears in the rendered Blade/MJML output so we catch
        // any accidental change to the preheader copy.
        $html = view('emails.mjml.digest.reply-notice', [
            'recipientName'    => 'Test User',
            'recipientEmail'   => 'test@example.com',
            'browseUrl'        => 'https://example.com/browse',
            'settingsUrl'      => 'https://example.com/settings',
            'trackingPixelHtml' => '',
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>To reply to a post, use the Reply button next to it in the digest email.</mj-preview>',
            $html,
            'DigestReplyNotice preheader must contain the static reply instructions'
        );
    }
}
