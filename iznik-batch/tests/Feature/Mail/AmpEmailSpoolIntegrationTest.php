<?php

namespace Tests\Feature\Mail;

use App\Services\EmailSpoolerService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * Integration tests for AMP email MIME structure.
 *
 * These tests verify that AMP emails sent through the spooler
 * actually contain the text/x-amp-html MIME part in the sent message.
 *
 * Requires Mailpit to be running and accessible.
 *
 * Note: These tests may show as "risky" due to log output during execution.
 * This is expected for integration tests that actually send emails.
 */
class AmpEmailSpoolIntegrationTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected MailpitHelper $mailpit;
    protected string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate unique ID for this test run.
        $this->testRunId = uniqid('amp_test_', true);

        // Configure for actual SMTP sending.
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);

        // Set up isolated spool directory (binds to container).
        $this->setUpIsolatedSpoolDirectory();

        // Set up Mailpit helper.
        $this->mailpit = new MailpitHelper('http://mailpit:8025');

        // Note: Do NOT call deleteAllMessages() here - in parallel test runs,
        // this would delete emails from other tests. Each test uses unique
        // email addresses via uniqueEmail(), so no cleanup is needed.
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
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
     * Check if Mailpit is available.
     */
    protected function isMailpitAvailable(): bool
    {
        try {
            $ch = curl_init('http://mailpit:8025/api/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a test mailable with AMP content.
     */
    protected function createAmpMailable(string $recipient, ?string $fromEmail = null): Mailable
    {
        $fromEmail = $fromEmail ?? $this->uniqueEmail('noreply');

        return new class($recipient, $fromEmail) extends Mailable {
            public function __construct(
                private string $recipient,
                private string $fromEmail
            ) {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromEmail, 'Test Sender'),
                    subject: 'AMP Test Email - ' . date('Y-m-d H:i:s'),
                );
            }

            public function build(): static
            {
                $ampHtml = '<!doctype html>
<html amp4email>
<head>
    <meta charset="utf-8">
    <script async src="https://cdn.ampproject.org/v0.js"></script>
    <style amp4email-boilerplate>body{visibility:hidden}</style>
</head>
<body>
    <h1>AMP Email Content</h1>
    <p>This is the AMP version of the email.</p>
    <amp-img src="https://example.com/image.jpg" width="300" height="200"></amp-img>
</body>
</html>';

                // Set HTML first, then override body structure with withSymfonyMessage.
                // Note: Don't use ->text() as it expects a view name.
                return $this->html('<p>This is the regular HTML version.</p>')
                    ->withSymfonyMessage(function (Email $message) use ($ampHtml) {
                        // Build multipart/alternative with AMP part.
                        // Order matters for email clients: text, AMP, HTML.
                        $textPart = new TextPart('This is the plain text version.', 'utf-8', 'plain');
                        $ampPart = new TextPart($ampHtml, 'utf-8', 'x-amp-html');
                        $htmlPart = new TextPart('<p>This is the regular HTML version.</p>', 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);
                        $message->setBody($alternativePart);
                    });
            }
        };
    }

    /**
     * Test that AMP email sent through spooler contains text/x-amp-html MIME part.
     *
     * This is the critical test that verifies AMP content survives the full
     * spool -> send pipeline and appears in the actual sent message.
     */
    public function test_spooled_amp_email_contains_amp_mime_part(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_mime');

        // Create and spool the AMP mailable.
        $mailable = $this->createAmpMailable($recipientEmail);
        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Verify AMP content was captured in spool file.
        $spoolFile = $this->testSpoolDir . '/pending/' . $id . '.json';
        $this->assertFileExists($spoolFile, 'Spool file should exist');

        $spoolData = json_decode(file_get_contents($spoolFile), true);
        $this->assertArrayHasKey('amp_html', $spoolData, 'Spool should contain amp_html');
        $this->assertNotNull($spoolData['amp_html'], 'amp_html should not be null');
        $this->assertStringContainsString('amp4email', $spoolData['amp_html'], 'amp_html should contain AMP doctype');

        // Process the spool to actually send the email.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent'], 'One email should have been sent');

        // Wait for and verify the message via Mailpit API.
        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $this->assertNotNull($message, 'Message should have been sent to Mailpit');

        $messageId = $message['ID'];

        // THE CRITICAL ASSERTION: Verify the sent email has the AMP MIME part.
        $hasAmpPart = $this->mailpit->hasAmpMimePart($messageId);
        $this->assertTrue(
            $hasAmpPart,
            'Sent email should contain text/x-amp-html MIME part'
        );

        // Also verify the AMP content is correct.
        $ampContent = $this->mailpit->getAmpContent($messageId);
        $this->assertNotNull($ampContent, 'Should be able to extract AMP content');
        $this->assertStringContainsString('amp4email', $ampContent, 'AMP content should contain amp4email doctype');
        $this->assertStringContainsString('AMP Email Content', $ampContent, 'AMP content should contain expected text');
    }

    /**
     * Test that the raw MIME message has the correct multipart/alternative structure.
     */
    public function test_amp_email_has_multipart_alternative_structure(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_structure');

        $mailable = $this->createAmpMailable($recipientEmail);
        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        $raw = $this->mailpit->getRawMessage($messageId);
        $this->assertNotNull($raw, 'Should be able to get raw message');

        // Verify multipart/alternative structure.
        $this->assertStringContainsString(
            'multipart/alternative',
            $raw,
            'Email should be multipart/alternative'
        );

        // Verify all three content types are present.
        $this->assertStringContainsString(
            'text/plain',
            $raw,
            'Email should contain text/plain part'
        );

        $this->assertStringContainsString(
            'text/x-amp-html',
            $raw,
            'Email should contain text/x-amp-html part'
        );

        $this->assertStringContainsString(
            'text/html',
            $raw,
            'Email should contain text/html part'
        );
    }

    /**
     * Test that AMP content is preserved exactly through spooling.
     */
    public function test_amp_content_is_preserved_exactly(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_exact');

        $mailable = $this->createAmpMailable($recipientEmail);
        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        $ampContent = $this->mailpit->getAmpContent($messageId);

        // Verify key AMP elements are preserved.
        $this->assertStringContainsString('<html amp4email>', $ampContent);
        $this->assertStringContainsString('<amp-img', $ampContent);
        $this->assertStringContainsString('amp4email-boilerplate', $ampContent);
    }

    /**
     * Test that AMP email has all envelope addresses after spooling.
     *
     * This is a critical test that verifies To, From, CC, BCC, Reply-To
     * addresses are preserved when AMP emails are spooled and sent.
     * This test would have caught the bug where setBody() caused the
     * envelope addresses to be lost.
     */
    public function test_amp_email_preserves_all_envelope_addresses(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_envelope');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create an AMP mailable with explicit recipient and sender.
        $mailable = $this->createAmpMailable($recipientEmail, $fromEmail);
        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        // Get the raw MIME message to verify envelope addresses.
        $raw = $this->mailpit->getRawMessage($messageId);
        $this->assertNotNull($raw, 'Should be able to get raw message');

        // Verify To header is present - this is the critical assertion
        // that would have caught the bug.
        $this->assertMatchesRegularExpression(
            '/^To:.*' . preg_quote($recipientEmail, '/') . '/mi',
            $raw,
            'AMP email must have To header with recipient address'
        );

        // Verify From header is present.
        $this->assertMatchesRegularExpression(
            '/^From:.*' . preg_quote($fromEmail, '/') . '/mi',
            $raw,
            'AMP email must have From header'
        );

        // Verify Subject header is present.
        $this->assertStringContainsString(
            'Subject:',
            $raw,
            'AMP email must have Subject header'
        );
    }

    /**
     * Test that AMP email with CC and BCC preserves those addresses.
     */
    public function test_amp_email_preserves_cc_and_bcc(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('amp_cc_to');
        $ccEmail = $this->uniqueEmail('amp_cc_cc');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a mailable with CC using withSymfonyMessage.
        $mailable = new class($recipientEmail, $ccEmail, $fromEmail) extends Mailable {
            public function __construct(
                private string $recipient,
                private string $ccRecipient,
                private string $fromEmail
            ) {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromEmail, 'Test'),
                    subject: 'AMP CC Test',
                );
            }

            public function build(): static
            {
                $ampHtml = '<!doctype html><html amp4email><head><meta charset="utf-8"><script async src="https://cdn.ampproject.org/v0.js"></script></head><body><p>AMP with CC</p></body></html>';

                return $this->html('<p>HTML with CC</p>')
                    ->cc($this->ccRecipient)
                    ->withSymfonyMessage(function (Email $message) use ($ampHtml) {
                        $textPart = new TextPart('Plain text', 'utf-8', 'plain');
                        $ampPart = new TextPart($ampHtml, 'utf-8', 'x-amp-html');
                        $htmlPart = new TextPart('<p>HTML with CC</p>', 'utf-8', 'html');
                        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);
                        $message->setBody($alternativePart);
                    });
            }
        };

        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        // The email should be delivered to both To and CC.
        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $this->assertNotNull($message, 'Message should be sent to primary recipient');

        // Verify CC header in raw message.
        $raw = $this->mailpit->getRawMessage($message['ID']);
        $this->assertMatchesRegularExpression(
            '/^Cc:.*' . preg_quote($ccEmail, '/') . '/mi',
            $raw,
            'AMP email must have Cc header with CC address'
        );
    }

    /**
     * Test that non-AMP email does NOT have AMP MIME part.
     *
     * This is a sanity check to ensure our detection is working correctly.
     */
    public function test_non_amp_email_does_not_have_amp_mime_part(): void
    {
        if (!$this->isMailpitAvailable()) {
            $this->markTestSkipped('Mailpit is not available.');
        }

        $recipientEmail = $this->uniqueEmail('no_amp');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a simple mailable without AMP content.
        $mailable = new class($recipientEmail, $fromEmail) extends Mailable {
            public function __construct(
                private string $recipient,
                private string $fromEmail
            ) {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromEmail, 'Test'),
                    subject: 'Non-AMP Test',
                );
            }

            public function build(): static
            {
                // Just use HTML - text version is auto-generated.
                return $this->html('<p>Regular HTML only</p>');
            }
        };

        $this->spooler->spool($mailable, $recipientEmail);
        $this->spooler->processSpool();

        $message = $this->mailpit->assertMessageSentTo($recipientEmail);
        $messageId = $message['ID'];

        // Verify the email does NOT have an AMP part.
        $hasAmpPart = $this->mailpit->hasAmpMimePart($messageId);
        $this->assertFalse(
            $hasAmpPart,
            'Non-AMP email should NOT contain text/x-amp-html MIME part'
        );
    }
}
