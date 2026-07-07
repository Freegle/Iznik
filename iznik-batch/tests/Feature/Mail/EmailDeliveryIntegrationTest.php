<?php

namespace Tests\Feature\Mail;

use App\Mail\Admin\AdminMail;
use App\Mail\Chat\ReferToSupportMail;
use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Mail\Message\DeadlineReached;
use App\Mail\Message\ModStdMessageMail;
use App\Models\Message;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * Integration tests that send real emails to Mailpit and verify delivery and content.
 *
 * Tests use unique email addresses via uniqueEmail() to avoid conflicts in parallel runs.
 * Requires Mailpit to be running and accessible.
 */
class EmailDeliveryIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure for actual SMTP sending (override phpunit.xml array mailer).
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        $this->mailpit = new MailpitHelper('http://mailpit:8025');
    }

    protected function isMailpitAvailable(): bool
    {
        try {
            return count($this->mailpit->getMessages()) >= 0;
        } catch (\Throwable $e) {
            return FALSE;
        }
    }

    /**
     * Get the full email body (HTML or text) from a Mailpit message.
     */
    protected function getEmailBody(array $message): string
    {
        // Prefer HTML, fall back to Text. Check non-empty since Mailpit
        // returns empty string for HTML on plain-text-only emails.
        if (!empty($message['HTML'])) {
            return $message['HTML'];
        }
        if (!empty($message['Text'])) {
            return $message['Text'];
        }

        // Try fetching the full message by ID.
        if (isset($message['ID'])) {
            $full = $this->mailpit->getMessage($message['ID']);
            if (!empty($full['HTML'])) {
                return $full['HTML'];
            }
            if (!empty($full['Text'])) {
                return $full['Text'];
            }
        }

        return '';
    }

    /**
     * Extract a URL matching a path pattern from email HTML/text body.
     * Also checks inside base64-encoded tracking redirect URLs (/e/d/r/...?url=BASE64).
     */
    protected function extractUrlByPath(string $body, string $pathPattern): ?string
    {
        // Try HTML href first.
        if (preg_match('/href=["\']([^"\']*' . $pathPattern . '[^"\']*)["\']/', $body, $matches)) {
            return html_entity_decode($matches[1]);
        }

        // Try plain text URL.
        if (preg_match('/(https?:\/\/[^\s]*' . $pathPattern . '[^\s<]*)/', $body, $matches)) {
            return $matches[1];
        }

        // Try tracking redirect URLs: href contains /e/d/r/...?url=BASE64
        if (preg_match_all('/href=["\']([^"\']*\/e\/d\/r\/[^"\']*)["\']/', $body, $hrefMatches)) {
            foreach ($hrefMatches[1] as $trackedHref) {
                $decoded = html_entity_decode($trackedHref);
                if (preg_match('/[?&]url=([^&"\' ]+)/', $decoded, $urlParam)) {
                    $destination = base64_decode($urlParam[1]);
                    if ($destination && preg_match('/' . $pathPattern . '/', $destination)) {
                        return $destination;
                    }
                }
            }
        }

        return NULL;
    }

    // =========================================================================
    // AdminMail
    // =========================================================================

    public function test_admin_mail_delivered_with_cta_link(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('admin');
        $user = $this->createTestUser(['email_preferred' => $recipientEmail]);
        $group = $this->createTestGroup();
        $userSite = config('freegle.sites.user');

        $admin = [
            'id' => 999,
            'parentid' => null,
            'subject' => 'Test Admin Notification',
            'text' => 'This is an important admin message for testing.',
            'ctalink' => $userSite . '/explore',
            'ctatext' => 'Explore Freegle',
            'groupid' => $group->id,
            'essential' => true,
        ];

        $mail = new AdminMail($user, $admin, $group->namefull, $group->nameshort . '-mods@test.com', $group->nameshort);
        Mail::send($mail);

        $message = $this->mailpit->assertMessageSentTo($recipientEmail, 20);
        $body = $this->getEmailBody($message);

        // Verify delivery and subject.
        $subject = $this->mailpit->getSubject($message);
        $this->assertStringContainsString('ADMIN:', $subject);
        $this->assertStringContainsString('Test Admin Notification', $subject);

        // Verify body is non-empty and contains admin text.
        $this->assertNotEmpty($body, 'Email body should not be empty');
        $this->assertStringContainsString('important admin message', $body);

        // Verify CTA link text is present (the URL may be wrapped in tracking redirect).
        $this->assertStringContainsString('Explore', $body, 'Email should reference the Explore CTA');
    }

    // =========================================================================
    // DeadlineReached
    // =========================================================================

    public function test_deadline_reached_delivered_with_action_urls(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('deadline');
        $user = $this->createTestUser(['email_preferred' => $recipientEmail]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Vintage Lamp (TestLocation)',
        ]);

        $mail = new DeadlineReached($message, $user);
        Mail::send($mail);

        $delivered = $this->mailpit->assertMessageSentTo($recipientEmail, 20);
        $body = $this->getEmailBody($delivered);

        // Verify subject.
        $subject = $this->mailpit->getSubject($delivered);
        $this->assertStringContainsString('Deadline reached', $subject);
        $this->assertStringContainsString('Vintage Lamp', $subject);

        // Verify body is non-empty.
        $this->assertNotEmpty($body, 'Email body should not be empty');

        // Verify extend URL.
        $extendUrl = $this->extractUrlByPath($body, '\/mypost\/\d+\/extend');
        $this->assertNotNull($extendUrl, 'Email should contain an extend URL. Body (first 2000 chars): ' . substr($body, 0, 2000));
        $this->assertStringContainsString((string) $message->id, $extendUrl);

        // Verify completed URL.
        $completedUrl = $this->extractUrlByPath($body, '\/mypost\/\d+\/completed');
        $this->assertNotNull($completedUrl, 'Email should contain a completed URL');

        // Verify withdraw URL.
        $withdrawUrl = $this->extractUrlByPath($body, '\/mypost\/\d+\/withdraw');
        $this->assertNotNull($withdrawUrl, 'Email should contain a withdraw URL');
    }

    // =========================================================================
    // DonationThankYou
    // =========================================================================

    public function test_donation_thank_you_delivered_with_site_link(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('donthank');
        $user = $this->createTestUser(['email_preferred' => $recipientEmail]);

        $mail = new DonationThankYou($user);
        Mail::send($mail);

        $delivered = $this->mailpit->assertMessageSentTo($recipientEmail, 20);
        $body = $this->getEmailBody($delivered);

        // Verify subject.
        $subject = $this->mailpit->getSubject($delivered);
        $this->assertStringContainsString('Thank you', $subject);
        $this->assertStringContainsString('donation', $subject);

        // Verify body is non-empty.
        $this->assertNotEmpty($body, 'Email body should not be empty');

        // Verify user site link is present.
        $userSite = config('freegle.sites.user');
        $siteUrl = $this->extractUrlByPath($body, preg_quote(parse_url($userSite, PHP_URL_HOST), '/'));
        $this->assertNotNull($siteUrl, 'Email should contain a link to the user site');
    }

    // =========================================================================
    // AskForDonation
    // =========================================================================

    public function test_ask_for_donation_delivered_with_donate_url(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('askdon');
        $user = $this->createTestUser(['email_preferred' => $recipientEmail]);

        $mail = new AskForDonation($user, 'OFFER: Garden Tools (TestLocation)');
        Mail::send($mail);

        $delivered = $this->mailpit->assertMessageSentTo($recipientEmail, 20);
        $body = $this->getEmailBody($delivered);

        // Verify subject contains the item reference.
        $subject = $this->mailpit->getSubject($delivered);
        $this->assertStringContainsString('Garden Tools', $subject);

        // Verify body is non-empty.
        $this->assertNotEmpty($body, 'Email body should not be empty');

        // Verify donate URL is present. The configured URL may vary, but it should be a link.
        $donateUrl = config('freegle.donation.url', 'http://freegle.in/paypal1510');
        $donateHost = parse_url($donateUrl, PHP_URL_HOST);
        // Check for either the direct donate URL or a tracked version of it.
        $foundDonateLink = $this->extractUrlByPath($body, preg_quote($donateHost, '/'))
            ?? $this->extractUrlByPath($body, 'donate|paypal');
        $this->assertNotNull($foundDonateLink, 'Email should contain a donate URL');
    }

    // =========================================================================
    // ModStdMessageMail
    // =========================================================================

    public function test_mod_std_message_delivered_with_body_content(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('modstd');
        $user = $this->createTestUser(['email_preferred' => $recipientEmail]);
        $group = $this->createTestGroup();

        $stdBody = 'Your post has been approved by a moderator. Thank you for freegling!';

        $mail = new ModStdMessageMail(
            modName: 'Test Moderator',
            groupName: $group->namefull,
            groupNameShort: $group->nameshort,
            stdSubject: 'Re: OFFER: Test Item (TestLocation)',
            stdBody: $stdBody,
            messageSubject: 'OFFER: Test Item (TestLocation)',
            msgId: 12345,
            recipientUserId: $user->id,
            recipientEmail: $recipientEmail,
        );
        Mail::to($recipientEmail)->send($mail);

        $delivered = $this->mailpit->assertMessageSentTo($recipientEmail, 20);
        $body = $this->getEmailBody($delivered);

        // Verify subject.
        $subject = $this->mailpit->getSubject($delivered);
        $this->assertStringContainsString('OFFER: Test Item', $subject);

        // Verify body is non-empty and contains the message content.
        $this->assertNotEmpty($body, 'Email body should not be empty');
        $this->assertStringContainsString('approved by a moderator', $body);
    }

    // =========================================================================
    // ReferToSupportMail
    // =========================================================================

    public function test_refer_to_support_delivered_with_reply_to(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $supportEmail = $this->uniqueEmail('support');
        $replyToEmail = $this->uniqueEmail('modreply');

        $mail = new ReferToSupportMail(
            userName: 'Alice Tester',
            userId: 111,
            chatId: 222,
            otherUserName: 'Bob Helper',
            otherUserId: 333,
            replyToAddress: $replyToEmail,
            replyToName: 'Alice Tester',
        );
        Mail::to($supportEmail)->send($mail);

        $delivered = $this->mailpit->assertMessageSentTo($supportEmail, 20);
        $body = $this->getEmailBody($delivered);

        // Verify subject contains user info and chat ID.
        $subject = $this->mailpit->getSubject($delivered);
        $this->assertStringContainsString('Alice Tester', $subject);
        $this->assertStringContainsString('#222', $subject);
        $this->assertStringContainsString('Bob Helper', $subject);

        // Verify body is non-empty and contains the review link.
        $this->assertNotEmpty($body, 'Email body should not be empty');
        $modSite = config('freegle.sites.mod');
        $this->assertStringContainsString('refer/222', $body);

        // Verify reply-to header.
        $replyTo = $this->mailpit->getHeader($delivered, 'Reply-To');
        $this->assertNotNull($replyTo, 'Email should have a Reply-To header');
        $this->assertStringContainsString($replyToEmail, $replyTo);
    }
}
