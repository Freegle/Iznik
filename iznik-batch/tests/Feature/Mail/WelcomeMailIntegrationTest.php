<?php

namespace Tests\Feature\Mail;

use App\Mail\Welcome\WelcomeMail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * Integration tests that actually send emails to Mailpit and verify via API.
 *
 * These tests require Mailpit to be running and accessible.
 * Note: These tests do NOT use RefreshDatabase - they use the real database
 * and send real emails to Mailpit.
 *
 * Tests use unique identifiers (uniqid) to ensure they can run in parallel
 * without data conflicts.
 */
class WelcomeMailIntegrationTest extends TestCase
{
    protected MailpitHelper $mailpit;
    protected string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate unique ID for this test run to avoid conflicts in parallel execution.
        $this->testRunId = uniqid('test_', TRUE);

        // Configure for actual SMTP sending (override phpunit.xml array mailer).
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        // Enable spam checking.
        Config::set('freegle.spam_check.enabled', true);
        Config::set('freegle.spam_check.spamassassin_host', 'spamassassin-app');
        Config::set('freegle.spam_check.spamassassin_port', 783);
        Config::set('freegle.spam_check.rspamd_host', 'rspamd');
        Config::set('freegle.spam_check.rspamd_port', 11334);

        // Set up Mailpit helper with docker network URL.
        $this->mailpit = new MailpitHelper('http://mailpit:8025');

        // Note: Do NOT call deleteAllMessages() here - in parallel test runs,
        // this would delete emails from other tests. Each test uses unique
        // email addresses via uniqueEmail(), so no cleanup is needed.
    }

    protected function tearDown(): void
    {
        // Don't clear messages after test - leave for manual inspection.
        parent::tearDown();
    }

    /**
     * Generate unique email address for this test.
     */
    protected function uniqueEmail(string $prefix = 'test', string $domain = 'example.com'): string
    {
        return "{$prefix}_{$this->testRunId}@{$domain}";
    }

    /**
     * Test welcome email is actually delivered to Mailpit.
     */
    public function test_welcome_email_delivered_to_mailpit(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('delivery');
        $password = 'welcomepass123';

        // Send the email.
        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, $password));

        // Wait for and verify the message via Mailpit API.
        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        $this->assertNotNull($message);
        // Subject contains emoji and site name.
        $subject = $this->mailpit->getSubject($message);
        $this->assertStringContainsString('Welcome to Freegle', $subject);
    }

    /**
     * Test welcome email body contains expected content.
     */
    public function test_welcome_email_contains_expected_content(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('content');
        $password = 'mypassword456';

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, $password));

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        // Verify body contains key content.
        $this->assertTrue(
            $this->mailpit->bodyContains($message, 'part of the'),
            'Email body should contain welcome text'
        );

        $this->assertTrue(
            $this->mailpit->bodyContains($message, $password),
            'Email body should contain the password'
        );

        $this->assertTrue(
            $this->mailpit->bodyContains($message, 'Happy freegling'),
            'Email body should contain closing text'
        );
    }

    /**
     * Test welcome email without password does not show password section.
     */
    public function test_welcome_email_without_password_excludes_password_section(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('nopassword');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, NULL));

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        // Verify the password section is not in the body.
        $this->assertFalse(
            $this->mailpit->bodyContains($message, "IMPORTANT: Your password"),
            'Email body should not contain password section when password is null'
        );
    }

    /**
     * Test email footer contains recipient email.
     */
    public function test_welcome_email_footer_contains_recipient(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('footer');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail));

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        $this->assertTrue(
            $this->mailpit->bodyContains($message, $recipientEmail),
            'Email footer should contain recipient email address'
        );
    }

    /**
     * Test email contains call-to-action buttons.
     */
    public function test_welcome_email_contains_cta_buttons(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('cta');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail));

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);

        // Check for CTA button text (URLs are tracked via redirect).
        $this->assertTrue(
            $this->mailpit->bodyContains($message, 'Give stuff away'),
            'Email should contain Give button text'
        );

        $this->assertTrue(
            $this->mailpit->bodyContains($message, 'Find what you need'),
            'Email should contain Find button text'
        );

        // Verify the three rules section exists (uppercase in text version).
        $this->assertTrue(
            $this->mailpit->bodyContains($message, 'THREE SIMPLE RULES') ||
            $this->mailpit->bodyContains($message, 'Three simple rules'),
            'Email should contain rules section'
        );
    }

    /**
     * Test welcome email passes spam checks.
     *
     * This test sends the email through SpamAssassin and Rspamd
     * and reports the spam scores in the headers.
     */
    public function test_welcome_email_passes_spam_checks(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('spam');

        Mail::to($recipientEmail)->send(new WelcomeMail($recipientEmail, 'testpass123'));

        // Wait for the message (returns full message with headers).
        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $this->assertNotNull($message, 'Message should have been sent');

        // Get spam report.
        $spamReport = $this->mailpit->getSpamReport($message);

        // Log spam report for analysis.
        $saScore = $spamReport['spamassassin']['score'];
        $rspamdScore = $spamReport['rspamd']['score'];
        $saSymbols = $spamReport['spamassassin']['symbols'];
        $rspamdSymbols = $spamReport['rspamd']['symbols'];

        fwrite(STDERR, "\n");
        fwrite(STDERR, "=== Welcome Email Spam Report ===\n");
        fwrite(STDERR, "SpamAssassin Score: " . ($saScore !== null ? sprintf('%.1f', $saScore) : 'N/A') . "\n");
        fwrite(STDERR, "SpamAssassin Symbols: " . (count($saSymbols) > 0 ? implode(', ', $saSymbols) : 'none') . "\n");
        fwrite(STDERR, "Rspamd Score: " . ($rspamdScore !== null ? sprintf('%.1f', $rspamdScore) : 'N/A') . "\n");
        fwrite(STDERR, "Rspamd Symbols: " . (count($rspamdSymbols) > 0 ? implode(', ', $rspamdSymbols) : 'none') . "\n");
        fwrite(STDERR, "================================\n");

        // Assert spam scores are below threshold (5.0).
        // Note: If spam checking is not enabled, scores will be null.
        if ($saScore !== null) {
            $this->assertLessThan(
                5.0,
                $saScore,
                "SpamAssassin score {$saScore} exceeds threshold. Symbols: " . implode(', ', $saSymbols)
            );
        }

        if ($rspamdScore !== null) {
            $this->assertLessThan(
                5.0,
                $rspamdScore,
                "Rspamd score {$rspamdScore} exceeds threshold. Symbols: " . implode(', ', $rspamdSymbols)
            );
        }

        // If neither spam checker returned results, still pass but warn.
        if ($saScore === null && $rspamdScore === null) {
            fwrite(STDERR, "WARNING: No spam scores available. Check spam check services.\n");
        }
    }

    /**
     * Check if Mailpit is available.
     */
    protected function isMailpitAvailable(): bool
    {
        try {
            $ch = curl_init('http://mailpit:8025/api/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return FALSE;
        }
    }
}
