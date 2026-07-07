<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Models\EmailTracking;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\Mail\Incoming\BounceService;
use App\Services\Mail\Incoming\ParsedEmail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BounceServiceTest extends TestCase
{
    private BounceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BounceService;
    }

    /**
     * Create a ParsedEmail instance for testing.
     */
    private function createParsedEmail(array $overrides = []): ParsedEmail
    {
        $defaults = [
            'rawMessage' => 'Raw email message',
            'envelopeFrom' => 'sender@example.com',
            'envelopeTo' => 'recipient@example.com',
            'subject' => 'Test Subject',
            'fromAddress' => 'sender@example.com',
            'fromName' => 'Test Sender',
            'toAddresses' => ['recipient@example.com'],
            'messageId' => '<test@example.com>',
            'date' => null,
            'textBody' => 'Test body',
            'htmlBody' => null,
            'headers' => [],
            'targetGroupName' => null,
            'isToVolunteers' => false,
            'isToAuto' => false,
            'bounceRecipient' => null,
            'bounceStatus' => null,
            'bounceDiagnostic' => null,
            'chatId' => null,
            'chatUserId' => null,
            'chatMessageId' => null,
            'commandUserId' => null,
            'commandGroupId' => null,
            'senderIp' => null,
        ];

        $params = array_merge($defaults, $overrides);

        return new ParsedEmail(
            $params['rawMessage'],
            $params['envelopeFrom'],
            $params['envelopeTo'],
            $params['subject'],
            $params['fromAddress'],
            $params['fromName'],
            $params['toAddresses'],
            $params['messageId'],
            $params['date'],
            $params['textBody'],
            $params['htmlBody'],
            $params['headers'],
            $params['targetGroupName'],
            $params['isToVolunteers'],
            $params['isToAuto'],
            $params['bounceRecipient'],
            $params['bounceStatus'],
            $params['bounceDiagnostic'],
            $params['chatId'],
            $params['chatUserId'],
            $params['chatMessageId'],
            $params['commandUserId'],
            $params['commandGroupId'],
            $params['senderIp']
        );
    }

    // ===================================================================
    // DSN Parsing Tests
    // ===================================================================

    public function test_extracts_diagnostic_code_from_standard_dsn(): void
    {
        $dsnBody = <<<'DSN'
From: Mail Delivery System <MAILER-DAEMON@example.com>
To: bounce-12345-1234567890@users.ilovefreegle.org
Subject: Mail delivery failed

Diagnostic-Code: smtp; 550 5.1.1 The email account that you tried to reach does not exist.
Original-Recipient: rfc822;test@example.com
Action: failed
Status: 5.1.1
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result);
        $this->assertStringContainsString('550 5.1.1', $result['diagnostic_code']);
        $this->assertEquals('test@example.com', $result['original_recipient']);
    }

    public function test_extracts_recipient_from_original_recipient_header(): void
    {
        $dsnBody = <<<'DSN'
Diagnostic-Code: smtp; 550 User unknown
Original-Recipient: rfc822;recipient@example.com
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertEquals('recipient@example.com', $result['original_recipient']);
    }

    public function test_extracts_recipient_from_final_recipient_header(): void
    {
        $dsnBody = <<<'DSN'
Diagnostic-Code: smtp; 550 User unknown
Final-Recipient: rfc822;finaluser@example.com
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertEquals('finaluser@example.com', $result['original_recipient']);
    }

    public function test_extracts_recipient_from_x_failed_recipients_header(): void
    {
        $dsnBody = <<<'DSN'
X-Failed-Recipients: failed@example.com
Content-Type: message/delivery-status

550 User not found
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertEquals('failed@example.com', $result['original_recipient']);
    }

    public function test_extracts_diagnostic_from_body_when_no_header(): void
    {
        // Heuristic extraction from body text
        $dsnBody = <<<'DSN'
Subject: Delivery Status Notification (Failure)

This is an automatically generated Delivery Status Notification.

Delivery to the following recipient failed permanently:

     user@example.com

Technical details of permanent failure:
Google tried to deliver your message, but it was rejected.

550-5.1.1 The email account that you tried to reach does not exist. Please try
550-5.1.1 double-checking the recipient's email address for typos or
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result);
        $this->assertStringContainsString('550', $result['diagnostic_code']);
        $this->assertEquals('user@example.com', $result['original_recipient']);
    }

    public function test_returns_null_for_unparseable_dsn(): void
    {
        $dsnBody = 'This is not a valid DSN message at all.';

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNull($result);
    }

    public function test_parses_temporary_4xx_dsn_status(): void
    {
        // Plus.net style delay notification with Status: 4.0.0
        $dsnBody = <<<'DSN'
From: Mail Delivery System <Mailer-Daemon@mx.example.net>
Subject:

This message was created automatically by mail delivery software.
A message that you sent has not yet been delivered to one or more of its
recipients after more than 24 hours on the queue.

  fullbox@example.com
    Delay reason: mailbox is full

--boundary
Content-type: message/delivery-status

Reporting-MTA: dns; mx.example.net

Action: delayed
Final-Recipient: rfc822;fullbox@example.com
Status: 4.0.0
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result, 'parseDsn should not return null for 4.x.x status codes');
        $this->assertStringContainsString('4.0.0', $result['diagnostic_code']);
        $this->assertEquals('fullbox@example.com', $result['original_recipient']);
    }

    public function test_parses_temporary_dsn_447_delivery_expired(): void
    {
        // BT Internet style DSN with Status: 4.4.7 (delivery time expired)
        $dsnBody = <<<'DSN'
From: Mail Delivery Service <postmaster@mx.example.net>
Subject: Delivery Status Notification

 - These recipients of your message have been processed by the mail server:
forwarded@example.net; Failed; 4.4.7 (delivery time expired)

--boundary
Content-Type: Message/Delivery-Status

Reporting-MTA: dns; mx.example.net

Original-Recipient: rfc822;original@example.com
Final-Recipient: rfc822; forwarded@example.net
Action: Failed
Status: 4.4.7 (delivery time expired)
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result, 'parseDsn should not return null for 4.4.7 status');
        $this->assertStringContainsString('4.4.7', $result['diagnostic_code']);
        $this->assertEquals('original@example.com', $result['original_recipient']);
    }

    // ===================================================================
    // Bounce Classification Tests
    // ===================================================================

    public function test_classifies_550_mailbox_unavailable_as_permanent(): void
    {
        $code = '550 Requested action not taken: mailbox unavailable';

        $this->assertTrue($this->service->isPermanentBounce($code));
    }

    public function test_classifies_550_5_1_1_as_permanent(): void
    {
        $code = '550 5.1.1 The email account does not exist';

        $this->assertTrue($this->service->isPermanentBounce($code));
    }

    public function test_classifies_invalid_recipient_as_permanent(): void
    {
        $code = 'Invalid recipient <test@example.com>';

        $this->assertTrue($this->service->isPermanentBounce($code));
    }

    public function test_classifies_550_no_such_user_as_permanent(): void
    {
        $code = '550 No Such User Here';

        $this->assertTrue($this->service->isPermanentBounce($code));
    }

    public function test_classifies_user_doesnt_have_account_as_permanent(): void
    {
        $code = "dd This user doesn't have a yahoo.com account";

        $this->assertTrue($this->service->isPermanentBounce($code));
    }

    public function test_classifies_temporary_suspension_as_ignorable(): void
    {
        $code = 'delivery temporarily suspended';

        $this->assertTrue($this->service->shouldIgnoreBounce($code));
    }

    public function test_classifies_trop_de_connexions_as_ignorable(): void
    {
        $code = 'Trop de connexions depuis cette adresse';

        $this->assertTrue($this->service->shouldIgnoreBounce($code));
    }

    public function test_classifies_blacklist_as_ignorable(): void
    {
        $code = 'Your IP was found on industry URI blacklists';

        $this->assertTrue($this->service->shouldIgnoreBounce($code));
    }

    public function test_classifies_message_blocked_as_ignorable(): void
    {
        $code = 'This message has been blocked';

        $this->assertTrue($this->service->shouldIgnoreBounce($code));
    }

    public function test_classifies_is_listed_as_ignorable(): void
    {
        $code = 'Your server is listed on a blacklist';

        $this->assertTrue($this->service->shouldIgnoreBounce($code));
    }

    public function test_classifies_421_temporary_error_as_not_permanent(): void
    {
        $code = '421 Try again later';

        $this->assertFalse($this->service->isPermanentBounce($code));
    }

    public function test_classifies_generic_error_as_not_permanent(): void
    {
        $code = 'Some generic delivery error';

        $this->assertFalse($this->service->isPermanentBounce($code));
    }

    // ===================================================================
    // Bounce Recording Tests
    // ===================================================================

    public function test_records_bounce_in_bounces_emails_table(): void
    {
        $user = $this->createTestUser();
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        $this->service->recordBounce($email->id, 'smtp; 550 User unknown', true);

        $this->assertDatabaseHas('bounces_emails', [
            'emailid' => $email->id,
            'permanent' => 1,
            'reset' => 0,
        ]);
    }

    public function test_records_temporary_bounce(): void
    {
        $user = $this->createTestUser();
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        $this->service->recordBounce($email->id, 'smtp; 421 Try again later', false);

        $this->assertDatabaseHas('bounces_emails', [
            'emailid' => $email->id,
            'permanent' => 0,
            'reset' => 0,
        ]);
    }

    public function test_updates_users_emails_bounced_timestamp(): void
    {
        $user = $this->createTestUser();
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Ensure bounced is null initially
        $email->bounced = null;
        $email->save();

        $this->service->recordBounce($email->id, 'smtp; 550 User unknown', true);

        $email->refresh();
        $this->assertNotNull($email->bounced);
    }

    // ===================================================================
    // User Suspension Tests
    // ===================================================================

    public function test_suspends_user_after_1_permanent_bounce_on_preferred_email(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 1 permanent bounce - industry standard is immediate suspension
        DB::table('bounces_emails')->insert([
            'emailid' => $email->id,
            'reason' => '550 User unknown',
            'permanent' => 1,
            'reset' => 0,
            'date' => now(),
        ]);

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    public function test_suspends_user_after_50_total_bounces_on_preferred_email(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 50 temporary bounces
        for ($i = 0; $i < 50; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again later',
                'permanent' => 0,
                'reset' => 0,
                'date' => now(),
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    public function test_does_not_suspend_with_0_permanent_bounces(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);

        // No bounces at all - user should not be suspended
        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_suspends_user_after_5_soft_bounces_within_14_days(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 5 soft bounces within the last 14 days
        for ($i = 0; $i < 5; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again later',
                'permanent' => 0,
                'reset' => 0,
                'date' => now()->subDays($i), // Spread over last 5 days
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    public function test_does_not_suspend_with_4_soft_bounces_within_14_days(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create only 4 soft bounces (below threshold)
        for ($i = 0; $i < 4; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again later',
                'permanent' => 0,
                'reset' => 0,
                'date' => now()->subDays($i),
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_does_not_suspend_with_5_soft_bounces_older_than_14_days(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 5 soft bounces but all older than 14 days
        for ($i = 0; $i < 5; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again later',
                'permanent' => 0,
                'reset' => 0,
                'date' => now()->subDays(15 + $i), // All older than 14 days
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_does_not_suspend_with_49_total_bounces(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 49 temporary bounces - spread across time to avoid soft bounce threshold
        // (soft bounce threshold is 5 within 14 days, so put these older than 14 days)
        for ($i = 0; $i < 49; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again later',
                'permanent' => 0,
                'reset' => 0,
                'date' => now()->subDays(15 + $i), // Older than 14 days
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_ignores_reset_bounces_in_suspension_count(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create 5 permanent bounces but mark them as reset
        for ($i = 0; $i < 5; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '550 User unknown',
                'permanent' => 1,
                'reset' => 1, // These are reset
                'date' => now(),
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_only_considers_bounces_on_preferred_email(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);

        // The default createTestUser creates a preferred email
        $preferredEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create a non-preferred email
        $nonPreferredEmail = $this->createTestUserEmail($user, ['preferred' => 0]);

        // Create 5 permanent bounces on non-preferred
        for ($i = 0; $i < 5; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $nonPreferredEmail->id,
                'reason' => '550 User unknown',
                'permanent' => 1,
                'reset' => 0,
                'date' => now(),
            ]);
        }

        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_does_not_re_suspend_already_bouncing_user(): void
    {
        $user = $this->createTestUser(['bouncing' => 1]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create more bounces
        for ($i = 0; $i < 5; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '550 User unknown',
                'permanent' => 1,
                'reset' => 0,
                'date' => now(),
            ]);
        }

        // Should not error or change anything
        $this->service->checkAndSuspendUser($user->id);

        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    // ===================================================================
    // VERP Address Parsing Tests
    // ===================================================================

    public function test_extracts_user_id_from_verp_address(): void
    {
        $address = 'bounce-12345-1699000000@users.ilovefreegle.org';

        $userId = $this->service->extractUserIdFromVerpAddress($address);

        $this->assertEquals(12345, $userId);
    }

    public function test_returns_null_for_non_verp_address(): void
    {
        $address = 'test@example.com';

        $userId = $this->service->extractUserIdFromVerpAddress($address);

        $this->assertNull($userId);
    }

    public function test_returns_null_for_invalid_verp_format(): void
    {
        $address = 'bounce-@users.ilovefreegle.org';

        $userId = $this->service->extractUserIdFromVerpAddress($address);

        $this->assertNull($userId);
    }

    // ===================================================================
    // Full Bounce Processing Tests
    // ===================================================================

    public function test_processes_bounce_with_verp_and_records_correctly(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        $rawBounce = <<<DSN
From: MAILER-DAEMON@mx.example.com
To: bounce-{$user->id}-1699000000@users.ilovefreegle.org
Subject: Delivery Status Notification

Diagnostic-Code: smtp; 550 5.1.1 User unknown
Original-Recipient: rfc822;{$userEmail->email}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => "bounce-{$user->id}-1699000000@users.ilovefreegle.org",
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '5.1.1',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('bounces_emails', [
            'emailid' => $userEmail->id,
            'permanent' => 1,
        ]);
    }

    public function test_processes_bounce_without_verp_using_recipient_lookup(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        $rawBounce = <<<DSN
From: MAILER-DAEMON@mx.example.com
To: postmaster@groups.ilovefreegle.org
Subject: Delivery Status Notification

Diagnostic-Code: smtp; 550 User unknown
Original-Recipient: rfc822;{$userEmail->email}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => 'postmaster@groups.ilovefreegle.org',
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '5.0.0',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('bounces_emails', [
            'emailid' => $userEmail->id,
        ]);
    }

    public function test_returns_error_result_for_unparseable_bounce(): void
    {
        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => 'This is not a valid DSN',
            'envelopeTo' => 'bounce-99999-1699000000@users.ilovefreegle.org',
            'bounceRecipient' => null,
            'bounceStatus' => '5.0.0',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertFalse($result['success']);
        $this->assertEquals('unparseable', $result['error']);
    }

    public function test_returns_error_for_unknown_recipient(): void
    {
        $rawBounce = <<<DSN
Diagnostic-Code: smtp; 550 User unknown
Original-Recipient: rfc822;unknown@nonexistent.example.com
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => 'bounce-12345-1699000000@users.ilovefreegle.org',
            'bounceRecipient' => null,
            'bounceStatus' => '5.0.0',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertFalse($result['success']);
        $this->assertEquals('unknown_recipient', $result['error']);
    }

    // ===================================================================
    // Inline Suspension Tests (happens during processBounce)
    // ===================================================================

    public function test_process_bounce_triggers_inline_suspension_check(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Process a permanent bounce which should trigger immediate suspension
        $rawBounce = <<<DSN
Diagnostic-Code: smtp; 550 5.1.1 User unknown
Original-Recipient: rfc822;{$userEmail->email}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => "bounce-{$user->id}-1699000000@users.ilovefreegle.org",
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '5.1.1',
        ]);

        $this->service->processBounce($parsedEmail);

        $user->refresh();
        $this->assertEquals(1, $user->bouncing, 'User should be suspended after 1st permanent bounce');
    }

    public function test_ignores_temporary_bounce_that_should_be_ignored(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        $rawBounce = <<<DSN
Diagnostic-Code: smtp; 450 delivery temporarily suspended
Original-Recipient: rfc822;{$userEmail->email}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => "bounce-{$user->id}-1699000000@users.ilovefreegle.org",
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '4.5.0',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['ignored'] ?? false);

        // Should not have recorded any bounce
        $this->assertDatabaseMissing('bounces_emails', [
            'emailid' => $userEmail->id,
        ]);
    }

    // ===================================================================
    // Email Tracking Integration Tests
    // ===================================================================

    public function test_extracts_trace_id_from_dsn(): void
    {
        $dsnBody = <<<'DSN'
From: Mail Delivery System <MAILER-DAEMON@example.com>
Subject: Mail delivery failed

Diagnostic-Code: smtp; 550 5.1.1 User unknown
Original-Recipient: rfc822;test@example.com
X-Freegle-Trace-Id: abc123xyz789tracking
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result);
        $this->assertEquals('abc123xyz789tracking', $result['trace_id']);
    }

    public function test_returns_null_trace_id_when_not_present(): void
    {
        $dsnBody = <<<'DSN'
Diagnostic-Code: smtp; 550 User unknown
Original-Recipient: rfc822;test@example.com
DSN;

        $result = $this->service->parseDsn($dsnBody);

        $this->assertNotNull($result);
        $this->assertNull($result['trace_id']);
    }

    public function test_updates_email_tracking_via_trace_id(): void
    {
        // Create a user and email tracking record
        $user = $this->createTestUser();
        $trackingId = 'test-tracking-id-12345';

        $tracking = EmailTracking::create([
            'tracking_id' => $trackingId,
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => 'recipient@example.com',
            'sent_at' => now()->subMinutes(5),
        ]);

        // Update via trace ID
        $result = $this->service->updateEmailTracking($trackingId, null);

        $this->assertTrue($result);

        // Verify bounced_at was set
        $tracking->refresh();
        $this->assertNotNull($tracking->bounced_at);
    }

    public function test_updates_email_tracking_via_recipient_fallback(): void
    {
        // Create a user and email tracking record
        $user = $this->createTestUser();
        $recipientEmail = 'fallback-test@example.com';

        $tracking = EmailTracking::create([
            'tracking_id' => 'some-tracking-id',
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $recipientEmail,
            'sent_at' => now()->subMinutes(5),
        ]);

        // Update via recipient email (no trace ID)
        $result = $this->service->updateEmailTracking(null, $recipientEmail);

        $this->assertTrue($result);

        // Verify bounced_at was set
        $tracking->refresh();
        $this->assertNotNull($tracking->bounced_at);
    }

    public function test_fallback_uses_most_recent_unbounced_email(): void
    {
        // Create a user and multiple email tracking records
        $user = $this->createTestUser();
        $recipientEmail = 'multi-email-test@example.com';

        // Older email (already bounced)
        $oldTracking = EmailTracking::create([
            'tracking_id' => 'old-tracking-id',
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $recipientEmail,
            'sent_at' => now()->subHours(2),
            'bounced_at' => now()->subHour(),
        ]);

        // Middle email (not bounced)
        $middleTracking = EmailTracking::create([
            'tracking_id' => 'middle-tracking-id',
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $recipientEmail,
            'sent_at' => now()->subMinutes(30),
        ]);

        // Most recent email (not bounced)
        $recentTracking = EmailTracking::create([
            'tracking_id' => 'recent-tracking-id',
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $recipientEmail,
            'sent_at' => now()->subMinutes(5),
        ]);

        // Update via recipient email (should pick most recent unbounced)
        $result = $this->service->updateEmailTracking(null, $recipientEmail);

        $this->assertTrue($result);

        // Verify only most recent unbounced email was updated
        $recentTracking->refresh();
        $middleTracking->refresh();
        $oldTracking->refresh();

        $this->assertNotNull($recentTracking->bounced_at);
        $this->assertNull($middleTracking->bounced_at);
        // Old one was already bounced
        $this->assertNotNull($oldTracking->bounced_at);
    }

    public function test_returns_false_when_no_tracking_record_found(): void
    {
        // Try to update with non-existent trace ID and recipient
        $result = $this->service->updateEmailTracking('nonexistent-id', 'nonexistent@example.com');

        $this->assertFalse($result);
    }

    public function test_process_bounce_updates_email_tracking_with_trace_id(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();
        $trackingId = 'bounce-test-tracking-id';

        // Create email tracking record
        $tracking = EmailTracking::create([
            'tracking_id' => $trackingId,
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $userEmail->email,
            'sent_at' => now()->subMinutes(5),
        ]);

        // Create bounce DSN with trace ID
        $rawBounce = <<<DSN
Diagnostic-Code: smtp; 550 5.1.1 User unknown
Original-Recipient: rfc822;{$userEmail->email}
X-Freegle-Trace-Id: {$trackingId}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => "bounce-{$user->id}-1699000000@users.ilovefreegle.org",
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '5.1.1',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['tracking_updated']);

        // Verify email tracking was updated
        $tracking->refresh();
        $this->assertNotNull($tracking->bounced_at);
    }

    public function test_process_bounce_falls_back_to_recipient_when_no_trace_id(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $userEmail = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Create email tracking record without using trace ID in DSN
        $tracking = EmailTracking::create([
            'tracking_id' => 'fallback-tracking-id',
            'email_type' => 'TestEmail',
            'userid' => $user->id,
            'recipient_email' => $userEmail->email,
            'sent_at' => now()->subMinutes(5),
        ]);

        // Create bounce DSN WITHOUT trace ID
        $rawBounce = <<<DSN
Diagnostic-Code: smtp; 550 5.1.1 User unknown
Original-Recipient: rfc822;{$userEmail->email}
DSN;

        $parsedEmail = $this->createParsedEmail([
            'rawMessage' => $rawBounce,
            'envelopeTo' => "bounce-{$user->id}-1699000000@users.ilovefreegle.org",
            'bounceRecipient' => $userEmail->email,
            'bounceStatus' => '5.1.1',
        ]);

        $result = $this->service->processBounce($parsedEmail);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['tracking_updated']);

        // Verify email tracking was updated via fallback
        $tracking->refresh();
        $this->assertNotNull($tracking->bounced_at);
    }

    // ===================================================================
    // Bulk Suspension Tests (suspendBouncingUsers)
    // ===================================================================

    public function test_suspend_bouncing_users_suspends_user_with_permanent_bounce(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        DB::table('bounces_emails')->insert([
            'emailid' => $email->id,
            'reason' => '550 User unknown',
            'permanent' => 1,
            'reset' => 0,
            'date' => now(),
        ]);

        $count = $this->service->suspendBouncingUsers();

        $this->assertGreaterThanOrEqual(1, $count);
        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    public function test_suspend_bouncing_users_suspends_user_with_50_total_bounces(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        for ($i = 0; $i < 50; $i++) {
            DB::table('bounces_emails')->insert([
                'emailid' => $email->id,
                'reason' => '421 Try again',
                'permanent' => 0,
                'reset' => 0,
                'date' => now(),
            ]);
        }

        $count = $this->service->suspendBouncingUsers();

        $this->assertGreaterThanOrEqual(1, $count);
        $user->refresh();
        $this->assertEquals(1, $user->bouncing);
    }

    public function test_suspend_bouncing_users_does_not_suspend_already_bouncing_user(): void
    {
        $user = $this->createTestUser(['bouncing' => 1]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        DB::table('bounces_emails')->insert([
            'emailid' => $email->id,
            'reason' => '550 User unknown',
            'permanent' => 1,
            'reset' => 0,
            'date' => now(),
        ]);

        // Record initial update time to verify no DB write happened for this user
        $beforeBouncing = DB::table('users')->where('id', $user->id)->value('bouncing');
        $this->service->suspendBouncingUsers();
        $afterBouncing = DB::table('users')->where('id', $user->id)->value('bouncing');

        $this->assertEquals($beforeBouncing, $afterBouncing);
    }

    public function test_suspend_bouncing_users_does_not_suspend_user_with_reset_bounces(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);
        $email = UserEmail::where('userid', $user->id)->where('preferred', 1)->first();

        // Permanent bounce but reset=1 (bounce was cleared)
        DB::table('bounces_emails')->insert([
            'emailid' => $email->id,
            'reason' => '550 User unknown',
            'permanent' => 1,
            'reset' => 1,
            'date' => now(),
        ]);

        $this->service->suspendBouncingUsers();

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_suspend_bouncing_users_does_not_suspend_user_with_bounce_on_non_preferred_email(): void
    {
        $user = $this->createTestUser(['bouncing' => 0]);

        // Add a second (non-preferred) email
        $nonPreferredEmail = UserEmail::create([
            'userid' => $user->id,
            'email' => 'secondary_' . $user->id . '@example.com',
            'preferred' => 0,
            'validated' => now(),
            'added' => now(),
        ]);

        // Permanent bounce on the non-preferred email only
        DB::table('bounces_emails')->insert([
            'emailid' => $nonPreferredEmail->id,
            'reason' => '550 User unknown',
            'permanent' => 1,
            'reset' => 0,
            'date' => now(),
        ]);

        $this->service->suspendBouncingUsers();

        $user->refresh();
        $this->assertEquals(0, $user->bouncing);
    }

    public function test_suspend_bouncing_users_returns_count_of_suspended(): void
    {
        $user1 = $this->createTestUser(['bouncing' => 0]);
        $user2 = $this->createTestUser(['bouncing' => 0]);
        $email1 = UserEmail::where('userid', $user1->id)->where('preferred', 1)->first();
        $email2 = UserEmail::where('userid', $user2->id)->where('preferred', 1)->first();

        foreach ([$email1->id, $email2->id] as $emailId) {
            DB::table('bounces_emails')->insert([
                'emailid' => $emailId,
                'reason' => '550 User unknown',
                'permanent' => 1,
                'reset' => 0,
                'date' => now(),
            ]);
        }

        $count = $this->service->suspendBouncingUsers();

        $this->assertGreaterThanOrEqual(2, $count);
    }
}
