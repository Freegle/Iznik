<?php

namespace Tests\Support;

/**
 * Trait for working with email test fixtures.
 *
 * Provides helper methods for loading and manipulating test email files.
 */
trait EmailFixtures
{
    /**
     * Get the path to the email fixtures directory.
     */
    protected function getEmailFixturesPath(): string
    {
        return base_path('tests/fixtures/emails');
    }

    /**
     * Get the path to a specific email fixture file.
     */
    protected function getEmailFixturePath(string $name): string
    {
        $path = "{$this->getEmailFixturesPath()}/{$name}.eml";

        if (! file_exists($path)) {
            throw new \RuntimeException("Email fixture not found: {$path}");
        }

        return $path;
    }

    /**
     * Load the contents of an email fixture file.
     */
    protected function loadEmailFixture(string $name): string
    {
        return file_get_contents($this->getEmailFixturePath($name));
    }

    /**
     * Create a minimal valid email for testing.
     *
     * @param  array<string, string>  $headers  Headers to set (From, To, Subject, etc.)
     * @param  string  $body  The email body
     * @return string Raw email string
     */
    protected function createMinimalEmail(array $headers = [], string $body = ''): string
    {
        $defaults = [
            'From' => 'test@test.com',
            'To' => 'recipient@test.com',
            'Subject' => 'Test Subject',
            'Date' => 'Tue, 27 Jan 2026 10:00:00 +0000',
            'Message-ID' => '<test-'.uniqid().'@test.com>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];

        $merged = array_merge($defaults, $headers);

        $headerLines = [];
        foreach ($merged as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $headerLines)."\r\n\r\n".$body;
    }

    /**
     * Create a multipart email for testing.
     *
     * @param  array<string, string>  $headers  Headers to set
     * @param  string  $textBody  Plain text body
     * @param  string  $htmlBody  HTML body
     * @return string Raw email string
     */
    protected function createMultipartEmail(array $headers = [], string $textBody = '', string $htmlBody = ''): string
    {
        $boundary = '----=_Part_'.uniqid();

        $defaults = [
            'From' => 'test@test.com',
            'To' => 'recipient@test.com',
            'Subject' => 'Test Subject',
            'Date' => 'Tue, 27 Jan 2026 10:00:00 +0000',
            'Message-ID' => '<test-'.uniqid().'@test.com>',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative;\r\n boundary=\"{$boundary}\"",
        ];

        $merged = array_merge($defaults, $headers);

        $headerLines = [];
        foreach ($merged as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $parts = [];

        if ($textBody) {
            $parts[] = "Content-Type: text/plain; charset=utf-8\r\n\r\n{$textBody}";
        }

        if ($htmlBody) {
            $parts[] = "Content-Type: text/html; charset=utf-8\r\n\r\n{$htmlBody}";
        }

        $body = "--{$boundary}\r\n".implode("\r\n--{$boundary}\r\n", $parts)."\r\n--{$boundary}--";

        return implode("\r\n", $headerLines)."\r\n\r\n".$body;
    }

    /**
     * Create a bounce message for testing.
     *
     * @param  string  $originalRecipient  The email that bounced
     * @param  string  $status  DSN status code (e.g., '5.0.0', '4.2.2')
     * @param  string  $diagnostic  Diagnostic message
     * @return string Raw bounce email
     */
    protected function createBounceEmail(
        string $originalRecipient,
        string $status = '5.0.0',
        string $diagnostic = 'mailbox unavailable'
    ): string {
        $boundary = 'Bounce_'.uniqid().'/bulk2.ilovefreegle.org';
        $messageId = '<bounce-'.uniqid().'@bulk2.ilovefreegle.org>';
        $date = date('r');

        $headers = [
            'Received' => 'by bulk2.ilovefreegle.org (Postfix)',
            'Date' => $date,
            'From' => 'MAILER-DAEMON@ilovefreegle.org (Mail Delivery System)',
            'Subject' => 'Undelivered Mail Returned to Sender',
            'To' => 'bounce-12345-67890@users.ilovefreegle.org',
            'Auto-Submitted' => 'auto-replied',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/report; report-type=delivery-status;\r\n\tboundary=\"{$boundary}\"",
            'Message-Id' => $messageId,
        ];

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $notification = "This is the mail system at host bulk2.ilovefreegle.org.\r\n\r\n".
            "I'm sorry to have to inform you that your message could not\r\n".
            "be delivered to one or more recipients.\r\n\r\n".
            "                   The mail system\r\n\r\n".
            "<{$originalRecipient}>: host mx.example.com[192.0.2.1] said: {$diagnostic}";

        $deliveryStatus = "Reporting-MTA: dns; bulk2.ilovefreegle.org\r\n".
            "X-Postfix-Queue-ID: ABCD1234\r\n".
            "Arrival-Date: {$date}\r\n\r\n".
            "Final-Recipient: rfc822; {$originalRecipient}\r\n".
            "Original-Recipient: rfc822;{$originalRecipient}\r\n".
            "Action: failed\r\n".
            "Status: {$status}\r\n".
            "Remote-MTA: dns; mx.example.com\r\n".
            "Diagnostic-Code: smtp; {$diagnostic}";

        $body = "This is a MIME-encapsulated message.\r\n\r\n".
            "--{$boundary}\r\n".
            "Content-Description: Notification\r\n".
            "Content-Type: text/plain; charset=us-ascii\r\n\r\n".
            "{$notification}\r\n\r\n".
            "--{$boundary}\r\n".
            "Content-Description: Delivery report\r\n".
            "Content-Type: message/delivery-status\r\n\r\n".
            "{$deliveryStatus}\r\n\r\n".
            "--{$boundary}--";

        return implode("\r\n", $headerLines)."\r\n\r\n".$body;
    }
}
