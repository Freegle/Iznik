<?php

namespace Tests\Feature\Mail;

use App\Mail\Alert\AlertMail;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\Config;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\Support\MailpitHelper;
use Tests\TestCase;

/**
 * A message to a community's volunteers keeps its attachments and the member's quoted email all the
 * way through the spool to the delivered mail.
 */
class AlertAttachmentSpoolIntegrationTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', 'mailpit');
        Config::set('mail.mailers.smtp.port', 1025);
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    public function test_delivered_mail_has_the_attachments_and_the_quoted_email(): void
    {
        $email = 'alert_' . uniqid('', true) . '@example.com';
        $png = "\x89PNG\r\n\x1a\n" . str_repeat('x', 200);

        app(EmailSpoolerService::class)->spool(new AlertMail(
            recipientEmail: $email,
            recipientName: 'Mod',
            fromAddress: 'support@ilovefreegle.org',
            fromName: 'Freegle Support',
            subjectLine: 'About a member',
            htmlBody: '<p>Please look at this.</p>',
            textBody: 'Please look at this.',
            quoted: 'Dear Freegle, the whole of the member email.',
            requireReply: true,
            files: [
                ['name' => 'one.png', 'mime' => 'image/png', 'data' => $png],
                ['name' => 'two.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 fake'],
            ],
        ), $email, 'alert');

        $stats = app(EmailSpoolerService::class)->processSpool();
        $this->assertSame(1, $stats['sent']);

        $mailpit = new MailpitHelper('http://mailpit:8025');
        $summary = $mailpit->assertMessageSentTo($email);
        $message = $mailpit->getMessage($summary['ID']);

        $names = array_column($message['Attachments'] ?? [], 'FileName');
        sort($names);
        $this->assertSame(['one.png', 'two.pdf'], $names);
        $this->assertStringContainsString('the whole of the member email', $message['HTML'] ?? '');
    }
}
