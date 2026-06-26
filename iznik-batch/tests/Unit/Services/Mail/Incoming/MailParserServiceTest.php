<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\ParsedEmail;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

class MailParserServiceTest extends TestCase
{
    use EmailFixtures;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MailParserService;
    }

    // ========================================
    // Basic Parsing Tests
    // ========================================

    public function test_parse_extracts_subject(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertInstanceOf(ParsedEmail::class, $parsed);
        $this->assertEquals('OFFER: Test item (Test Location)', $parsed->subject);
    }

    public function test_parse_extracts_from_address(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertEquals('test@test.com', $parsed->fromAddress);
        $this->assertEquals('Test User', $parsed->fromName);
    }

    public function test_parse_extracts_to_addresses(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertContains('testgroup@groups.ilovefreegle.org', $parsed->toAddresses);
    }

    public function test_parse_extracts_message_id(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertEquals('test-message-id-12345@test.com', $parsed->messageId);
    }

    public function test_parse_extracts_date(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertNotNull($parsed->date);
        $this->assertEquals('2026-01-27', $parsed->date->format('Y-m-d'));
    }

    public function test_parse_extracts_text_body(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertStringContainsString('test offer message', $parsed->textBody);
        $this->assertStringContainsString('test item available', $parsed->textBody);
    }

    public function test_parse_extracts_html_body(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertStringContainsString('<p>This is a test offer message.</p>', $parsed->htmlBody);
    }

    public function test_parse_stores_envelope_addresses(): void
    {
        $rawEmail = $this->loadEmailFixture('basic');

        $parsed = $this->parser->parse($rawEmail, 'envelope-from@test.com', 'envelope-to@groups.ilovefreegle.org');

        $this->assertEquals('envelope-from@test.com', $parsed->envelopeFrom);
        $this->assertEquals('envelope-to@groups.ilovefreegle.org', $parsed->envelopeTo);
    }

    public function test_parse_stores_raw_message(): void
    {
        $rawEmail = $this->loadEmailFixture('basic');
        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'testgroup@groups.ilovefreegle.org');

        $this->assertEquals($rawEmail, $parsed->rawMessage);
    }

    // ========================================
    // Header Extraction Tests
    // ========================================

    public function test_parse_extracts_arbitrary_headers(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'X-Custom-Header' => 'custom-value',
            'X-Another-Header' => 'another-value',
        ], 'Test body');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'recipient@test.com');

        $this->assertEquals('custom-value', $parsed->getHeader('x-custom-header'));
        $this->assertEquals('another-value', $parsed->getHeader('x-another-header'));
    }

    public function test_get_header_returns_null_for_missing_header(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertNull($parsed->getHeader('x-nonexistent-header'));
    }

    // ========================================
    // Bounce Detection Tests
    // ========================================

    public function test_parse_detects_bounce_message(): void
    {
        $rawEmail = $this->loadEmailFixture('bounce');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@ilovefreegle.org', 'bounce-3743448-1479334364@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce());
    }

    public function test_parse_extracts_bounce_recipient(): void
    {
        $rawEmail = $this->loadEmailFixture('bounce');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@ilovefreegle.org', 'bounce-3743448-1479334364@users.ilovefreegle.org');

        $this->assertEquals('bounced@example.com', $parsed->bounceRecipient);
    }

    public function test_parse_extracts_bounce_status(): void
    {
        $rawEmail = $this->loadEmailFixture('bounce');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@ilovefreegle.org', 'bounce-3743448-1479334364@users.ilovefreegle.org');

        $this->assertEquals('5.0.0', $parsed->bounceStatus);
        $this->assertTrue($parsed->isPermanentBounce());
    }

    public function test_parse_extracts_bounce_diagnostic(): void
    {
        $rawEmail = $this->loadEmailFixture('bounce');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@ilovefreegle.org', 'bounce-3743448-1479334364@users.ilovefreegle.org');

        $this->assertStringContainsString('mailbox unavailable', $parsed->bounceDiagnostic);
    }

    public function test_permanent_bounce_starts_with_5(): void
    {
        $rawEmail = $this->createBounceEmail('test@example.com', '5.1.1', 'User unknown');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@test.com', 'bounce@users.ilovefreegle.org');

        $this->assertTrue($parsed->isPermanentBounce());
    }

    public function test_dynamic_bounce_extracts_recipient(): void
    {
        $expectedEmail = 'bounced-user-'.uniqid().'@example.com';
        $rawEmail = $this->createBounceEmail($expectedEmail, '5.1.1', 'User unknown');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@test.com', 'bounce-12345@users.ilovefreegle.org');

        $this->assertEquals($expectedEmail, $parsed->bounceRecipient);
    }

    public function test_temporary_bounce_starts_with_4(): void
    {
        $rawEmail = $this->createBounceEmail('test@example.com', '4.2.2', 'Mailbox full');

        $parsed = $this->parser->parse($rawEmail, 'MAILER-DAEMON@test.com', 'bounce@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce());
        $this->assertFalse($parsed->isPermanentBounce());
    }

    public function test_bounce_detected_with_empty_envelope_from(): void
    {
        // RFC 5321: Bounces (DSNs) should use empty envelope-from (null sender)
        // Postfix often sends bounces with MAIL FROM:<>
        $rawEmail = $this->createBounceEmail('test@example.com', '5.1.1', 'User unknown');

        // Empty string represents null sender <>
        $parsed = $this->parser->parse($rawEmail, '', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Bounce with empty envelope-from should be detected');
        $this->assertEquals('test@example.com', $parsed->bounceRecipient);
    }

    public function test_bounce_detected_with_postmaster_envelope_from(): void
    {
        // Some MTAs use postmaster@ as envelope-from for bounces
        $rawEmail = $this->createBounceEmail('test@example.com', '5.1.1', 'User unknown');

        $parsed = $this->parser->parse($rawEmail, 'postmaster@bulk2.ilovefreegle.org', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Bounce with postmaster envelope-from should be detected');
    }

    public function test_bounce_detected_by_content_type_regardless_of_envelope(): void
    {
        // If Content-Type is multipart/report with delivery-status, it's a bounce
        // regardless of envelope-from
        $rawEmail = $this->createBounceEmail('test@example.com', '5.1.1', 'User unknown');

        // Even with a regular user email as envelope-from, content-type should detect it
        $parsed = $this->parser->parse($rawEmail, 'someuser@example.com', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Bounce should be detected by Content-Type even with regular envelope-from');
    }

    public function test_bounce_detected_by_subject_heuristics(): void
    {
        // Legacy compatibility: detect bounces by subject even without proper DSN format
        // This matches the behavior in iznik-server/include/message/Message.php
        $rawEmail = $this->createMinimalEmail([
            'From' => 'someuser@example.com',
            'To' => 'notify-123-456@users.ilovefreegle.org',
            'Subject' => 'Undelivered Mail Returned to Sender',
        ], 'Your message could not be delivered.');

        $parsed = $this->parser->parse($rawEmail, 'someuser@example.com', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Bounce should be detected by subject pattern');
    }

    public function test_bounce_detected_by_delivery_status_notification_subject(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'postmaster@example.com',
            'To' => 'notify-123-456@users.ilovefreegle.org',
            'Subject' => 'Delivery Status Notification (Failure)',
        ], 'The email account that you tried to reach does not exist.');

        $parsed = $this->parser->parse($rawEmail, 'postmaster@example.com', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Delivery Status Notification subject should detect bounce');
    }

    public function test_bounce_detected_by_mail_delivery_failed_subject(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'mailer@example.com',
            'To' => 'notify-123-456@users.ilovefreegle.org',
            'Subject' => 'Mail delivery failed: returning message to sender',
        ], 'A message that you sent could not be delivered.');

        $parsed = $this->parser->parse($rawEmail, 'mailer@example.com', 'notify-123-456@users.ilovefreegle.org');

        $this->assertTrue($parsed->isBounce(), 'Mail delivery failed subject should detect bounce');
    }

    public function test_regular_email_not_detected_as_bounce(): void
    {
        // Ensure we don't false-positive on regular emails
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'notify-123-456@users.ilovefreegle.org',
            'Subject' => 'Re: Your item on Freegle',
        ], 'Yes I am still interested in the item.');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'notify-123-456@users.ilovefreegle.org');

        $this->assertFalse($parsed->isBounce(), 'Regular email should not be detected as bounce');
    }

    // ========================================
    // Chat Reply Detection Tests
    // ========================================

    public function test_parse_detects_chat_notification_reply(): void
    {
        $rawEmail = $this->loadEmailFixture('chat_reply');

        $parsed = $this->parser->parse($rawEmail, 'replier@example.com', 'notify-12345-67890-111@users.ilovefreegle.org');

        $this->assertTrue($parsed->isChatNotificationReply());
    }

    public function test_parse_extracts_chat_ids_from_notify_address(): void
    {
        $rawEmail = $this->loadEmailFixture('chat_reply');

        $parsed = $this->parser->parse($rawEmail, 'replier@example.com', 'notify-12345-67890-111@users.ilovefreegle.org');

        $this->assertEquals(12345, $parsed->chatId);
        $this->assertEquals(67890, $parsed->chatUserId);
        $this->assertEquals(111, $parsed->chatMessageId);
    }

    public function test_chat_notification_reply_without_message_id(): void
    {
        $rawEmail = $this->createMinimalEmail([], 'Reply content');

        $parsed = $this->parser->parse($rawEmail, 'replier@example.com', 'notify-12345-67890@users.ilovefreegle.org');

        $this->assertTrue($parsed->isChatNotificationReply());
        $this->assertEquals(12345, $parsed->chatId);
        $this->assertEquals(67890, $parsed->chatUserId);
        $this->assertNull($parsed->chatMessageId);
    }

    // ========================================
    // Auto-Reply Detection Tests
    // ========================================

    public function test_parse_detects_auto_submitted_header(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'Auto-Submitted' => 'auto-replied',
        ], 'Auto-reply content');

        $parsed = $this->parser->parse($rawEmail, 'autoresponder@test.com', 'recipient@test.com');

        $this->assertTrue($parsed->isAutoReply());
    }

    public function test_parse_detects_auto_generated_header(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'Auto-Submitted' => 'auto-generated',
        ], 'Auto-generated content');

        $parsed = $this->parser->parse($rawEmail, 'system@test.com', 'recipient@test.com');

        $this->assertTrue($parsed->isAutoReply());
    }

    public function test_normal_message_is_not_auto_reply(): void
    {
        $parsed = $this->parseFixture('basic');

        $this->assertFalse($parsed->isAutoReply());
    }

    // ========================================
    // Group Detection Tests
    // ========================================

    public function test_parse_extracts_group_name_from_envelope_to(): void
    {
        $rawEmail = $this->loadEmailFixture('basic');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'mygroup@groups.ilovefreegle.org');

        $this->assertEquals('mygroup', $parsed->targetGroupName);
    }

    public function test_parse_detects_volunteers_address(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'To' => 'testgroup-volunteers@groups.ilovefreegle.org',
        ], 'Message to volunteers');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'testgroup-volunteers@groups.ilovefreegle.org');

        $this->assertTrue($parsed->isToVolunteers());
        $this->assertEquals('testgroup', $parsed->targetGroupName);
    }

    public function test_parse_detects_auto_address(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'To' => 'testgroup-auto@groups.ilovefreegle.org',
        ], 'Automated message');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'testgroup-auto@groups.ilovefreegle.org');

        $this->assertTrue($parsed->isToAuto());
        $this->assertEquals('testgroup', $parsed->targetGroupName);
    }

    // ========================================
    // Email Command Detection Tests
    // ========================================

    public function test_parse_detects_subscribe_command(): void
    {
        $rawEmail = $this->createMinimalEmail([], 'Subscribe me');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'testgroup-subscribe@groups.ilovefreegle.org');

        $this->assertTrue($parsed->isSubscribeCommand());
        $this->assertEquals('testgroup', $parsed->targetGroupName);
    }

    public function test_parse_detects_unsubscribe_command(): void
    {
        $rawEmail = $this->createMinimalEmail([], 'Unsubscribe me');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'testgroup-unsubscribe@groups.ilovefreegle.org');

        $this->assertTrue($parsed->isUnsubscribeCommand());
        $this->assertEquals('testgroup', $parsed->targetGroupName);
    }

    public function test_parse_detects_digestoff_command(): void
    {
        $rawEmail = $this->createMinimalEmail([], '');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'digestoff-12345-67890@users.ilovefreegle.org');

        $this->assertTrue($parsed->isDigestOffCommand());
        $this->assertEquals(12345, $parsed->commandUserId);
        $this->assertEquals(67890, $parsed->commandGroupId);
    }

    // ========================================
    // Error Handling Tests
    // ========================================

    public function test_parse_handles_malformed_email_gracefully(): void
    {
        $rawEmail = 'This is not a valid email';

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'test@test.com');

        $this->assertInstanceOf(ParsedEmail::class, $parsed);
        $this->assertEquals('test@test.com', $parsed->envelopeFrom);
        $this->assertEquals('test@test.com', $parsed->envelopeTo);
    }

    public function test_parse_handles_empty_body(): void
    {
        $rawEmail = $this->createMinimalEmail(['Subject' => 'Empty Body'], '');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'recipient@test.com');

        $this->assertInstanceOf(ParsedEmail::class, $parsed);
        $this->assertEquals('Empty Body', $parsed->subject);
        $this->assertEmpty($parsed->textBody);
    }

    // ========================================
    // IP Address Extraction Tests
    // ========================================

    public function test_parse_extracts_ip_from_x_originating_ip(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'X-Originating-IP' => '[192.0.2.50]',
        ], 'Test');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'recipient@test.com');

        $this->assertEquals('192.0.2.50', $parsed->senderIp);
    }

    public function test_parse_extracts_ip_from_x_freegle_ip(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'X-Freegle-IP' => '192.0.2.51',
        ], 'Test');

        $parsed = $this->parser->parse($rawEmail, 'test@test.com', 'recipient@test.com');

        $this->assertEquals('192.0.2.51', $parsed->senderIp);
    }

    // ========================================
    // Digest Reply Detection Tests
    // ========================================

    public function test_parse_detects_digest_reply_to_noreply_address(): void
    {
        $noreplyAddr = config('freegle.mail.noreply_addr');

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => $noreplyAddr,
            'Subject' => 'Re: 5 new posts near you - Sofa, Table',
        ], 'Thanks for the digest!');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', $noreplyAddr);

        $this->assertTrue($parsed->isDigestReply());
    }

    public function test_parse_detects_digest_reply_via_in_reply_to_header(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someaddress@example.com',
            'Subject' => 'Re: 3 new posts near you',
            'In-Reply-To' => '<UnifiedDigest-12345-67890@ilovefreegle.org>',
        ], 'I want the sofa!');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'someaddress@example.com');

        $this->assertTrue($parsed->isDigestReply());
    }

    public function test_parse_non_digest_email_not_flagged_as_digest_reply(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'testgroup@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Sofa (London)',
        ], 'Free sofa available.');

        $parsed = $this->parser->parse($rawEmail, 'user@example.com', 'testgroup@groups.ilovefreegle.org');

        $this->assertFalse($parsed->isDigestReply());
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Parse a fixture file using default envelope addresses.
     */
    private function parseFixture(string $name): ParsedEmail
    {
        $rawEmail = $this->loadEmailFixture($name);

        // Use envelope addresses appropriate for the fixture type
        $envelopeFrom = match ($name) {
            'bounce' => 'MAILER-DAEMON@ilovefreegle.org',
            'tn_post' => 'user@trashnothing.com',
            'chat_reply' => 'replier@example.com',
            default => 'test@test.com',
        };

        $envelopeTo = match ($name) {
            'bounce' => 'bounce-3743448-1479334364@users.ilovefreegle.org',
            'chat_reply' => 'notify-12345-67890-111@users.ilovefreegle.org',
            default => 'testgroup@groups.ilovefreegle.org',
        };

        return $this->parser->parse($rawEmail, $envelopeFrom, $envelopeTo);
    }
}
