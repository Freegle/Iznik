<?php

namespace Tests\Unit\Mail;

use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Donation\DonationSummaryMail;
use App\Mail\Donation\DonationThankYou;
use App\Models\User;
use Tests\TestCase;

class DonationMailTest extends TestCase
{
    public function test_donation_thank_you_can_be_constructed(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertInstanceOf(DonationThankYou::class, $mail);
    }

    public function test_donation_thank_you_has_user(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertSame($user->id, $mail->user->id);
    }

    public function test_donation_thank_you_has_user_site(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);

        $this->assertNotEmpty($mail->userSite);
        $this->assertStringContainsString('http', $mail->userSite);
    }

    public function test_donation_thank_you_build_returns_self(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $result = $mail->build();

        $this->assertInstanceOf(DonationThankYou::class, $result);
    }

    public function test_donation_thank_you_has_correct_subject(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $envelope = $mail->envelope();

        $this->assertEquals('Thank you for your donation to Freegle!', $envelope->subject);
    }

    public function test_donation_thank_you_has_attachments(): void
    {
        $user = $this->createTestUser();

        $mail = new DonationThankYou($user);
        $attachments = $mail->attachments();

        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function test_ask_for_donation_can_be_constructed(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertInstanceOf(AskForDonation::class, $mail);
        $this->assertEquals($user->id, $mail->user->id);
    }

    public function test_ask_for_donation_with_item_subject(): void
    {
        $user = $this->createTestUser();
        $itemSubject = 'OFFER: Free sofa (London)';

        $mail = new AskForDonation($user, $itemSubject);

        $this->assertEquals($itemSubject, $mail->itemSubject);
    }

    public function test_ask_for_donation_build_returns_self(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user, 'Test Item');
        $result = $mail->build();

        $this->assertInstanceOf(AskForDonation::class, $result);
    }

    public function test_ask_for_donation_subject_with_item(): void
    {
        $user = $this->createTestUser();
        $itemSubject = 'OFFER: Test Item (Location)';

        $mail = new AskForDonation($user, $itemSubject);
        $mail->build();

        // Subject should contain the item.
        $this->assertTrue($mail->hasSubject("Regarding: {$itemSubject}"));
    }

    public function test_ask_for_donation_subject_without_item(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);
        $mail->build();

        // Subject should be the default.
        $this->assertTrue($mail->hasSubject('Thanks for freegling!'));
    }

    public function test_ask_for_donation_has_user_site(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertNotEmpty($mail->userSite);
    }

    public function test_ask_for_donation_has_target(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertIsFloat($mail->target);
    }

    public function test_ask_for_donation_has_donate_url(): void
    {
        $user = $this->createTestUser();

        $mail = new AskForDonation($user);

        $this->assertNotEmpty($mail->donateUrl);
    }

    // --- Preheader tests ---

    public function test_ask_for_donation_preheader_shows_item_subject(): void
    {
        $user = $this->createTestUser();
        $itemSubject = 'OFFER: Sofa (Bristol)';

        $html = view('emails.mjml.donation.ask', [
            'user'       => $user,
            'userSite'   => 'https://www.ilovefreegle.org',
            'itemSubject' => $itemSubject,
            'target'     => 2500.0,
            'donateUrl'  => 'https://example.com/donate',
            'settingsUrl' => 'https://example.com/settings',
            'continueUrl' => 'https://example.com',
        ])->render();

        $this->assertStringContainsString('Did you just get this from Freegle?', $html);
        $this->assertStringContainsString('Sofa (Bristol)', $html);
    }

    public function test_ask_for_donation_preheader_fallback_without_item(): void
    {
        $user = $this->createTestUser();

        $html = view('emails.mjml.donation.ask', [
            'user'        => $user,
            'userSite'    => 'https://www.ilovefreegle.org',
            'itemSubject' => null,
            'target'      => 2500.0,
            'donateUrl'   => 'https://example.com/donate',
            'settingsUrl' => 'https://example.com/settings',
            'continueUrl' => 'https://example.com',
        ])->render();

        $this->assertStringContainsString('Thanks for freegling!', $html);
    }

    public function test_donation_thank_you_preheader_shows_static_message(): void
    {
        $user = $this->createTestUser();

        $html = view('emails.mjml.donation.thank-you', [
            'user'        => $user,
            'userSite'    => 'https://www.ilovefreegle.org',
            'continueUrl' => 'https://example.com',
            'settingsUrl' => 'https://example.com/settings',
        ])->render();

        $this->assertStringContainsString(
            'Thank you for your donation - you help keep Freegle free for everyone.',
            $html
        );
    }

    public function test_donate_external_preheader_shows_donor_name_and_amount(): void
    {
        $html = view('emails.mjml.donation.donate-external', [
            // siteName/logoUrl are injected globally by MjmlMailable when sent via the
            // mailable; a bare view()->render() must supply them itself.
            'siteName'  => 'Freegle',
            'logoUrl'   => 'https://example.com/logo.png',
            'userName'  => 'Alice Smith',
            'userId'    => 42,
            'userEmail' => 'alice@example.com',
            'amount'    => 25.00,
            'channel'   => 'PayPal Donate',
        ])->render();

        $this->assertStringContainsString('Alice Smith', $html);
        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('PayPal Donate', $html);
    }

    public function test_donation_daily_summary_preheader_shows_total(): void
    {
        $html = view('emails.mjml.donation.daily-summary', [
            'htmlContent' => '<p>Donations today</p>',
            'total'       => 123.45,
            'email'       => 'fundraising@example.com',
        ])->render();

        $this->assertStringContainsString('Total today: £123.45', $html);
    }
}
