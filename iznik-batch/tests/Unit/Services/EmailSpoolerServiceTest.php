<?php

namespace Tests\Unit\Services;

use App\Mail\Welcome\WelcomeMail;
use App\Models\UserEmail;
use App\Services\EmailSpoolerService;
use App\Services\Mail\Incoming\BounceService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

class EmailSpoolerServiceTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    public function test_spool_creates_pending_file(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        $id = $this->spooler->spool($mailable, $email, 'welcome');

        $this->assertNotEmpty($id);
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
        $this->assertEquals($id, $data['id']);
        // 'to' is now an array of address objects.
        $this->assertEquals($email, $data['to'][0]['address']);
        $this->assertEquals('welcome', $data['email_type']);
        $this->assertEquals(0, $data['attempts']);
    }

    public function test_spool_stores_email_content(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email, 'testpass123');

        $id = $this->spooler->spool($mailable, $email);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('html', $data);
        $this->assertStringContainsString('Welcome', $data['html']);
        $this->assertArrayHasKey('subject', $data);
    }

    public function test_get_backlog_stats_empty_queue(): void
    {
        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(0, $stats['pending_count']);
        $this->assertEquals(0, $stats['sending_count']);
        $this->assertEquals(0, $stats['failed_count']);
        $this->assertEquals('healthy', $stats['status']);
        $this->assertNull($stats['oldest_pending_at']);
    }

    public function test_get_backlog_stats_with_pending(): void
    {
        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        $this->spooler->spool($mailable, $email);
        $this->spooler->spool($mailable, $email);

        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(2, $stats['pending_count']);
        $this->assertEquals('healthy', $stats['status']);
        $this->assertNotNull($stats['oldest_pending_at']);
    }

    public function test_process_spool_sends_email(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.
        // Array mail driver (phpunit.xml) prevents actual sending.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);
        $id = $this->spooler->spool($mailable, $email);

        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['processed']);
        $this->assertEquals(1, $stats['sent']);
        $this->assertEquals(0, $stats['retried']);
        $this->assertEquals(0, $stats['stuck_alerts']);

        // File should be moved to sent.
        $this->assertFileDoesNotExist($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/sent/' . $id . '.json');

        // Mail::html sends a closure, not a Mailable class.
        // We verify the file was moved to sent directory as proof of success.
    }

    public function test_process_spool_respects_limit(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        // Spool 5 emails.
        for ($i = 0; $i < 5; $i++) {
            $this->spooler->spool($mailable, $email);
        }

        // Process only 2.
        $stats = $this->spooler->processSpool(limit: 2);

        $this->assertEquals(2, $stats['processed']);
        $this->assertEquals(2, $stats['sent']);

        // 3 should still be pending.
        $remaining = glob($this->testSpoolDir . '/pending/*.json');
        $this->assertCount(3, $remaining);
    }

    public function test_cleanup_sent_removes_old_files(): void
    {
        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        $email = $this->uniqueEmail('recipient');
        $mailable = new WelcomeMail($email);

        // Spool and process an email.
        $id = $this->spooler->spool($mailable, $email);
        $this->spooler->processSpool();

        // Backdate the sent file.
        $sentFile = $this->testSpoolDir . '/sent/' . $id . '.json';
        touch($sentFile, strtotime('-10 days'));

        $deleted = $this->spooler->cleanupSent(daysToKeep: 7);

        $this->assertEquals(1, $deleted);
        $this->assertFileDoesNotExist($sentFile);
    }

    public function test_cleanup_sent_skips_non_json_files(): void
    {
        // Create a non-json file in the sent directory.
        file_put_contents($this->testSpoolDir . '/sent/readme.txt', 'do not delete');

        // Create an old json file that should be deleted.
        $oldFile = $this->testSpoolDir . '/sent/old_mail.json';
        file_put_contents($oldFile, json_encode(['id' => 'old']));
        touch($oldFile, strtotime('-10 days'));

        // Create a recent json file that should be kept.
        $newFile = $this->testSpoolDir . '/sent/new_mail.json';
        file_put_contents($newFile, json_encode(['id' => 'new']));

        $deleted = $this->spooler->cleanupSent(daysToKeep: 7);

        $this->assertEquals(1, $deleted);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
        $this->assertFileExists($this->testSpoolDir . '/sent/readme.txt');
    }

    public function test_cleanup_sent_handles_many_files(): void
    {
        // Create 500 old files to verify DirectoryIterator handles batches.
        for ($i = 0; $i < 500; $i++) {
            $file = $this->testSpoolDir . '/sent/mail_' . $i . '.json';
            file_put_contents($file, json_encode(['id' => 'mail_' . $i]));
            touch($file, strtotime('-10 days'));
        }

        // Create 5 recent files that should be kept.
        for ($i = 0; $i < 5; $i++) {
            $file = $this->testSpoolDir . '/sent/recent_' . $i . '.json';
            file_put_contents($file, json_encode(['id' => 'recent_' . $i]));
        }

        $deleted = $this->spooler->cleanupSent(daysToKeep: 7);

        $this->assertEquals(500, $deleted);

        // Verify the recent files are still there.
        $remaining = glob($this->testSpoolDir . '/sent/*.json');
        $this->assertCount(5, $remaining);
    }

    public function test_retry_failed_moves_to_pending(): void
    {
        // Create a fake failed email file.
        $id = 'test_failed_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test']],
            'subject' => 'Test',
            'html' => '<p>Test</p>',
            'attempts' => 3,
            'last_error' => 'Connection failed',
        ];

        file_put_contents(
            $this->testSpoolDir . '/failed/' . $id . '.json',
            json_encode($data)
        );

        $result = $this->spooler->retryFailed($id);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->testSpoolDir . '/failed/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');

        // Check attempts was reset.
        $retried = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
        $this->assertEquals(0, $retried['attempts']);
        $this->assertNull($retried['last_error']);
    }

    public function test_retry_all_failed(): void
    {
        // Create multiple fake failed emails.
        for ($i = 0; $i < 3; $i++) {
            $id = 'test_failed_' . $i . '_' . uniqid();
            $data = [
                'id' => $id,
                'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
                'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test']],
                'subject' => 'Test ' . $i,
                'html' => '<p>Test</p>',
                'attempts' => 3,
                'last_error' => 'Error ' . $i,
            ];

            file_put_contents(
                $this->testSpoolDir . '/failed/' . $id . '.json',
                json_encode($data)
            );
        }

        $count = $this->spooler->retryAllFailed();

        $this->assertEquals(3, $count);

        $failed = glob($this->testSpoolDir . '/failed/*.json');
        $pending = glob($this->testSpoolDir . '/pending/*.json');

        $this->assertCount(0, $failed);
        $this->assertCount(3, $pending);
    }

    public function test_health_status_warning_on_large_queue(): void
    {
        // Create many pending files to trigger warning status.
        for ($i = 0; $i < 150; $i++) {
            $id = 'test_' . $i . '_' . uniqid();
            $data = [
                'id' => $id,
                'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
                'created_at' => now()->toIso8601String(),
            ];

            file_put_contents(
                $this->testSpoolDir . '/pending/' . $id . '.json',
                json_encode($data)
            );
        }

        $stats = $this->spooler->getBacklogStats();

        $this->assertEquals(150, $stats['pending_count']);
        $this->assertEquals('warning', $stats['status']);
    }

    /**
     * Test that custom headers added via withSymfonyMessage survive spooling.
     *
     * This is the key test that ensures headers are never lost through the spooler.
     */
    public function test_custom_headers_survive_spooling(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a test mailable that adds custom headers.
        $mailable = new class($recipientEmail, $fromEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with headers',
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>')
                    ->withSymfonyMessage(function (Email $message) {
                        $headers = $message->getHeaders();
                        $headers->addTextHeader('X-Custom-Test', 'test-value-123');
                        $headers->addTextHeader('X-Another-Header', 'another-value');
                        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/unsubscribe>');
                        $headers->addTextHeader('Feedback-Id', 'test:feedback:id');
                    });
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Read the spool file and verify headers are captured.
        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('headers', $data);
        $this->assertArrayHasKey('X-Custom-Test', $data['headers']);
        $this->assertEquals('test-value-123', $data['headers']['X-Custom-Test']);
        $this->assertArrayHasKey('X-Another-Header', $data['headers']);
        $this->assertEquals('another-value', $data['headers']['X-Another-Header']);
        $this->assertArrayHasKey('List-Unsubscribe', $data['headers']);
        $this->assertArrayHasKey('Feedback-Id', $data['headers']);
    }

    /**
     * Test that headers are applied when processing the spool.
     */
    public function test_headers_applied_when_sending_from_spool(): void
    {
        // Create a spool file with custom headers.
        $id = 'test_headers_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [
                'X-Custom-Header' => 'custom-value',
                'List-Unsubscribe' => '<https://example.com/unsubscribe>',
            ],
            'reply_to' => [['address' => $this->uniqueEmail('reply'), 'name' => 'Reply To']],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        // Process the spool.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent']);

        // Read the sent file to confirm it was processed.
        $sentData = json_decode(file_get_contents($this->testSpoolDir . '/sent/' . $id . '.json'), true);
        $this->assertEquals($id, $sentData['id']);

        // The headers should still be in the data.
        $this->assertArrayHasKey('X-Custom-Header', $sentData['headers']);
        $this->assertArrayHasKey('List-Unsubscribe', $sentData['headers']);
    }

    /**
     * Test that AMP content is preserved through spooling.
     */
    public function test_amp_content_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');

        // Create a test mailable that includes AMP content.
        $mailable = new class($recipientEmail, $fromEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with AMP',
                );
            }

            public function build(): static
            {
                $ampHtml = '<!doctype html><html amp4email><head></head><body>AMP Content</body></html>';

                return $this->html('<p>Regular HTML</p>')
                    ->withSymfonyMessage(function (Email $message) use ($ampHtml) {
                        // Build multipart/alternative with AMP part.
                        $textPart = new \Symfony\Component\Mime\Part\TextPart('Plain text', 'utf-8', 'plain');
                        $ampPart = new \Symfony\Component\Mime\Part\TextPart($ampHtml, 'utf-8', 'x-amp-html');
                        $htmlPart = new \Symfony\Component\Mime\Part\TextPart('<p>Regular HTML</p>', 'utf-8', 'html');
                        $alternativePart = new \Symfony\Component\Mime\Part\Multipart\AlternativePart($textPart, $ampPart, $htmlPart);
                        $message->setBody($alternativePart);
                    });
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        // Read the spool file and verify AMP content is captured.
        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('amp_html', $data);
        $this->assertNotNull($data['amp_html']);
        $this->assertStringContainsString('amp4email', $data['amp_html']);
        $this->assertStringContainsString('AMP Content', $data['amp_html']);
    }

    /**
     * Test that reply-to addresses are preserved through spooling.
     */
    public function test_reply_to_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');
        $replyToEmail = $this->uniqueEmail('reply');

        $mailable = new class($recipientEmail, $fromEmail, $replyToEmail) extends Mailable {
            public function __construct(private string $recipient, private string $fromAddress, private string $replyToAddress)
            {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with Reply-To',
                    replyTo: [new \Illuminate\Mail\Mailables\Address($this->replyToAddress, 'Reply Handler')],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('reply_to', $data);
        $this->assertNotEmpty($data['reply_to']);
        $this->assertEquals($replyToEmail, $data['reply_to'][0]['address']);
        $this->assertEquals('Reply Handler', $data['reply_to'][0]['name']);
    }

    /**
     * Test that BCC addresses are preserved through spooling.
     */
    public function test_bcc_preserved_in_spool(): void
    {
        $recipientEmail = $this->uniqueEmail('recipient');
        $fromEmail = $this->uniqueEmail('noreply');
        $bcc1Email = $this->uniqueEmail('bcc1');
        $bcc2Email = $this->uniqueEmail('bcc2');

        $mailable = new class($recipientEmail, $fromEmail, $bcc1Email, $bcc2Email) extends Mailable {
            public function __construct(
                private string $recipient,
                private string $fromAddress,
                private string $bcc1Address,
                private string $bcc2Address
            ) {
            }

            public function envelope(): Envelope
            {
                return new Envelope(
                    from: new \Illuminate\Mail\Mailables\Address($this->fromAddress, 'Test Sender'),
                    subject: 'Test with BCC',
                    bcc: [
                        new \Illuminate\Mail\Mailables\Address($this->bcc1Address, 'BCC User 1'),
                        new \Illuminate\Mail\Mailables\Address($this->bcc2Address, 'BCC User 2'),
                    ],
                );
            }

            public function build(): static
            {
                return $this->html('<p>Test content</p>');
            }
        };

        $id = $this->spooler->spool($mailable, $recipientEmail);

        $data = json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);

        $this->assertArrayHasKey('bcc', $data);
        $this->assertNotEmpty($data['bcc']);
        $this->assertCount(2, $data['bcc']);
        $this->assertEquals($bcc1Email, $data['bcc'][0]['address']);
        $this->assertEquals('BCC User 1', $data['bcc'][0]['name']);
        $this->assertEquals($bcc2Email, $data['bcc'][1]['address']);
        $this->assertEquals('BCC User 2', $data['bcc'][1]['name']);
    }

    /**
     * Test that BCC addresses are applied when sending from spool.
     */
    public function test_bcc_applied_when_sending_from_spool(): void
    {
        // Create a spool file with BCC addresses.
        $id = 'test_bcc_send_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => '<p>Test content</p>',
            'text' => 'Test content',
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [
                ['address' => $this->uniqueEmail('bcc1'), 'name' => 'BCC User 1'],
                ['address' => $this->uniqueEmail('bcc2'), 'name' => 'BCC User 2'],
            ],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Don't use Mail::fake() - it interferes with processSpool()'s Mail::html() call.

        // Process the spool.
        $stats = $this->spooler->processSpool();

        $this->assertEquals(1, $stats['sent']);

        // Read the sent file to confirm BCC data is preserved.
        $sentData = json_decode(file_get_contents($this->testSpoolDir . '/sent/' . $id . '.json'), true);
        $this->assertEquals($id, $sentData['id']);
        $this->assertCount(2, $sentData['bcc']);
    }

    /**
     * Test that plain text body is included in non-AMP emails when processing spool.
     *
     * This is critical for email clients like TrashNothing that parse the plain text
     * body of chat notification emails.
     */
    public function test_plain_text_body_included_in_non_amp_emails(): void
    {
        // Create a spool file with both HTML and text content (no AMP).
        $id = 'test_text_body_' . uniqid();
        $plainText = "New message from Test User\n\nHello, this is a test message.\n\nReply: https://example.com/chat/123";
        $htmlContent = '<p>New message from Test User</p><p>Hello, this is a test message.</p><a href="https://example.com/chat/123">Reply</a>';

        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => 'Test User']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Test Sender']],
            'subject' => 'Test Subject',
            'html' => $htmlContent,
            'text' => $plainText,
            'amp_html' => null,  // No AMP content.
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Capture the sent message to verify the body structure.
        $capturedMessage = null;

        // Use a custom mailer transport to capture the message.
        \Illuminate\Support\Facades\Mail::extend('capture', function () use (&$capturedMessage) {
            return new class($capturedMessage) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
                private mixed $capturedRef;

                public function __construct(&$captured)
                {
                    parent::__construct();
                    $this->capturedRef = &$captured;
                }

                protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
                {
                    $this->capturedRef = $message->getOriginalMessage();
                }

                public function __toString(): string
                {
                    return 'capture://';
                }
            };
        });

        // Configure the capture mailer and switch to it.
        $originalDriver = config('mail.default');
        config(['mail.mailers.capture' => ['transport' => 'capture']]);
        config(['mail.default' => 'capture']);

        // Clear ALL cached mailers so the new default config takes effect.
        // purge('capture') only clears that specific mailer, not the default.
        \Illuminate\Support\Facades\Mail::forgetMailers();

        // Process the spool.
        $stats = $this->spooler->processSpool();

        // Restore original driver.
        config(['mail.default' => $originalDriver]);

        $this->assertEquals(1, $stats['sent']);

        // Verify the message was captured and has the correct body structure.
        $this->assertNotNull($capturedMessage, 'Message should have been captured');
        $this->assertInstanceOf(Email::class, $capturedMessage);

        // The body should be multipart/alternative with text and HTML parts.
        $body = $capturedMessage->getBody();
        $this->assertInstanceOf(
            \Symfony\Component\Mime\Part\Multipart\AlternativePart::class,
            $body,
            'Email body should be multipart/alternative to include both text and HTML'
        );

        // Verify both parts are present.
        $parts = $body->getParts();
        $this->assertGreaterThanOrEqual(2, count($parts), 'Should have at least text and HTML parts');

        // Find text and HTML parts.
        $hasTextPart = false;
        $hasHtmlPart = false;
        foreach ($parts as $part) {
            if ($part instanceof \Symfony\Component\Mime\Part\TextPart) {
                if ($part->getMediaSubtype() === 'plain') {
                    $hasTextPart = true;
                    $this->assertStringContainsString('Hello, this is a test message', $part->getBody());
                } elseif ($part->getMediaSubtype() === 'html') {
                    $hasHtmlPart = true;
                }
            }
        }

        $this->assertTrue($hasTextPart, 'Email should have a text/plain part');
        $this->assertTrue($hasHtmlPart, 'Email should have a text/html part');
    }

    /**
     * Test that isPermanentSmtpFailure correctly identifies permanent errors.
     */
    public function test_is_permanent_smtp_failure_detects_hard_bounces(): void
    {
        $method = new \ReflectionMethod($this->spooler, 'isPermanentSmtpFailure');
        $method->setAccessible(true);

        // Permanent failures - should return true.
        $this->assertTrue($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown"'));
        $this->assertTrue($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "501", with message "501 5.1.3 Bad recipient address syntax"'));
        $this->assertTrue($method->invoke($this->spooler, 'Invalid addresses: non-ASCII characters not supported in local-part of email.'));
        $this->assertTrue($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "553", with message "553 5.1.3 Invalid address"'));
        $this->assertTrue($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "554", with message "554 Transaction failed"'));
        $this->assertTrue($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "551", with message "551 User not local"'));
        $this->assertTrue($method->invoke($this->spooler, '550 No such user - 5.2.1 Mailbox disabled'));

        // Transient failures - should return false.
        $this->assertFalse($method->invoke($this->spooler, 'Connection timed out'));
        $this->assertFalse($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "421", with message "421 Try again later"'));
        $this->assertFalse($method->invoke($this->spooler, 'Expected response code "250/251/252" but got code "450", with message "450 Mailbox busy"'));
        $this->assertFalse($method->invoke($this->spooler, 'Connection refused'));
        $this->assertFalse($method->invoke($this->spooler, 'DNS lookup failed'));
    }

    /**
     * Test that permanent SMTP failures move emails to failed/ and record a bounce.
     */
    public function test_permanent_smtp_failure_moves_to_failed_and_records_bounce(): void
    {
        // Create a test user — createTestUser() already creates a preferred email.
        // Use that email directly so checkAndSuspendUser finds the bounce on the preferred email.
        $user = $this->createTestUser();
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create a spool file addressed to that user's email.
        $id = 'test_bounce_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $userEmail->email, 'name' => '']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Freegle']],
            'subject' => 'Test Subject',
            'html' => '<p>Test</p>',
            'text' => 'Test',
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
            'last_attempt' => null,
            'last_error' => null,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Mock the Mail facade to throw a permanent SMTP error.
        \Illuminate\Support\Facades\Mail::shouldReceive('html')
            ->once()
            ->andThrow(new \Exception('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown"'));

        $stats = $this->spooler->processSpool();

        // Email should be in failed/, not pending/.
        $this->assertFileDoesNotExist($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/failed/' . $id . '.json');
        $this->assertEquals(1, $stats['bounced'] ?? 0);

        // Bounce should be recorded in bounces_emails.
        $bounceCount = DB::table('bounces_emails')
            ->where('emailid', $userEmail->id)
            ->where('permanent', 1)
            ->count();
        $this->assertGreaterThanOrEqual(1, $bounceCount);

        // User should be marked as bouncing (1 permanent bounce = suspension).
        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    /**
     * Test that transient SMTP failures still retry (move back to pending/).
     */
    public function test_transient_smtp_failure_retries(): void
    {
        $id = 'test_transient_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Freegle']],
            'subject' => 'Test Subject',
            'html' => '<p>Test</p>',
            'text' => 'Test',
            'headers' => [],
            'reply_to' => [],
            'cc' => [],
            'bcc' => [],
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
            'last_attempt' => null,
            'last_error' => null,
        ];

        file_put_contents(
            $this->testSpoolDir . '/pending/' . $id . '.json',
            json_encode($data)
        );

        // Mock the Mail facade to throw a transient error.
        \Illuminate\Support\Facades\Mail::shouldReceive('html')
            ->once()
            ->andThrow(new \Exception('Connection timed out'));

        $stats = $this->spooler->processSpool();

        // Email should be back in pending/ for retry.
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
        $this->assertFileDoesNotExist($this->testSpoolDir . '/failed/' . $id . '.json');
        $this->assertEquals(1, $stats['retried']);
        $this->assertEquals(0, $stats['bounced'] ?? 0);
    }

    /**
     * Test that a malformed recipient address — which throws while the message
     * is built, before it is ever written to the spool — records a permanent
     * bounce and is skipped, rather than escaping uncaught and killing the
     * queue task (Sentry BATCH-17 / BATCH-6).
     */
    public function test_spool_records_bounce_when_recipient_address_is_malformed(): void
    {
        // No "@" → Symfony throws RfcComplianceException ("... does not comply
        // with addr-spec of RFC 2822"), which the classifier treats as permanent.
        $malformed = 'kojopoku6_' . uniqid() . '.com';
        $user = $this->createTestUser(['email_preferred' => $malformed]);
        $userEmail = UserEmail::where('email', $malformed)->first();

        $mailable = new WelcomeMail($malformed);

        // Must not throw, and must not spool anything.
        $id = $this->spooler->spool($mailable, $malformed, 'welcome');

        $this->assertSame('', $id);
        $this->assertCount(0, glob($this->testSpoolDir . '/pending/*.json'));

        // Permanent bounce recorded against the offending address.
        $bounceCount = DB::table('bounces_emails')
            ->where('emailid', $userEmail->id)
            ->where('permanent', 1)
            ->count();
        $this->assertGreaterThanOrEqual(1, $bounceCount);

        // User flagged as bouncing (1 permanent bounce = suspension).
        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    /**
     * Test that a non-permanent build failure (e.g. infra/MJML) still
     * propagates from spool() instead of being silently swallowed.
     */
    public function test_spool_rethrows_non_permanent_build_failure(): void
    {
        $email = $this->uniqueEmail('recipient');

        // A mailable whose build throws a transient/unknown error — not an
        // address problem — must bubble up so it gets noticed, exactly as
        // before this change.
        $mailable = new class($email) extends Mailable {
            public function __construct(private string $recipient) {}

            public function build(): static
            {
                throw new \RuntimeException('MJML compilation failed: Could not resolve host: mjml');
            }
        };

        $this->expectException(\RuntimeException::class);

        $this->spooler->spool($mailable, $email);
    }

    /**
     * Test that a file orphaned in sending/ (left by a process that died
     * mid-send) is reclaimed back to pending/ on startup.
     *
     * The spooler runs single-process (numprocs=1), so at daemon startup
     * nothing is in flight — reclaiming is race-free and lossless.
     */
    public function test_reclaim_orphaned_sending_moves_file_back_to_pending(): void
    {
        // Simulate a process that died after moving the file to sending/.
        $id = 'test_orphan_' . uniqid();
        $data = [
            'id' => $id,
            'to' => [['address' => $this->uniqueEmail('recipient'), 'name' => '']],
            'from' => [['address' => $this->uniqueEmail('noreply'), 'name' => 'Freegle']],
            'subject' => 'Orphaned Subject',
            'html' => '<p>Test</p>',
            'attempts' => 0,
            'created_at' => now()->toIso8601String(),
        ];

        file_put_contents(
            $this->testSpoolDir . '/sending/' . $id . '.json',
            json_encode($data)
        );

        $reclaimed = $this->spooler->reclaimOrphanedSending();

        $this->assertEquals(1, $reclaimed);
        $this->assertFileDoesNotExist($this->testSpoolDir . '/sending/' . $id . '.json');
        $this->assertFileExists($this->testSpoolDir . '/pending/' . $id . '.json');
    }

    /**
     * Test that reclaim is a no-op (returns 0) when sending/ is empty.
     */
    public function test_reclaim_orphaned_sending_noop_when_empty(): void
    {
        $reclaimed = $this->spooler->reclaimOrphanedSending();

        $this->assertEquals(0, $reclaimed);
    }

    /**
     * Test that reclaim handles multiple orphaned files in one pass.
     */
    public function test_reclaim_orphaned_sending_handles_multiple_files(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $id = 'test_orphan_' . $i . '_' . uniqid();
            file_put_contents(
                $this->testSpoolDir . '/sending/' . $id . '.json',
                json_encode(['id' => $id])
            );
        }

        $reclaimed = $this->spooler->reclaimOrphanedSending();

        $this->assertEquals(3, $reclaimed);
        $this->assertCount(0, glob($this->testSpoolDir . '/sending/*.json'));
        $this->assertCount(3, glob($this->testSpoolDir . '/pending/*.json'));
    }
}
