<?php

namespace Tests\Unit\Mail;

use App\Mail\Session\ForgotPasswordMail;
use App\Models\EmailTracking;
use Tests\TestCase;

/**
 * freegle.mail.tracking_enabled: off means no email_tracking row and every
 * tracked link/pixel helper hands back the plain destination. On (the default)
 * is unchanged.
 */
class MailTrackingSwitchTest extends TestCase
{
    private function mail(): ForgotPasswordMail
    {
        return new ForgotPasswordMail(
            userId: 123,
            email: 'switch@example.com',
            resetUrl: 'https://www.ilovefreegle.org/settings?u=123&k=abc',
        );
    }

    public function test_tracking_on_by_default_creates_a_record(): void
    {
        config(['freegle.mail.tracking_enabled' => true]);
        $before = EmailTracking::count();

        $mail = $this->mail();

        $this->assertNotNull($mail->getTracking());
        $this->assertSame($before + 1, EmailTracking::count());
        $this->assertNotEquals('https://example.com/x', $mail->trackedUrl('https://example.com/x'));
        $this->assertNotSame('', $mail->getTrackingPixelMjml());
    }

    public function test_tracking_off_writes_nothing_and_links_are_plain(): void
    {
        config(['freegle.mail.tracking_enabled' => false]);
        $before = EmailTracking::count();

        $mail = $this->mail();

        $this->assertNull($mail->getTracking());
        $this->assertSame($before, EmailTracking::count());
        $this->assertSame('https://example.com/x', $mail->trackedUrl('https://example.com/x'));
        $this->assertSame('https://example.com/i.png', $mail->trackedImageUrl('https://example.com/i.png', 'hero'));
        $this->assertSame('', $mail->getTrackingPixelMjml());
        $this->assertSame('', $mail->getTrackingPixelHtml());

        // The mail still builds and addresses normally without tracking.
        $this->assertInstanceOf(ForgotPasswordMail::class, $mail->build());
        $this->assertTrue($mail->hasTo('switch@example.com'));
    }

    protected function tearDown(): void
    {
        config(['freegle.mail.tracking_enabled' => true]);
        parent::tearDown();
    }
}
