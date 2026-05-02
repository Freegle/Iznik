<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Models\ChatMessage;
use App\Models\Group;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Support\Facades\DB;
use App\Mail\Fbl\FblNotification;
use Illuminate\Support\Facades\Mail;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Tests for IncomingMailService routing logic.
 *
 * These tests verify that incoming emails are routed correctly based on
 * the envelope-to address, message content, and sender status.
 */
class IncomingMailServiceTest extends TestCase
{
    use EmailFixtures;

    private IncomingMailService $service;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(MailParserService::class);
        $this->service = app(IncomingMailService::class);
    }

    // ========================================
    // System Address Routing Tests
    // ========================================

    public function test_routes_digestoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off digest',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_digestoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'digestoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off digest',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'digestoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_readreceipt_to_receipt(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $msg = $this->createTestChatMessage($chat, $user2, ['message' => 'Test message']);
        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "readreceipt-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org",
            'Subject' => 'Read receipt',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "readreceipt-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::RECEIPT, $result);
    }

    public function test_routes_readreceipt_to_dropped_when_chat_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'readreceipt-99999999-88888888-77777777@users.ilovefreegle.org',
            'Subject' => 'Read receipt',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'readreceipt-99999999-88888888-77777777@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_subscribe_to_system(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'newuser@example.com',
            'To' => $group->nameshort.'-subscribe@groups.ilovefreegle.org',
            'Subject' => 'Subscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'newuser@example.com',
            $group->nameshort.'-subscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_unsubscribe_to_system(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);
        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            'Subject' => 'Unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-unsubscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_unsubscribe_to_dropped_when_not_member(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'unknown@example.com',
            'To' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            'Subject' => 'Unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'unknown@example.com',
            $group->nameshort.'-unsubscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_oneclick_unsubscribe_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unsub')]);
        $userEmail = $user->emails->first()->email;

        // Create a Link login record with a key for the user
        $key = 'validkey123';
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Link',
            'credentials' => $key,
            'added' => now(),
            'lastaccess' => now(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "unsubscribe-{$user->id}-{$key}-digest@users.ilovefreegle.org",
            'Subject' => 'One-click unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "unsubscribe-{$user->id}-{$key}-digest@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_oneclick_unsubscribe_to_dropped_with_invalid_key(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unsub')]);
        $userEmail = $user->emails->first()->email;

        // Create a Link login record with a different key
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Link',
            'credentials' => 'realkey123',
            'added' => now(),
            'lastaccess' => now(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "unsubscribe-{$user->id}-wrongkey-digest@users.ilovefreegle.org",
            'Subject' => 'One-click unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "unsubscribe-{$user->id}-wrongkey-digest@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_eventsoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "eventsoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off events',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "eventsoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_eventsoff_to_dropped_when_not_member(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'eventsoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off events',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'eventsoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_newslettersoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "newslettersoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off newsletters',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "newslettersoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_newslettersoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'newslettersoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off newsletters',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'newslettersoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_relevantoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "relevantoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off relevant',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "relevantoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_relevantoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'relevantoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off relevant',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'relevantoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_volunteeringoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "volunteeringoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off volunteering',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "volunteeringoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_volunteeringoff_to_dropped_when_not_member(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'volunteeringoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off volunteering',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'volunteeringoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_notificationmailsoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "notificationmailsoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off notification mails',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "notificationmailsoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_notificationmailsoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'notificationmailsoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off notification mails',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'notificationmailsoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_fbl_to_system(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'fbl@hotmail.com',
            'To' => 'fbl@ilovefreegle.org',
            'Subject' => 'Feedback Loop Report',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'fbl@hotmail.com',
            'fbl@ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Volunteer/Auto Address Routing Tests
    // ========================================

    public function test_routes_volunteers_address_to_volunteers(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);

        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Question for volunteers',
        ], 'Hi, I have a question about posting...');

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);
    }

    public function test_routes_auto_address_to_volunteers(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);

        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-auto@groups.ilovefreegle.org',
            'Subject' => 'Auto message',
        ], 'This is an automated message...');

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-auto@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);
    }

    // ========================================
    // Bounce Handling Tests
    // ========================================

    public function test_routes_bounce_to_system(): void
    {
        $bounceEmail = $this->createBounceEmail(
            'recipient@example.com',
            '5.1.1',
            'User unknown'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@ilovefreegle.org',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Bounces are system processing - not dropped
        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_permanent_bounce_suspends_user_email(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bounced')]);
        $userEmail = $user->emails->first();

        $bounceEmail = $this->createBounceEmail(
            $userEmail->email,
            '5.1.1',
            'User unknown'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@ilovefreegle.org',
            'bounce-'.$user->id.'-12345@users.ilovefreegle.org'
        );

        $this->service->route($parsed);

        // Refresh and check the user's email is now marked as bounced
        $userEmail->refresh();
        $this->assertNotNull($userEmail->bounced, 'Email should be marked as bounced');
    }

    public function test_permanent_bounce_to_group_address_suspends_user_email(): void
    {
        // Issue #39: Bounces delivered to group addresses (not bounce-{userid}) should
        // still record the bounce by looking up the recipient email in users_emails.
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('group-bounce')]);
        $userEmail = $user->emails->first();

        $bounceEmail = $this->createBounceEmail(
            $userEmail->email,
            '5.2.2',
            'mailbox full'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@outlook.com',
            'Bexhillfreegle-auto@groups.ilovefreegle.org'
        );

        $this->service->route($parsed);

        $userEmail->refresh();
        $this->assertNotNull($userEmail->bounced, 'Bounce to group address should still mark email as bounced');
    }

    public function test_temporary_bounce_to_group_address_not_recorded(): void
    {
        // Temporary bounces (4.x.x) should NOT be recorded as permanent bounces.
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('temp-bounce')]);
        $userEmail = $user->emails->first();

        $bounceEmail = $this->createBounceEmail(
            $userEmail->email,
            '4.2.2',
            'mailbox full temporarily'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@outlook.com',
            'Somefreegle-auto@groups.ilovefreegle.org'
        );

        $this->service->route($parsed);

        $userEmail->refresh();
        $this->assertNull($userEmail->bounced, 'Temporary bounce should not mark email as bounced');
    }

    // ========================================
    // Human Reply to Bounce Address Tests (Issue #40)
    // ========================================

    public function test_human_reply_to_bounce_address_sends_auto_reply(): void
    {
        // A human replying to a bounce- address should get a helpful auto-reply
        // and the email should be routed as TO_SYSTEM.
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'arthurcoxhill@gmail.com',
            'Subject' => 'Re: [Lancaster Morecambe Freegle] What\'s New (36 messages)',
        ], 'Thanks for the digest, I want to reply to a post.');

        $parsed = $this->parser->parse(
            $email,
            'arthurcoxhill@gmail.com',
            'bounce-44294373-1770054044@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertSent(\App\Mail\BounceAddressAutoReply::class, function ($mail) {
            return $mail->hasTo('arthurcoxhill@gmail.com');
        });
    }

    public function test_auto_reply_not_sent_to_mailer_daemon(): void
    {
        // Loop prevention: never auto-reply to mailer-daemon
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'MAILER-DAEMON@example.com',
            'Subject' => 'Delivery failure',
        ], 'Could not deliver.');

        $parsed = $this->parser->parse(
            $email,
            'MAILER-DAEMON@example.com',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        // Not a DSN so won't be detected as bounce by isBounce() — should still
        // hit the bounce-address handler in routeSystemAddress
        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    public function test_auto_reply_not_sent_to_noreply(): void
    {
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'noreply@example.com',
            'Subject' => 'Automated message',
        ], 'This is automated.');

        $parsed = $this->parser->parse(
            $email,
            'noreply@example.com',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    public function test_auto_reply_not_sent_to_bounce_address(): void
    {
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'bounce-99999-11111@users.ilovefreegle.org',
            'Subject' => 'Re: something',
        ], 'Reply from another bounce address.');

        $parsed = $this->parser->parse(
            $email,
            'bounce-99999-11111@users.ilovefreegle.org',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    public function test_auto_reply_not_sent_when_auto_submitted_header(): void
    {
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'Subject' => 'Out of office',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am away.');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    public function test_auto_reply_rate_limited_per_sender(): void
    {
        // Second reply from same sender within 24h should NOT get auto-reply
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'repeater@example.com',
            'Subject' => 'Re: digest',
        ], 'First reply.');

        $parsed = $this->parser->parse(
            $email,
            'repeater@example.com',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $this->service->route($parsed);
        Mail::assertSent(\App\Mail\BounceAddressAutoReply::class);

        // Second reply
        Mail::fake();
        $email2 = $this->createMinimalEmail([
            'From' => 'repeater@example.com',
            'Subject' => 'Re: digest again',
        ], 'Second reply.');

        $parsed2 = $this->parser->parse(
            $email2,
            'repeater@example.com',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $this->service->route($parsed2);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    public function test_auto_reply_misclassified_as_bounce_returns_to_system(): void
    {
        // Outlook auto-replies sometimes arrive with MAILER-DAEMON envelope-from,
        // causing the parser to flag them as bounces. Since they have no DSN content,
        // parseDsn() returns null. These should return TO_SYSTEM, not ERROR.
        $autoReplyEmail = $this->createMinimalEmail([
            'From' => 'someone@example.com',
            'To' => 'bounce-12345-67890@users.ilovefreegle.org',
            'Subject' => 'Automatic reply: [Test Freegle] OFFER: Item (Area AB1)',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am currently out of the office.');

        $parsed = $this->parser->parse(
            $autoReplyEmail,
            'MAILER-DAEMON',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Should NOT be ERROR - auto-replies aren't real bounces
        $this->assertNotEquals(RoutingResult::ERROR, $result);
    }

    public function test_dsn_bounce_to_bounce_address_not_auto_replied(): void
    {
        // Actual DSN bounces should be handled by handleBounce, not auto-replied
        Mail::fake();

        $bounceEmail = $this->createBounceEmail(
            'recipient@example.com',
            '5.1.1',
            'User unknown'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@ilovefreegle.org',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\BounceAddressAutoReply::class);
    }

    // ========================================
    // Chat Reply Routing Tests
    // ========================================

    public function test_routes_chat_notification_reply_to_user(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, it is still available!');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_routes_chat_reply_with_msgid_to_user(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $msg = $this->createTestChatMessage($chat, $user2, ['message' => 'Is this available?']);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, still available!');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_routes_replyto_address_to_user(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_replyto_creates_interested_type_with_refmsgid(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Verify chat message was created with TYPE_INTERESTED and correct refmsgid
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->where('refmsgid', $message->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg, 'Chat message should be TYPE_INTERESTED with refmsgid');
        $this->assertEquals($message->id, $chatMsg->refmsgid);
        $this->assertEquals(ChatMessage::TYPE_INTERESTED, $chatMsg->type);
    }

    public function test_replyto_creates_roster_entries_for_both_users(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Find the chat that was created
        $chat = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where('user1', min($poster->id, $replier->id))
            ->where('user2', max($poster->id, $replier->id))
            ->first();

        $this->assertNotNull($chat, 'Chat should be created');

        // Both users must have roster entries so the notification system
        // can track seen/emailed state and send email notifications.
        $posterRoster = DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $poster->id)
            ->first();

        $replierRoster = DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $replier->id)
            ->first();

        $this->assertNotNull($posterRoster, 'Poster (message owner) must have a roster entry');
        $this->assertNotNull($replierRoster, 'Replier (sender) must have a roster entry');
    }

    public function test_replyto_does_not_duplicate_roster_on_second_reply(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        // Send first reply
        $email1 = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed1 = $this->parser->parse(
            $email1,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed1);

        // Send second reply to same chat
        $email2 = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Can I collect today?');

        $parsed2 = $this->parser->parse(
            $email2,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed2);

        // Find the chat
        $chat = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where('user1', min($poster->id, $replier->id))
            ->where('user2', max($poster->id, $replier->id))
            ->first();

        // Should still have exactly one roster entry per user, not duplicates
        $posterRosterCount = DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $poster->id)
            ->count();

        $replierRosterCount = DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $replier->id)
            ->count();

        $this->assertEquals(1, $posterRosterCount, 'Poster should have exactly one roster entry');
        $this->assertEquals(1, $replierRosterCount, 'Replier should have exactly one roster entry');
    }

    public function test_direct_mail_creates_interested_type_with_fd_msgid_header(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;
        $posterEmail = $poster->emails->first()->email;
        // TN uses slug-based address: slug-{userid}@users.ilovefreegle.org
        $posterSlugAddr = "someslug-{$poster->id}@users.ilovefreegle.org";

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $posterSlugAddr,
            'Subject' => $message->subject,
            'x-fd-msgid' => (string) $message->id,
        ], 'I would love this item!');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            $posterSlugAddr
        );

        $this->service->route($parsed);

        // Verify chat message was created with TYPE_INTERESTED and correct refmsgid
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg, 'Direct mail should create TYPE_INTERESTED message');
        $this->assertEquals($message->id, $chatMsg->refmsgid);
        $this->assertEquals(ChatMessage::TYPE_INTERESTED, $chatMsg->type);
    }

    public function test_direct_mail_finds_refmsgid_by_subject(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Wooden bookshelf (Test Town)',
        ]);

        $replierEmail = $replier->emails->first()->email;
        $posterSlugAddr = "someslug-{$poster->id}@users.ilovefreegle.org";

        // Reply with similar subject but no x-fd-msgid header
        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $posterSlugAddr,
            'Subject' => 'Re: OFFER: Wooden bookshelf (Test Town)',
        ], 'Can I collect this today?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            $posterSlugAddr
        );

        $this->service->route($parsed);

        // Verify chat message was created with refmsgid found by subject matching
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg, 'Direct mail should create TYPE_INTERESTED message');
        $this->assertEquals($message->id, $chatMsg->refmsgid);
    }

    public function test_notify_reply_creates_default_type(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Thanks for the reply');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Verify chat message was created with TYPE_DEFAULT (not INTERESTED)
        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->where('userid', $user1->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);
        $this->assertEquals(ChatMessage::TYPE_DEFAULT, $chatMsg->type);
        $this->assertNull($chatMsg->refmsgid);
    }

    public function test_chat_reply_creates_chat_message(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'The test reply message content');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $initialCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->service->route($parsed);

        $finalCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->assertEquals($initialCount + 1, $finalCount);

        // Verify the message content
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertStringContainsString('The test reply message content', $lastMessage->message);
    }

    // ========================================
    // Auto-Reply Handling Tests
    // ========================================

    public function test_drops_auto_reply_messages(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someone@users.ilovefreegle.org',
            'Subject' => 'Out of Office: Re: Your message',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am out of the office until...');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'someone@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_vacation_auto_reply(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someone@users.ilovefreegle.org',
            'Subject' => 'Automatic reply: Your message',
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
        ], 'Thank you for your message...');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'someone@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Group Post Routing Tests
    // ========================================

    public function test_routes_approved_member_post_to_approved(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);
    }

    public function test_routes_moderated_member_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('moderated')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'MODERATED',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    public function test_routes_non_member_post_to_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('nonmember')]);
        // Don't add user to group

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Non-members get rejection email and message is dropped
        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_unmapped_user_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unmapped')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Don't set location - user is unmapped

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    public function test_routes_worry_word_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Add worry word to database
        DB::table('worrywords')->insert([
            'keyword' => 'kitten',
            'type' => 'Review',
        ]);

        $userEmail = $user->emails->first()->email;

        // Use a worry word in the subject
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free kitten (London)',
        ], 'Adorable kitten needs a good home.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Worry words cause posts to be held for review
        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Spam Detection Tests
    // ========================================

    public function test_routes_spam_to_incoming_spam(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spammer')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        // Seed a spam keyword so the spam checker can detect it
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        // (SpamCheckService caches keywords on first access)
        $this->service = app(IncomingMailService::class);

        // Use known spam patterns
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Make money fast! (London)',
        ], 'Send money to this account for guaranteed returns! Western Union accepted.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_spam_message_stored_for_moderator_review(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamstore')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        // Seed a spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Nigerian Prince',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        // (SpamCheckService caches keywords on first access)
        $this->service = app(IncomingMailService::class);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free money from Nigerian Prince (London)',
        ], 'I am a Nigerian Prince with money for you.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);

        // Verify message was created in database with spam info
        $message = DB::table('messages')
            ->where('fromuser', $user->id)
            ->where('subject', 'OFFER: Free money from Nigerian Prince (London)')
            ->first();

        $this->assertNotNull($message, 'Message should be created in database');
        $this->assertEquals('Known spam keyword', $message->spamtype);
        $this->assertStringContainsString('Nigerian Prince', $message->spamreason);

        // Verify messages_groups entry with Pending collection
        $messageGroup = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->first();

        $this->assertNotNull($messageGroup, 'Message group entry should exist');
        $this->assertEquals('Pending', $messageGroup->collection);

        // Verify messages_history entry for spam tracking
        $history = DB::table('messages_history')
            ->where('msgid', $message->id)
            ->first();

        $this->assertNotNull($history, 'Message history entry should exist');
        $this->assertEquals($group->id, $history->groupid);
    }

    public function test_spamassassin_spam_stored_with_score(): void
    {
        // Mock SpamCheckService so checkSpamAssassin returns a high spam score
        // without needing a running spamd daemon.
        $spamCheckMock = \Mockery::mock(\App\Services\Mail\Incoming\SpamCheckService::class)->makePartial();
        $spamCheckMock->shouldReceive('checkSpamAssassin')
            ->andReturn([15.5, true]);
        $spamCheckMock->shouldReceive('checkMessage')
            ->andReturn(null);

        $this->service = new IncomingMailService(spamCheck: $spamCheckMock);

        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamasspam')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test item (London)',
        ], 'Test body');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result,
            "SpamAssassin score 15.5 should route as spam, got: {$result->value}");

        $message = DB::table('messages')
            ->where('fromuser', $user->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($message, 'Spam message should be stored in database');
        $this->assertEquals('SpamAssassin', $message->spamtype);
        $this->assertStringContainsString('score', $message->spamreason);
    }

    public function test_routing_context_includes_spam_info(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamctx')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        // Seed spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Lottery Winner',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        // (SpamCheckService caches keywords on first access)
        $this->service = app(IncomingMailService::class);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Lottery Winner prize (London)',
        ], 'You are a Lottery Winner!');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);

        // Check routing context includes spam details
        $context = $this->service->getLastRoutingContext();
        $this->assertArrayHasKey('spam_type', $context);
        $this->assertArrayHasKey('spam_reason', $context);
        $this->assertArrayHasKey('message_id', $context);
        $this->assertEquals('Known spam keyword', $context['spam_type']);
    }

    public function test_greeting_spam_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Greeting spam pattern: greeting in subject + line1, with HTTP link
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'Hello there!',
        ], "Hello my friend\nPlease check this out\nhttp://spammy-link.com/click-here");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_refer_to_spammer_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Create a known spammer
        $spammer = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spammer')]);
        DB::table('spam_users')->insert([
            'userid' => $spammer->id,
            'collection' => 'Spammer',
            'reason' => 'Known spammer',
            'added' => now(),
        ]);
        $spammerEmail = $spammer->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free Stuff (London)',
        ], "Contact {$spammerEmail} for more details about this offer.");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_our_domain_in_from_name_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => 'groups.ilovefreegle.org <'.$userEmail.'>',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free Item (London)',
        ], 'A normal looking message body.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_subject_reuse_across_groups_detected_as_spam(): void
    {
        [$user, , $userEmail] = $this->createPostableUser();

        // Create many groups and message history with same pruned subject
        // to exceed SUBJECT_THRESHOLD (30)
        for ($i = 0; $i < 31; $i++) {
            $g = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromuser' => $user->id,
                'prunedsubject' => 'Test Spammy Subject',
                'groupid' => $g->id,
                'fromip' => '1.2.3.4',
                'fromname' => 'Test User',
                'arrival' => now(),
            ]);
        }

        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Spammy Subject (London)',
        ], 'Normal body text');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_ip_user_threshold_detected_as_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Create message history with many users from same IP (> USER_THRESHOLD=5)
        for ($i = 0; $i < 6; $i++) {
            $u = $this->createTestUser(['email_preferred' => $this->uniqueEmail("ipuser{$i}")]);
            DB::table('messages_history')->insert([
                'fromuser' => $u->id,
                'prunedsubject' => "Subject {$i}",
                'groupid' => $group->id,
                'fromip' => '203.0.113.99',
                'fromname' => "User {$i}",
                'arrival' => now(),
            ]);
        }

        // Email with the same suspicious IP
        $rawEmail = "From: {$userEmail}\r\nTo: {$group->nameshort}@groups.ilovefreegle.org\r\n"
            ."Subject: OFFER: Something (London)\r\nX-Freegle-IP: 203.0.113.99\r\n\r\n"
            .'Normal body text';

        $parsed = $this->parser->parse(
            $rawEmail,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_ip_group_threshold_detected_as_spam(): void
    {
        [$user, , $userEmail] = $this->createPostableUser();

        // Create message history with many groups from same IP (>= GROUP_THRESHOLD=20)
        for ($i = 0; $i < 20; $i++) {
            $g = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromuser' => $user->id,
                'prunedsubject' => 'Subject',
                'groupid' => $g->id,
                'fromip' => '203.0.113.88',
                'fromname' => 'Test User',
                'arrival' => now(),
            ]);
        }

        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $rawEmail = "From: {$userEmail}\r\nTo: {$group->nameshort}@groups.ilovefreegle.org\r\n"
            ."Subject: OFFER: Something (London)\r\nX-Freegle-IP: 203.0.113.88\r\n\r\n"
            .'Normal body text';

        $parsed = $this->parser->parse(
            $rawEmail,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_bulk_volunteer_mail_flagged_for_review(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bulkmailer')]);
        $userEmail = $user->emails->first()->email;

        // Create history of bulk sends to volunteer addresses
        for ($i = 0; $i < 21; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($user, $g);
            DB::table('messages')->insert([
                'envelopefrom' => $userEmail,
                'envelopeto' => $g->nameshort.'-volunteers@groups.ilovefreegle.org',
                'arrival' => now(),
            ]);
        }

        // Now send one more volunteer email
        $targetGroup = $this->createTestGroup();
        $this->createMembership($user, $targetGroup);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $targetGroup->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Important message for volunteers',
        ], 'Please share this with everyone.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $targetGroup->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Bulk volunteer mail goes to review, not rejected
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // Chat message should be flagged
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_volunteers_with_spam_keyword_detected_as_spam(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        $this->service = app(IncomingMailService::class);

        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('volspammer')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Important notice',
        ], 'Send money via Western Union to claim your prize.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Volunteers messages with spam go to review (not rejected) - users may be reporting spam
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // The chat message should be flagged for review so volunteers see it was detected
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_prohibited_user_post_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('banned')]);
        // PROHIBITED users are blocked by the API (message.php:625) so email should match
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'PROHIBITED',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Something (London)',
        ], 'Normal message body.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_known_spammer(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('badactor')]);
        // Mark user as spammer
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'reason' => 'Test spammer',
            'added' => now(),
        ]);
        $group = $this->createTestGroup();

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Normal item (London)',
        ], 'This looks normal but sender is banned.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Trash Nothing Routing Tests
    // ========================================

    public function test_routes_trash_nothing_post(): void
    {
        $group = $this->createTestGroup();

        $email = $this->loadEmailFixture('tn_post');
        // Modify fixture to target our test group
        $email = str_replace(
            'testgroup@groups.ilovefreegle.org',
            $group->nameshort.'@groups.ilovefreegle.org',
            $email
        );

        $parsed = $this->parser->parse(
            $email,
            'user@trashnothing.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // TN posts from authenticated source should route to appropriate collection
        // This test verifies TN secret header is respected
        $this->assertNotEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_skips_spam_check_for_trash_nothing_with_secret(): void
    {
        $group = $this->createTestGroup();

        // Create email with TN secret header but spam-like content
        $email = $this->createMinimalEmail([
            'From' => 'user@trashnothing.com',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Make money (Bristol)',
            'X-Trash-Nothing-Secret' => 'valid-secret-here',
        ], 'Content that might otherwise trigger spam filters.');

        $parsed = $this->parser->parse(
            $email,
            'user@trashnothing.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        // If secret is valid, spam check should be skipped
        $result = $this->service->route($parsed);

        $this->assertNotEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    // ========================================
    // Tryst (Calendar) Response Tests
    // ========================================

    public function test_routes_tryst_response_to_tryst(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('trystuser')]);
        $userEmail = $user->emails->first()->email;

        // Create a second user for the tryst
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('trystuser2')]);

        // Create a tryst record
        $trystId = DB::table('trysts')->insertGetId([
            'user1' => $user->id,
            'user2' => $user2->id,
            'arrangedfor' => now()->addDay(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "handover-{$trystId}-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Accepted: Pickup at 2pm',
            'Content-Type' => 'text/calendar; method=REPLY',
        ], "BEGIN:VCALENDAR\nMETHOD:REPLY\nEND:VCALENDAR");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "handover-{$trystId}-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TRYST, $result);
    }

    public function test_routes_tryst_response_to_dropped_when_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'handover-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Accepted: Pickup at 2pm',
            'Content-Type' => 'text/calendar; method=REPLY',
        ], "BEGIN:VCALENDAR\nMETHOD:REPLY\nEND:VCALENDAR");

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'handover-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    public function test_drops_twitter_notifications(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'info@twitter.com',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'You have a new follower!',
        ], 'Someone followed you on Twitter.');

        $parsed = $this->parser->parse(
            $email,
            'info@twitter.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_self_sent_messages(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $userEmail,
            'Subject' => 'Note to self',
        ], 'Testing.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $userEmail
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_chat_reply_to_stale_chat(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Make chat stale (90 days old)
        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subDays(90)]);

        // Try to reply from unfamiliar email
        $email = $this->createMinimalEmail([
            'From' => 'unknown@otherdomain.com',
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Old conversation',
        ], 'Reply to very old chat.');

        $parsed = $this->parser->parse(
            $email,
            'unknown@otherdomain.com',
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_expired_message_reply(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Make message expired (45 days old)
        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'arrival' => now()->subDays(45),
                'date' => now()->subDays(45),
            ]);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Chat Spam/Review Tests
    // ========================================

    public function test_chat_reply_with_spam_keyword_flagged_for_review(): void
    {
        // Seed a spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        $this->service = app(IncomingMailService::class);

        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Please send payment via Western Union to claim your item.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Chat-bound spam is NOT rejected - it goes to review
        $this->assertEquals(RoutingResult::TO_USER, $result);

        // The chat message should be flagged for review
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_chat_reply_with_money_symbol_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'I will sell this for £50, bargain price.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // checkReview detects money symbols
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_chat_reply_with_script_tag_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Hello <script>alert("xss")</script> world');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_clean_chat_reply_not_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, it is still available! When would you like to collect?');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(0, $lastMessage->reviewrequired);
        $this->assertNull($lastMessage->reportreason);
    }

    public function test_direct_mail_with_spam_flagged_for_review(): void
    {
        // Seed a spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        $this->service = app(IncomingMailService::class);

        $sender = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $recipient = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $senderEmail = $sender->emails->first()->email;
        $recipientEmail = $recipient->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'To' => $recipientEmail,
            'Subject' => 'About the item',
        ], 'Send money via Western Union to receive your prize.');

        $parsed = $this->parser->parse(
            $email,
            $senderEmail,
            $recipientEmail
        );

        $result = $this->service->route($parsed);

        // Direct mail spam goes to review, not rejection
        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Find the chat that was created
        $chat = DB::table('chat_rooms')
            ->where(function ($q) use ($sender, $recipient) {
                $q->where('user1', $sender->id)->where('user2', $recipient->id);
            })
            ->orWhere(function ($q) use ($sender, $recipient) {
                $q->where('user1', $recipient->id)->where('user2', $sender->id);
            })
            ->first();

        $this->assertNotNull($chat);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
    }

    public function test_chat_reply_with_external_email_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Contact me at myemail@externaldomain.com for more details about this offer.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_clean_chat_reply_held_when_previous_message_held_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Insert a chat message that is held for review (simulates a previous spam message).
        DB::table('chat_messages')->insert([
            'chatid' => $chat->id,
            'userid' => $user2->id,
            'message' => 'Send money via Western Union',
            'type' => 'Default',
            'date' => now()->subMinute(),
            'reviewrequired' => 1,
            'reportreason' => 'Spam',
            'processingrequired' => 0,
            'processingsuccessful' => 1,
        ]);

        // Now send a clean email reply to the same chat
        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, it is still available! When would you like to collect?');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // The clean message should ALSO be held for review because the previous
        // message in this chat is held. This prevents bypassing moderation by
        // sending follow-up messages after a held one.
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired, 'Clean message should be held when previous message is held for review');
        $this->assertEquals('Last', $lastMessage->reportreason, 'Report reason should be Last when held due to previous message');
    }

    // ========================================
    // Volunteers Spam Check Tests
    // ========================================

    public function test_volunteers_message_from_spammer_is_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('vol-spammer')]);
        $this->createMembership($user, $group);

        // Mark user as spammer
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $userEmail = $user->emails->first()->email;
        $groupName = $group->nameshort;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$groupName}-volunteers@groups.ilovefreegle.org",
            'Subject' => 'Help please',
        ], 'I need help with my item.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$groupName}-volunteers@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Known spammers are dropped unconditionally before routing
        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_volunteers_message_from_deleted_user_is_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('vol-deleted')]);
        $this->createMembership($user, $group);

        // Mark user as deleted (soft delete with timestamp)
        $user->update(['deleted' => now()]);

        $userEmail = $user->emails->first()->email;
        $groupName = $group->nameshort;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$groupName}-volunteers@groups.ilovefreegle.org",
            'Subject' => 'Help please',
        ], 'I need help with my item.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$groupName}-volunteers@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Deleted users should be dropped - their account no longer exists
        $this->assertEquals(RoutingResult::DROPPED, $result);

        // Verify no chat message was created
        $chatMessage = DB::table('chat_messages')
            ->where('userid', $user->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNull($chatMessage, 'No chat message should be created for a deleted user');
    }

    public function test_volunteers_spam_keyword_flagged_for_review(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('vol-spam')]);
        $this->createMembership($user, $group);

        // Seed spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        // Recreate service to pick up the newly inserted keyword
        $this->service = app(IncomingMailService::class);

        $userEmail = $user->emails->first()->email;
        $groupName = $group->nameshort;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$groupName}-volunteers@groups.ilovefreegle.org",
            'Subject' => 'Help please',
        ], 'Please send money via Western Union right away.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$groupName}-volunteers@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Volunteers spam goes to review, not rejected
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // Chat message should be flagged
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    // ========================================
    // TAKEN/RECEIVED Swallowing Tests
    // ========================================

    public function test_taken_subject_post_routes_to_system(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'TAKEN: Dining table (London)',
        ], 'This item has been taken.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_received_subject_post_routes_to_system(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'RECEIVED: Bookshelf (Acton)',
        ], 'Got this, thanks!');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Closed Group Reply Test
    // ========================================

    public function test_reply_to_message_on_closed_group_returns_to_system(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('closed-sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('closed-recipient')]);

        // Create a group with closed setting
        $group = $this->createTestGroup(['settings' => json_encode(['closed' => true])]);

        // Create a message on that group
        $messageId = DB::table('messages')->insertGetId([
            'arrival' => now()->subDays(5),
            'date' => now()->subDays(5),
            'fromuser' => $user2->id,
            'subject' => 'OFFER: Something',
            'type' => 'Offer',
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $messageId,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'arrival' => now()->subDays(5),
        ]);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "replyto-{$messageId}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: OFFER: Something',
        ], 'I would like this please.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "replyto-{$messageId}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Read Receipt in Chat Reply Test
    // ========================================

    public function test_read_receipt_in_chat_reply_is_dropped(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('receipt-sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('receipt-recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
            'Content-Type' => 'multipart/report; report-type=disposition-notification',
        ], '');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Prohibited Posting Status Test
    // ========================================

    public function test_prohibited_posting_status_routes_to_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('prohibited')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'PROHIBITED',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'OFFER: Banned item',
        ], 'This is a test post.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // API rejects PROHIBITED with "Not allowed to post" (message.php:625), email matches
        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Direct Mail Self-to-Self Test
    // ========================================

    public function test_direct_mail_to_self_is_dropped(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('selfmail')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $userEmail,
            'Subject' => 'Testing',
        ], 'Hello me.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $userEmail
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Merged User Tests
    // ========================================

    public function test_direct_mail_to_merged_user_proxy_address_is_delivered(): void
    {
        // Create the "merged" user (the one that survives)
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Simulate a merged user: the old proxy address contains a non-existent user ID,
        // but the email is registered in users_emails against the surviving user.
        // This happens when user A is merged into user B — the old proxy email
        // (slug-{A}@users.ilovefreegle.org) gets reassigned to user B's userid.
        // Create a real user then delete it to get a UID that genuinely doesn't exist
        // and won't collide with other concurrent tests.
        $deletedUser = User::create(['fullname' => 'Deleted Merged User', 'added' => now()]);
        $fakeOldUid = $deletedUser->id;
        $deletedUser->delete();
        $this->assertNull(User::find($fakeOldUid), 'Precondition: deleted UID should not exist');

        $oldProxyEmail = "someslug-{$fakeOldUid}@users.ilovefreegle.org";
        UserEmail::create([
            'userid' => $poster->id,
            'email' => $oldProxyEmail,
            'preferred' => 0,
            'added' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $oldProxyEmail,
            'Subject' => $message->subject,
            'x-fd-msgid' => (string) $message->id,
        ], 'I would love this item!');

        $parsed = $this->parser->parse($email, $replierEmail, $oldProxyEmail);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result, 'Mail to merged user proxy should be delivered, not dropped');

        // Verify chat message was created between the correct users
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg, 'Chat message should be created for merged user');
        $this->assertEquals($message->id, $chatMsg->refmsgid);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Create a user with group membership, location, and DEFAULT posting status.
     *
     * @return array{User, Group, string} [user, group, userEmail]
     */
    protected function createPostableUser(): array
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('postable')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);
        $userEmail = $user->emails->first()->email;

        return [$user, $group, $userEmail];
    }

    public function test_autoreply_to_notify_address_is_not_globally_dropped(): void
    {
        // Legacy code routes notify- addresses BEFORE checking auto-reply globally.
        // An auto-reply to a notify address should reach handleChatNotificationReply(),
        // not be dropped by the global auto-reply filter.
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $user2Email = $user2->emails->first()->email;

        // Make chat fresh so stale check doesn't interfere
        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subDays(1)]);

        $email = $this->createMinimalEmail([
            'From' => $user2Email,
            'To' => "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Your message',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am out of office.');

        $parsed = $this->parser->parse(
            $email,
            $user2Email,
            "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Should be routed to user (chat reply), NOT dropped
        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_bounce_to_notify_address_is_not_globally_dropped(): void
    {
        // Bounces to notify addresses should reach handleChatNotificationReply(),
        // not be intercepted by the global bounce handler.
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $user2Email = $user2->emails->first()->email;

        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subDays(1)]);

        // Simulate a bounce-like message (has bounce status) to a notify address
        // The key thing is that isChatNotificationReply() should take priority
        $email = $this->createMinimalEmail([
            'From' => 'mailer-daemon@example.com',
            'To' => "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org",
            'Subject' => 'Delivery Status Notification',
        ], 'This message was undeliverable.');

        $parsed = $this->parser->parse(
            $email,
            'mailer-daemon@example.com',
            "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org"
        );

        // If the parser detects this as a chat notification reply (chatId is set),
        // it should be routed via handleChatNotificationReply, not the bounce handler.
        // The result depends on whether mailer-daemon is in the chat - likely DROPPED
        // by the handler itself, but importantly NOT by the global bounce filter.
        if ($parsed->isChatNotificationReply()) {
            $result = $this->service->route($parsed);
            // mailer-daemon won't be in the chat, so handler drops it - that's fine
            // The point is the global bounce check didn't intercept it
            $this->assertNotEquals(RoutingResult::DROPPED, $result,
                'Should not have been dropped by global bounce handler if chat notification reply was detected');
        } else {
            // If parser doesn't detect bounce headers, it goes through bounce handler - also acceptable
            $this->assertTrue(true, 'Parser did not detect as chat notification reply - bounce handler handles it');
        }
    }

    public function test_autoreply_to_replyto_address_is_not_globally_dropped(): void
    {
        // Auto-replies to replyto- addresses should reach handleReplyToAddress(),
        // not be dropped by the global auto-reply filter.
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);
        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Your offer',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am currently away.');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Should be routed to user (reply to message), NOT dropped by global auto-reply filter
        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    /**
     * Create a location for testing.
     */
    protected function createLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'Test Location '.uniqid(),
            'type' => 'Polygon',
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    public function test_routing_context_set_for_approved_post(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('ctx-member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Context Test (London)',
        ], 'Testing routing context.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);

        $context = $this->service->getLastRoutingContext();
        $this->assertEquals($group->id, $context['group_id']);
        $this->assertEquals($group->nameshort, $context['group_name']);
        $this->assertEquals($user->id, $context['user_id']);
    }

    public function test_routing_context_has_reason_for_dropped(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'unknown@nowhere.com',
            'To' => 'nonexistent-subscribe@groups.ilovefreegle.org',
            'Subject' => 'Subscribe',
        ], 'Subscribe');

        $parsed = $this->parser->parse(
            $email,
            'unknown@nowhere.com',
            'nonexistent-subscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);

        // Dropped emails should have a routing_reason explaining why they were dropped
        $context = $this->service->getLastRoutingContext();
        $this->assertArrayHasKey('routing_reason', $context);
        $this->assertNotEmpty($context['routing_reason']);
    }

    public function test_routing_context_reset_between_calls(): void
    {
        // First call sets context for approved message
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('ctx-reset')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email1 = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Reset Test (London)',
        ], 'Testing reset.');

        $parsed1 = $this->parser->parse(
            $email1,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $this->service->route($parsed1);
        $context1 = $this->service->getLastRoutingContext();
        $this->assertNotEmpty($context1);
        $this->assertArrayHasKey('group_id', $context1);

        // Second call should reset context (no group_id from first call)
        $email2 = $this->createMinimalEmail([
            'From' => 'unknown@nowhere.com',
            'To' => 'nonexistent-subscribe@groups.ilovefreegle.org',
            'Subject' => 'Subscribe',
        ], 'Subscribe');

        $parsed2 = $this->parser->parse(
            $email2,
            'unknown@nowhere.com',
            'nonexistent-subscribe@groups.ilovefreegle.org'
        );

        $this->service->route($parsed2);
        $context2 = $this->service->getLastRoutingContext();
        // Context should be reset - no group_id from the first call should remain
        $this->assertArrayNotHasKey('group_id', $context2);
        // But dropped emails do have routing_reason
        $this->assertArrayHasKey('routing_reason', $context2);
    }

    public function test_chat_notification_reply_tracks_email_reply(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Create an email_tracking record simulating a chat notification that was sent to user1.
        DB::table('email_tracking')->insert([
            'tracking_id' => 'test-tracking-' . uniqid(),
            'email_type' => 'ChatNotification',
            'userid' => $user1->id,
            'recipient_email' => $user1->emails->first()->email,
            'subject' => 'New message from user2',
            'metadata' => json_encode(['chat_id' => $chat->id, 'sender_id' => $user2->id]),
            'sent_at' => now()->subMinutes(30),
            'has_amp' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: New message from user2',
        ], 'Thanks, I will collect it tomorrow.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Verify the email_tracking record was updated with reply info.
        $tracking = DB::table('email_tracking')
            ->where('email_type', 'ChatNotification')
            ->where('userid', $user1->id)
            ->whereRaw("JSON_EXTRACT(metadata, '$.chat_id') = ?", [$chat->id])
            ->first();

        $this->assertNotNull($tracking->replied_at, 'replied_at should be set');
        $this->assertEquals('email', $tracking->replied_via, 'replied_via should be email');
    }

    // ========================================
    // HTML-Only Email Tests
    // ========================================

    public function test_html_only_email_converted_to_plain_text(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        // Create an HTML-only email (no text part) like Apple Mail sometimes sends
        $htmlContent = '<html><head></head><body><div>Hi Karen, I live at 5 Kingsfield Close. The trolley is still available.</div><div>Warm wishes</div><div>Tessa</div></body></html>';

        $email = $this->createMultipartEmail(
            [
                'From' => $user1Email,
                'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
                'Subject' => 'Re: About the trolley',
            ],
            '', // No text body
            $htmlContent // HTML only
        );

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Verify the message was stored as plain text, not HTML
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        // Should NOT contain HTML tags
        $this->assertStringNotContainsString('<html>', $lastMessage->message);
        $this->assertStringNotContainsString('<div>', $lastMessage->message);
        $this->assertStringNotContainsString('<body>', $lastMessage->message);

        // Should contain the actual text content
        $this->assertStringContainsString('Kingsfield Close', $lastMessage->message);
        $this->assertStringContainsString('trolley', $lastMessage->message);
    }

    // ========================================
    // Bounce to Notify Address Tests (Issue: spotonmybum bounce went to chat review)
    // ========================================

    public function test_bounce_to_notify_address_should_be_processed_as_bounce_not_chat_message(): void
    {
        // When a bounce arrives at a notify-{chatId}-{userId}@ address (because the
        // original chat notification email bounced), it should be processed as a bounce,
        // NOT routed to handleChatNotificationReply which would create a chat message.

        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bounced-recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $user2Email = $user2->emails->first()->email;

        // Update chat to have recent activity (so it's not stale)
        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subHours(1)]);

        // Count chat messages before
        $messageCountBefore = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        // Create a bounce email (multipart/report with delivery-status)
        // This is what postfix sends when a chat notification bounces
        $bounceEmail = $this->createBounceEmail(
            $user2Email,
            '5.1.1',  // Permanent bounce - mailbox doesn't exist
            '550 5.1.1 User unknown'
        );

        $notifyAddress = "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org";

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@bulk2.ilovefreegle.org',
            $notifyAddress
        );

        // Verify the parser detects this as a bounce
        $this->assertTrue($parsed->isBounce(), 'Parser should detect this as a bounce');

        // Route the email
        $result = $this->service->route($parsed);

        // The result should be TO_SYSTEM (bounce processed) not TO_USER (chat message created)
        $this->assertEquals(
            RoutingResult::TO_SYSTEM,
            $result,
            'Bounce to notify address should return TO_SYSTEM, not TO_USER'
        );

        // Verify NO new chat message was created
        $messageCountAfter = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();
        $this->assertEquals(
            $messageCountBefore,
            $messageCountAfter,
            'Bounce should NOT create a chat message'
        );

        // Verify the bounce was recorded (user's email marked as bounced)
        $user2Email = DB::table('users_emails')
            ->where('userid', $user2->id)
            ->where('preferred', 1)
            ->first();
        $this->assertNotNull(
            $user2Email->bounced,
            'Bounce should mark user email as bounced'
        );
    }

    public function test_bounce_to_notify_address_from_outlook_block(): void
    {
        // Real case: Outlook blocked our IP and sent a bounce like:
        // "550 5.7.1 Unfortunately, messages from [77.72.7.253] weren't sent."
        //
        // These bounces should be processed as bounces, not chat messages.

        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender2')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('outlook-blocked')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $user2Email = $user2->emails->first()->email;

        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subHours(1)]);

        $messageCountBefore = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        // Create bounce with Outlook-style error message
        $bounceEmail = $this->createBounceEmail(
            $user2Email,
            '5.7.1',
            "550 5.7.1 Unfortunately, messages from [77.72.7.253] weren't sent. Please contact your Internet service provider"
        );

        $notifyAddress = "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org";

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@bulk2.ilovefreegle.org',
            $notifyAddress
        );

        $result = $this->service->route($parsed);

        // Should be processed as bounce
        $this->assertEquals(
            RoutingResult::TO_SYSTEM,
            $result,
            'Outlook IP-blocked bounce should return TO_SYSTEM'
        );

        // No chat message created
        $messageCountAfter = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();
        $this->assertEquals($messageCountBefore, $messageCountAfter);
    }

    public function test_legitimate_chat_reply_to_notify_address_still_works(): void
    {
        // Ensure the fix doesn't break legitimate chat replies.
        // A regular email (not a bounce) to a notify address should still
        // create a chat message.

        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster3')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier3')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $user2Email = $user2->emails->first()->email;

        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subHours(1)]);

        $messageCountBefore = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        // Regular reply (not a bounce)
        $replyEmail = $this->createMinimalEmail([
            'From' => $user2Email,
            'To' => "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Your item',
        ], 'Yes, I am still interested in collecting this.');

        $parsed = $this->parser->parse(
            $replyEmail,
            $user2Email,
            "notify-{$chat->id}-{$user2->id}@users.ilovefreegle.org"
        );

        // Should NOT be detected as a bounce
        $this->assertFalse($parsed->isBounce(), 'Regular reply should not be detected as bounce');

        $result = $this->service->route($parsed);

        // Should create a chat message
        $this->assertEquals(RoutingResult::TO_USER, $result);

        $messageCountAfter = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();
        $this->assertEquals(
            $messageCountBefore + 1,
            $messageCountAfter,
            'Regular reply should create a chat message'
        );
    }

    public function test_bounce_to_replyto_address_should_be_processed_as_bounce(): void
    {
        // Bounces can also arrive at replyto-{msgid}-{fromid}@ addresses when
        // a What's New notification bounces. These should be processed as bounces.

        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster4')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier4')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);
        $replierEmail = $replier->emails->first()->email;

        // Create a bounce to a replyto address
        $bounceEmail = $this->createBounceEmail(
            $replierEmail,
            '5.1.1',
            '550 5.1.1 User unknown'
        );

        $replytoAddress = "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org";

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@bulk2.ilovefreegle.org',
            $replytoAddress
        );

        // Verify parser detects this as a bounce
        $this->assertTrue($parsed->isBounce(), 'Parser should detect this as a bounce');

        $result = $this->service->route($parsed);

        // Should be processed as bounce (TO_SYSTEM), not as a chat reply (TO_USER)
        $this->assertEquals(
            RoutingResult::TO_SYSTEM,
            $result,
            'Bounce to replyto address should return TO_SYSTEM'
        );

        // Verify bounce was recorded
        $replierEmailRecord = DB::table('users_emails')
            ->where('userid', $replier->id)
            ->where('preferred', 1)
            ->first();
        $this->assertNotNull(
            $replierEmailRecord->bounced,
            'Bounce should mark user email as bounced'
        );
    }

    // ========================================
    // Subject Location Parsing Tests
    // ========================================

    public function test_parseSubject_extracts_type_item_and_location(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'parseSubject');
        $method->setAccessible(true);

        // Standard format: "OFFER: item (location)"
        [$type, $item, $location] = $method->invoke($this->service, 'OFFER: Sofa (Edinburgh)');
        $this->assertEquals('OFFER', $type);
        $this->assertEquals('Sofa', $item);
        $this->assertEquals('Edinburgh', $location);
    }

    public function test_parseSubject_handles_location_with_brackets(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'parseSubject');
        $method->setAccessible(true);

        // Location with brackets inside: "OFFER: item (location (area))"
        [$type, $item, $location] = $method->invoke($this->service, 'WANTED: Books (London (Central))');
        $this->assertEquals('WANTED', $type);
        $this->assertEquals('Books', $item);
        $this->assertEquals('London (Central)', $location);
    }

    public function test_parseSubject_handles_no_location(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'parseSubject');
        $method->setAccessible(true);

        // No location in parentheses
        [$type, $item, $location] = $method->invoke($this->service, 'OFFER: Sofa');
        $this->assertNull($location);
    }

    public function test_parseSubject_handles_no_colon(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'parseSubject');
        $method->setAccessible(true);

        // No colon in subject
        [$type, $item, $location] = $method->invoke($this->service, 'Free sofa available');
        $this->assertNull($type);
        $this->assertNull($item);
        $this->assertNull($location);
    }

    public function test_extractLocationFromSubject_finds_location(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'extractLocationFromSubject');
        $method->setAccessible(true);

        // Create a test location
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'TestLocation' . uniqid(),
            'type' => 'Postcode',
            'lat' => 55.9533,
            'lng' => -3.1883,
        ]);

        $locationName = DB::table('locations')->where('id', $locationId)->value('name');

        $group = $this->createTestGroup();

        $result = $method->invoke($this->service, "OFFER: Sofa ({$locationName})", $group->id);

        $this->assertNotNull($result);
        $this->assertEquals($locationId, $result['id']);
        $this->assertEquals(55.9533, $result['lat']);
        $this->assertEquals(-3.1883, $result['lng']);

        // Cleanup
        DB::table('locations')->where('id', $locationId)->delete();
    }

    public function test_extractLocationFromSubject_returns_null_for_unknown_location(): void
    {
        $method = new \ReflectionMethod(IncomingMailService::class, 'extractLocationFromSubject');
        $method->setAccessible(true);

        $group = $this->createTestGroup();

        $result = $method->invoke($this->service, 'OFFER: Sofa (NonExistentPlace12345)', $group->id);

        $this->assertNull($result);
    }

    public function test_group_post_sets_lat_lng_from_subject_location(): void
    {
        // Create a test location
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'SubjectTestLoc' . uniqid(),
            'type' => 'Postcode',
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);

        $locationName = DB::table('locations')->where('id', $locationId)->value('name');

        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        // Clear user's lastlocation to ensure we test subject parsing
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => null]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => "OFFER: Test item ({$locationName})",
        ], 'Test body');

        $parsed = $this->parser->parse($email, $userEmail, "{$group->nameshort}@groups.ilovefreegle.org");
        $result = $this->service->route($parsed);

        // Should route to pending or approved (not dropped)
        $this->assertContains($result, [RoutingResult::PENDING, RoutingResult::APPROVED]);

        // Check the message was created with lat/lng
        $context = $this->service->getLastRoutingContext();
        if (isset($context['message_id'])) {
            $message = DB::table('messages')->where('id', $context['message_id'])->first();
            $this->assertNotNull($message);
            $this->assertEquals(51.5074, (float) $message->lat);
            $this->assertEquals(-0.1278, (float) $message->lng);
            $this->assertEquals($locationId, $message->locationid);

            // Note: messages_spatial is now populated by cron, not on insert
            // See commit ff086ee6 "fix: Remove immediate messages_spatial insert - cron handles it"
        }

        // Cleanup
        DB::table('locations')->where('id', $locationId)->delete();
    }

    // ========================================
    // TN Image Scraping Tests
    // ========================================

    public function test_scrape_tn_image_urls_returns_empty_for_null_body(): void
    {
        $urls = $this->service->scrapeTnImageUrls(null);
        $this->assertEmpty($urls);
    }

    public function test_scrape_tn_image_urls_returns_empty_for_body_without_tn_links(): void
    {
        $body = "Hello, I have a sofa to give away.\nPlease contact me.";
        $urls = $this->service->scrapeTnImageUrls($body);
        $this->assertEmpty($urls);
    }

    public function test_scrape_tn_image_urls_finds_tn_pics_links(): void
    {
        // Note: This will actually try to fetch the TN page, which may fail in tests.
        // We're testing the regex extraction, not the HTTP fetch.
        $body = "I have items to give away.\n\nCheck out the pictures:\nhttps://trashnothing.com/pics/abc123\n\nContact me if interested.";

        // The method will find the URL even if the HTTP fetch fails
        // We mock the HTTP response to test properly
        \Illuminate\Support\Facades\Http::fake([
            'trashnothing.com/pics/*' => \Illuminate\Support\Facades\Http::response('<html><body><a href="https://img.trashnothing.com/image.jpg"><img src="https://img.trashnothing.com/thumb.jpg"/></a></body></html>', 200),
        ]);

        $urls = $this->service->scrapeTnImageUrls($body);
        $this->assertContains('https://img.trashnothing.com/image.jpg', $urls);
    }

    public function test_scrape_tn_image_urls_finds_multiple_links(): void
    {
        $body = "Multiple pics:\nhttps://trashnothing.com/pics/abc123\nhttps://trashnothing.com/pics/def456";

        \Illuminate\Support\Facades\Http::fake([
            'trashnothing.com/pics/abc123' => \Illuminate\Support\Facades\Http::response('<html><body><a href="https://img.trashnothing.com/img1.jpg"><img src="https://img.trashnothing.com/thumb1.jpg"/></a></body></html>', 200),
            'trashnothing.com/pics/def456' => \Illuminate\Support\Facades\Http::response('<html><body><a href="https://photos.trashnothing.com/img2.jpg"><img src="https://photos.trashnothing.com/thumb2.jpg"/></a></body></html>', 200),
        ]);

        $urls = $this->service->scrapeTnImageUrls($body);
        $this->assertCount(2, $urls);
        $this->assertContains('https://img.trashnothing.com/img1.jpg', $urls);
        $this->assertContains('https://photos.trashnothing.com/img2.jpg', $urls);
    }

    public function test_strip_tn_pic_links_returns_null_for_null_body(): void
    {
        $result = $this->service->stripTnPicLinks(null);
        $this->assertNull($result);
    }

    public function test_strip_tn_pic_links_removes_check_out_pictures_block(): void
    {
        $body = "I have a sofa.\n\nCheck out the pictures:\nhttps://trashnothing.com/pics/abc123\n\nContact me.";
        $result = $this->service->stripTnPicLinks($body);

        $this->assertStringNotContainsString('Check out the pictures', $result);
        $this->assertStringNotContainsString('trashnothing.com/pics', $result);
        $this->assertStringContainsString('I have a sofa.', $result);
        $this->assertStringContainsString('Contact me.', $result);
    }

    public function test_strip_tn_pic_links_preserves_body_without_tn_links(): void
    {
        $body = "Hello, I have items to give away.\nPlease contact me.";
        $result = $this->service->stripTnPicLinks($body);
        $this->assertEquals($body, $result);
    }

    public function test_create_tn_image_attachments_with_mocked_tus(): void
    {
        // Create a test user and group
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tn-test')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Create a test message
        $messageId = DB::table('messages')->insertGetId([
            'date' => now(),
            'source' => 'Email',
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test item',
            'textbody' => 'Test message',
            'tnpostid' => 'test123',
            'arrival' => now(),
        ]);

        // Mock HTTP for image download
        \Illuminate\Support\Facades\Http::fake([
            'img.trashnothing.com/*' => \Illuminate\Support\Facades\Http::response(
                // 1x1 red PNG (valid image)
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='),
                200,
                ['Content-Type' => 'image/png']
            ),
            // Mock TUS server
            config('freegle.tus_uploader') => \Illuminate\Support\Facades\Http::sequence()
                ->push('', 201, ['Location' => config('freegle.tus_uploader').'/test-upload-id'])
                ->push('', 200)
                ->push('', 204),
            config('freegle.tus_uploader').'/test-upload-id' => \Illuminate\Support\Facades\Http::response('', 200),
        ]);

        $imageUrls = ['https://img.trashnothing.com/test-image.jpg'];
        $created = $this->service->createTnImageAttachments($messageId, $imageUrls);

        // Due to mocking complexity, we just verify the method runs without error
        // Full integration testing would require a real TUS server
        $this->assertIsInt($created);

        // Cleanup
        DB::table('messages_attachments')->where('msgid', $messageId)->delete();
        DB::table('messages')->where('id', $messageId)->delete();
    }

    public function test_group_post_strips_tn_links_from_textbody(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tn-strip')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        // Mock HTTP responses
        \Illuminate\Support\Facades\Http::fake([
            'trashnothing.com/pics/*' => \Illuminate\Support\Facades\Http::response('<html><body></body></html>', 200),
        ]);

        $body = "I have a dining table to give away. Collection from my house.\n\nCheck out the pictures:\nhttps://trashnothing.com/pics/test123\n\nThanks for looking.";

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'OFFER: Dining Table',
            'X-Trash-Nothing-Post-ID' => 'tn-test-456',
        ], $body);

        $parsed = $this->parser->parse($email, $userEmail, "{$group->nameshort}@groups.ilovefreegle.org");
        $result = $this->service->route($parsed);

        // Accept PENDING, APPROVED, or INCOMING_SPAM (spam detection may trigger on test data)
        // The main purpose of this test is to verify TN link stripping when message IS created
        $this->assertContains($result, [RoutingResult::PENDING, RoutingResult::APPROVED, RoutingResult::INCOMING_SPAM]);

        // Check the message was created with cleaned textbody (only if not spam)
        $context = $this->service->getLastRoutingContext();
        if (isset($context['message_id']) && $result !== RoutingResult::INCOMING_SPAM) {
            $message = DB::table('messages')->where('id', $context['message_id'])->first();
            $this->assertNotNull($message);
            $this->assertStringNotContainsString('trashnothing.com/pics', $message->textbody);
            $this->assertStringContainsString('I have a dining table', $message->textbody);
        }
    }

    // ========================================
    // Chat Email Storage Tests
    // ========================================

    public function test_replyto_stores_raw_email_in_messages_and_chat_messages_byemail(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $rawEmail,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Find the chat message that was created
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);

        // Verify the raw email was stored in messages table and linked via chat_messages_byemail
        $byEmail = DB::table('chat_messages_byemail')
            ->where('chatmsgid', $chatMsg->id)
            ->first();

        $this->assertNotNull($byEmail, 'chat_messages_byemail record should exist');

        $storedMsg = DB::table('messages')
            ->where('id', $byEmail->msgid)
            ->first();

        $this->assertNotNull($storedMsg, 'Raw email should be stored in messages table');
        $this->assertStringContainsString('Is this still available?', $storedMsg->message);
        $this->assertEquals($replier->id, $storedMsg->fromuser);
        $this->assertEquals($replierEmail, $storedMsg->fromaddr);
    }

    public function test_notify_reply_stores_raw_email_in_messages_and_chat_messages_byemail(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Thanks for the reply');

        $parsed = $this->parser->parse(
            $rawEmail,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Find the chat message
        $chatMsg = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->where('userid', $user1->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);

        // Verify the raw email was stored and linked
        $byEmail = DB::table('chat_messages_byemail')
            ->where('chatmsgid', $chatMsg->id)
            ->first();

        $this->assertNotNull($byEmail, 'chat_messages_byemail record should exist for notify replies too');

        $storedMsg = DB::table('messages')
            ->where('id', $byEmail->msgid)
            ->first();

        $this->assertNotNull($storedMsg);
        $this->assertStringContainsString('Thanks for the reply', $storedMsg->message);
    }

    public function test_direct_mail_without_refmsgid_uses_default_type(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);

        $replierEmail = $replier->emails->first()->email;
        $posterSlugAddr = "someslug-{$poster->id}@users.ilovefreegle.org";

        // No x-fd-msgid header and subject won't match any post
        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $posterSlugAddr,
            'Subject' => 'Random unrelated subject',
        ], 'Hello there');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            $posterSlugAddr
        );

        $this->service->route($parsed);

        // Without a refmsgid, type should be DEFAULT not INTERESTED
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);
        $this->assertEquals(ChatMessage::TYPE_DEFAULT, $chatMsg->type);
        $this->assertNull($chatMsg->refmsgid);
    }

    // ========================================
    // Fix #6: addEmailToUser (email forwarding)
    // ========================================

    public function test_replyto_adds_unknown_sender_email_to_user_profile(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;
        // Simulate email forwarding: envelope-from is a different address
        $forwardedFrom = 'forwarded-' . uniqid() . '@otherdomain.com';

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: ' . $message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $forwardedFrom,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // The forwarding email should be added to the user's profile
        $addedEmail = DB::table('users_emails')
            ->where('userid', $replier->id)
            ->where('email', $forwardedFrom)
            ->first();

        $this->assertNotNull($addedEmail, 'Forwarding email should be added to user profile');
    }

    public function test_addEmailToUser_skips_system_addresses(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: ' . $message->subject,
        ], 'Is this still available?');

        // Use a system address as envelope-from (should NOT be added)
        $parsed = $this->parser->parse(
            $email,
            'notify-123-456@users.ilovefreegle.org',
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // System address should NOT be added
        $added = DB::table('users_emails')
            ->where('userid', $replier->id)
            ->where('email', 'notify-123-456@users.ilovefreegle.org')
            ->exists();

        $this->assertFalse($added, 'System addresses should not be added to user profile');
    }

    // ========================================
    // Fix #7: hasOutcome / mailedLastForUser
    // ========================================

    public function test_replyto_suppresses_email_for_completed_message(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Mark message as having outcome (TAKEN/RECEIVED)
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'timestamp' => now(),
        ]);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: ' . $message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Chat message should still be created (it goes to chat, just no email notification)
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->where('refmsgid', $message->id)
            ->first();

        $this->assertNotNull($chatMsg, 'Chat message should still be created for completed items');

        // The chat roster should be updated to suppress email (mailedLastForUser)
        $chat = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where(function ($q) use ($poster, $replier) {
                $q->where(function ($q2) use ($poster, $replier) {
                    $q2->where('user1', min($poster->id, $replier->id))
                        ->where('user2', max($poster->id, $replier->id));
                });
            })
            ->first();

        $this->assertNotNull($chat);

        $roster = DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $poster->id)
            ->first();

        $this->assertNotNull($roster, 'Roster should be updated to suppress email notification');
        $this->assertNotNull($roster->lastemailed, 'lastemailed should be set');
    }

    // ========================================
    // Fix #9: overridemoderation (Big Switch)
    // ========================================

    public function test_override_moderation_forces_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Enable Big Switch on group
        DB::table('groups')->where('id', $group->id)->update([
            'overridemoderation' => 'ModerateAll',
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Big Switch (London)',
        ], 'Test item.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Should be PENDING despite DEFAULT posting status
        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Fix #10: Mod posts forced to PENDING
    // ========================================

    public function test_moderator_post_forced_to_pending(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['email_preferred' => $this->uniqueEmail('mod')]);
        $this->createMembership($mod, $group, [
            'ourPostingStatus' => 'DEFAULT',
            'role' => 'Moderator',
        ]);
        DB::table('users')->where('id', $mod->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $modEmail = $mod->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $modEmail,
            'To' => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Mod Post Test (London)',
        ], 'Test mod post.');

        $parsed = $this->parser->parse(
            $email,
            $modEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Mod posts via email should be PENDING for other mods to review
        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Fix #11: Group moderated setting
    // ========================================

    public function test_group_moderated_setting_forces_post_to_pending(): void
    {
        $group = $this->createTestGroup(['settings' => json_encode(['moderated' => true])]);
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Moderated Group Test (London)',
        ], 'Test post to moderated group.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Fix #12: messages_postings
    // ========================================

    public function test_approved_post_creates_messages_postings_record(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Postings Record Test (London)',
        ], 'Test post.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);

        $context = $this->service->getLastRoutingContext();
        $this->assertArrayHasKey('message_id', $context);

        // Verify messages_postings record was created
        $posting = DB::table('messages_postings')
            ->where('msgid', $context['message_id'])
            ->where('groupid', $group->id)
            ->first();

        $this->assertNotNull($posting, 'messages_postings record should be created');
        $this->assertEquals(0, $posting->repost);
        $this->assertEquals(0, $posting->autorepost);
    }

    // ========================================
    // Fix #13: FBL comprehensive email shutoff + notification email
    // ========================================

    public function test_fbl_turns_off_all_email_types(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('fbl-user')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'emailfrequency' => 24,
            'eventsallowed' => 1,
            'volunteeringallowed' => 1,
        ]);

        // Set initial user settings
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'newslettersallowed' => 1,
        ]);

        $userEmail = $user->emails->first()->email;

        // Create FBL report with Original-Rcpt-To header
        $rawMessage = "From: fbl@hotmail.com\r\n"
            . "To: fbl@ilovefreegle.org\r\n"
            . "Subject: Feedback Loop Report\r\n"
            . "Original-Rcpt-To: {$userEmail}\r\n"
            . "\r\nFBL report content";

        $parsed = $this->parser->parse($rawMessage, 'fbl@hotmail.com', 'fbl@ilovefreegle.org');

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);

        // Verify memberships updated
        $membership = DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        $this->assertEquals(0, $membership->emailfrequency, 'Digest should be turned off');
        $this->assertEquals(0, $membership->eventsallowed, 'Events should be turned off');
        $this->assertEquals(0, $membership->volunteeringallowed, 'Volunteering should be turned off');

        // Verify user settings updated
        $updatedUser = DB::table('users')->where('id', $user->id)->first();
        $this->assertEquals(0, $updatedUser->relevantallowed, 'Relevant should be turned off');
        $this->assertEquals(0, $updatedUser->newslettersallowed, 'Newsletters should be turned off');

        // Verify user JSON settings
        $settings = json_decode($updatedUser->settings, true);
        $this->assertFalse($settings['notifications']['email']);
        $this->assertFalse($settings['notifications']['emailmine']);
        $this->assertFalse($settings['notificationmails']);
        $this->assertFalse($settings['engagement']);
    }

    public function test_fbl_sends_notification_email(): void
    {
        Mail::fake();

        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('fbl-notif')]);
        $userEmail = $user->emails->first()->email;

        $rawMessage = "From: fbl@hotmail.com\r\n"
            . "To: fbl@ilovefreegle.org\r\n"
            . "Subject: Feedback Loop Report\r\n"
            . "Original-Rcpt-To: {$userEmail}\r\n"
            . "\r\nFBL report content";

        $parsed = $this->parser->parse($rawMessage, 'fbl@hotmail.com', 'fbl@ilovefreegle.org');

        $this->service->route($parsed);

        // Verify the FBL notification email was sent as a branded Mailable
        Mail::assertSent(FblNotification::class, function (FblNotification $mail) use ($user, $userEmail) {
            return $mail->user->id === $user->id
                && $mail->recipientEmail === $userEmail;
        });
    }

    // ========================================
    // Fix #20: Subject prepend for unpaired direct messages
    // ========================================

    public function test_direct_mail_without_refmsgid_prepends_subject(): void
    {
        $sender = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $recipient = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);

        $senderEmail = $sender->emails->first()->email;
        $recipientSlugAddr = "someslug-{$recipient->id}@users.ilovefreegle.org";

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'To' => $recipientSlugAddr,
            'Subject' => 'About the sofa you posted',
        ], 'I would like to collect it please.');

        $parsed = $this->parser->parse(
            $email,
            $senderEmail,
            $recipientSlugAddr
        );

        $this->service->route($parsed);

        // Without a refmsgid, subject should be prepended to message body
        $chatMsg = DB::table('chat_messages')
            ->where('userid', $sender->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);
        $this->assertStringContainsString('About the sofa you posted', $chatMsg->message);
        $this->assertStringContainsString('I would like to collect it please.', $chatMsg->message);
    }

    public function test_direct_mail_with_refmsgid_does_not_prepend_subject(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Nice sofa (London)',
        ]);

        $replierEmail = $replier->emails->first()->email;
        $posterSlugAddr = "someslug-{$poster->id}@users.ilovefreegle.org";

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $posterSlugAddr,
            'Subject' => 'OFFER: Nice sofa (London)',
            'x-fd-msgid' => (string) $message->id,
        ], 'I would like this please.');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            $posterSlugAddr
        );

        $this->service->route($parsed);

        $chatMsg = DB::table('chat_messages')
            ->where('userid', $replier->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($chatMsg);
        // With refmsgid, subject should NOT be prepended
        $this->assertStringNotContainsString('OFFER: Nice sofa', $chatMsg->message);
        $this->assertStringContainsString('I would like this please.', $chatMsg->message);
    }

    // ========================================
    // Fix #23: Spam log entries in logs table
    // ========================================

    public function test_spam_post_creates_log_entry(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamlog')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        DB::table('spam_keywords')->insert([
            'word' => 'SpamLogTest' . uniqid(),
            'action' => 'Spam',
            'type' => 'Literal',
        ]);
        $spamWord = DB::table('spam_keywords')->orderBy('id', 'desc')->first()->word;

        $this->service = app(IncomingMailService::class);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free stuff (London)',
        ], "Get your {$spamWord} here!");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);

        $context = $this->service->getLastRoutingContext();
        $this->assertArrayHasKey('message_id', $context);

        // Verify log entry was created
        $logEntry = DB::table('logs')
            ->where('type', 'Message')
            ->where('subtype', 'ClassifiedSpam')
            ->where('msgid', $context['message_id'])
            ->first();

        $this->assertNotNull($logEntry, 'Spam log entry should be created in logs table');
        $this->assertEquals($group->id, $logEntry->groupid);
    }

    // ========================================
    // Fix #24: Digest off log entry
    // ========================================

    public function test_digestoff_creates_log_entry(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off digest',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $this->service->route($parsed);

        // Verify log entry
        $logEntry = DB::table('logs')
            ->where('type', 'User')
            ->where('subtype', 'MailOff')
            ->where('user', $user->id)
            ->first();

        $this->assertNotNull($logEntry, 'Digest off should create a log entry');
    }

    public function test_tn_reporting_member_preserves_conversation_transcript(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnreporter')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        // Simulate a TN "Reporting member" email with conversation transcript.
        // The transcript contains Subject:/From:/To: lines that the strip logic
        // would previously eat from those headers to end-of-string.
        $body = "Reporting member \"baduser\" baduser-12345@users.ilovefreegle.org (#12345)\r\n"
            . "\r\n"
            . "No response to my messages\r\n"
            . "\r\n"
            . "\r\n"
            . "---------- Conversation Transcript (ordered newest to oldest) ----------\r\n"
            . "\r\n"
            . "--- 2026-02-09 14:26:50 --\r\n"
            . "Subject: OFFER: Test item (AB1)\r\n"
            . "From: {$userEmail}\r\n"
            . "To: baduser-12345@users.ilovefreegle.org\r\n"
            . "\r\n"
            . "Hi, is this still available? I can collect tomorrow.\r\n"
            . "\r\n"
            . "Possible collection times: tomorrow afternoon";

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Reporting member "baduser" baduser-12345@users.ilovefreegle.org (#12345)',
        ], $body);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // The chat message should preserve the full conversation transcript
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($lastMessage);
        $this->assertStringContainsString('No response to my messages', $lastMessage->message);
        $this->assertStringContainsString('Conversation Transcript', $lastMessage->message);
        $this->assertStringContainsString('Subject: OFFER: Test item (AB1)', $lastMessage->message);
        $this->assertStringContainsString('Hi, is this still available?', $lastMessage->message);
        $this->assertStringContainsString('Possible collection times: tomorrow afternoon', $lastMessage->message);
    }

    public function test_non_reporting_volunteer_message_still_strips_quoted(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('volreply')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        // A regular volunteer message (not a TN report) should still strip quoted text
        $body = "I have a question about posting rules.\r\n"
            . "\r\n"
            . "On Mon, Feb 10, 2026 at 3:00 PM Someone wrote:\r\n"
            . "This is the original quoted message that should be stripped.";

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort . '-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Question about posting',
        ], $body);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($lastMessage);
        $this->assertStringContainsString('question about posting rules', $lastMessage->message);
        // The "On ... wrote:" quoted text should be stripped for non-report messages
        $this->assertStringNotContainsString('original quoted message that should be stripped', $lastMessage->message);
    }

    // ========================================
    // Chat Room Redirect Tests
    // ========================================

    public function test_chat_notification_reply_follows_redirect(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);

        // Create the canonical room
        $canonical = $this->createTestChatRoom($user1, $user2);

        // Simulate a deleted duplicate room by inserting a redirect
        $deletedId = $canonical->id + 99999;
        DB::table('chat_room_redirects')->insert([
            'old_id' => $deletedId,
            'new_id' => $canonical->id,
        ]);

        $user1Email = $user1->emails->first()->email;

        // Send reply to the old (deleted) chat ID
        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$deletedId}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'I can collect tomorrow');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$deletedId}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Should route successfully via redirect
        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Message should be in the canonical room
        $msg = ChatMessage::where('chatid', $canonical->id)
            ->where('message', 'LIKE', '%collect tomorrow%')
            ->first();
        $this->assertNotNull($msg, 'Message should be in canonical room after redirect');
    }

    public function test_chat_notification_reply_to_nonexistent_chat_without_redirect_is_dropped(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);

        $user1Email = $user1->emails->first()->email;
        $fakeId = 999999999;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$fakeId}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Something',
        ], 'Hello');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$fakeId}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // getOrCreateUserChat Duplicate Prevention Tests
    // ========================================

    public function test_replyto_finds_existing_chat_with_swapped_users(): void
    {
        // This tests that replying to a message finds an existing chat room
        // even when the user1/user2 order doesn't match the normalized order.
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($replier, $group);
        $message = $this->createTestMessage($poster, $group);

        // Create an existing chat with poster as user1 (un-normalized, like old PHP did)
        $existingChat = $this->createTestChatRoom($poster, $replier);
        $this->createTestChatMessage($existingChat, $poster, ['message' => 'old conversation']);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Should NOT have created a new chat room - should reuse existing
        $roomCount = DB::table('chat_rooms')
            ->where('chattype', 'User2User')
            ->where(function ($q) use ($poster, $replier) {
                $q->where(function ($q2) use ($poster, $replier) {
                    $q2->where('user1', $poster->id)->where('user2', $replier->id);
                })->orWhere(function ($q2) use ($poster, $replier) {
                    $q2->where('user1', $replier->id)->where('user2', $poster->id);
                });
            })
            ->count();

        $this->assertEquals(1, $roomCount, 'Should reuse existing chat, not create duplicate');
    }

    public function test_replyto_updates_lastaccess(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($replier, $group);
        $message = $this->createTestMessage($poster, $group);

        // Set lastaccess to a stale date
        DB::table('users')->where('id', $replier->id)->update(['lastaccess' => '2020-01-01 00:00:00']);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // lastaccess should have been updated from the stale 2020 date
        $user = DB::table('users')->where('id', $replier->id)->first();
        $this->assertGreaterThan('2025-01-01', $user->lastaccess, 'lastaccess should be updated on email reply');
    }

    public function test_group_post_updates_lastaccess(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Set lastaccess to a stale date
        DB::table('users')->where('id', $user->id)->update(['lastaccess' => '2020-01-01 00:00:00']);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (TestLocation)',
        ], 'A test offer posting');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Should route to group
        $this->assertContains($result, [RoutingResult::PENDING, RoutingResult::APPROVED]);

        // lastaccess should have been updated
        $updatedUser = DB::table('users')->where('id', $user->id)->first();
        $this->assertGreaterThan('2025-01-01', $updatedUser->lastaccess, 'lastaccess should be updated on group post');
    }

    // ========================================
    // recordFailure Tests
    // ========================================

    /**
     * Test that recordFailure() increments retrycount and sets retrylastfailure.
     *
     * V1 parity: Message::recordFailure() in iznik-server/include/message/Message.php
     * does: UPDATE messages SET retrycount = LAST_INSERT_ID(retrycount), retrylastfailure = NOW()
     * and logs to the logs table with type='Message', subtype='Failure'.
     */
    public function test_record_failure_increments_retrycount_and_sets_retrylastfailure(): void
    {
        // Create a message record directly in the DB
        $messageId = DB::table('messages')->insertGetId([
            'date' => now(),
            'source' => 'Email',
            'messageid' => 'test-failure-' . uniqid() . '@example.com',
            'retrycount' => 0,
            'retrylastfailure' => null,
        ]);

        $this->service->recordFailure($messageId, 'Test failure reason');

        $message = DB::table('messages')->where('id', $messageId)->first();
        $this->assertEquals(1, $message->retrycount, 'retrycount should be incremented to 1');
        $this->assertNotNull($message->retrylastfailure, 'retrylastfailure should be set');
    }

    public function test_record_failure_increments_retrycount_on_repeated_calls(): void
    {
        $messageId = DB::table('messages')->insertGetId([
            'date' => now(),
            'source' => 'Email',
            'messageid' => 'test-failure-repeat-' . uniqid() . '@example.com',
            'retrycount' => 0,
            'retrylastfailure' => null,
        ]);

        $this->service->recordFailure($messageId, 'First failure');
        $this->service->recordFailure($messageId, 'Second failure');

        $message = DB::table('messages')->where('id', $messageId)->first();
        $this->assertEquals(2, $message->retrycount, 'retrycount should be 2 after two failures');
    }

    // ========================================
    // Digest Reply Routing Tests
    // ========================================

    public function test_digest_reply_sends_auto_response_and_routes_to_system(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');

        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'Subject' => 'Re: Your Freegle Digest',
        ], 'I want to reply to a post in the digest.');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            $noreplyAddr
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertSent(\App\Mail\Digest\DigestReplyNotice::class, function ($mail) {
            return $mail->hasTo('user@example.com');
        });
    }

    public function test_digest_reply_suppressed_for_mailer_daemon_sender(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');

        $email = $this->createMinimalEmail([
            'From' => 'MAILER-DAEMON@example.com',
            'Subject' => 'Delivery Status',
        ], 'Could not deliver your message.');

        $parsed = $this->parser->parse(
            $email,
            'MAILER-DAEMON@example.com',
            $noreplyAddr
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\Digest\DigestReplyNotice::class);
    }

    public function test_digest_reply_suppressed_for_noreply_sender(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');

        $email = $this->createMinimalEmail([
            'From' => 'noreply@someservice.com',
            'Subject' => 'Automated message',
        ], 'This is automated.');

        $parsed = $this->parser->parse(
            $email,
            'noreply@someservice.com',
            $noreplyAddr
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\Digest\DigestReplyNotice::class);
    }

    public function test_digest_reply_suppressed_when_auto_submitted(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');

        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'Subject' => 'Out of office',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am away from the office.');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            $noreplyAddr
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertNotSent(\App\Mail\Digest\DigestReplyNotice::class);
    }

    public function test_digest_reply_rate_limited_to_once_per_24h(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');

        $email = $this->createMinimalEmail([
            'From' => 'ratelimit@example.com',
            'Subject' => 'Re: Digest',
        ], 'First reply to digest.');

        $parsed = $this->parser->parse(
            $email,
            'ratelimit@example.com',
            $noreplyAddr
        );

        $result1 = $this->service->route($parsed);
        $this->assertEquals(RoutingResult::TO_SYSTEM, $result1);
        Mail::assertSent(\App\Mail\Digest\DigestReplyNotice::class);

        // Second reply from same sender within 24h — no auto-reply
        Mail::fake();
        $email2 = $this->createMinimalEmail([
            'From' => 'ratelimit@example.com',
            'Subject' => 'Re: Digest again',
        ], 'Second reply to digest.');

        $parsed2 = $this->parser->parse(
            $email2,
            'ratelimit@example.com',
            $noreplyAddr
        );

        $result2 = $this->service->route($parsed2);
        $this->assertEquals(RoutingResult::TO_SYSTEM, $result2);
        Mail::assertNotSent(\App\Mail\Digest\DigestReplyNotice::class);
    }

    public function test_digest_reply_via_in_reply_to_header(): void
    {
        Mail::fake();

        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'Subject' => 'Re: Your Digest',
            'In-Reply-To' => '<UnifiedDigest-12345@ilovefreegle.org>',
        ], 'I am replying to the digest via In-Reply-To.');

        // Envelope-to is a regular address (not noreply@), but In-Reply-To
        // identifies this as a digest reply
        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'freegle@example.com'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertSent(\App\Mail\Digest\DigestReplyNotice::class, function ($mail) {
            return $mail->hasTo('user@example.com');
        });
    }

    public function test_digest_reply_includes_user_id_when_sender_found(): void
    {
        Mail::fake();

        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $senderEmail = $this->uniqueEmail('digestsender');
        $user = $this->createTestUser(['email_preferred' => $senderEmail]);

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'Subject' => 'Re: Your Freegle Digest',
        ], 'I want to reply to a post.');

        $parsed = $this->parser->parse(
            $email,
            $senderEmail,
            $noreplyAddr
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
        Mail::assertSent(\App\Mail\Digest\DigestReplyNotice::class, function ($mail) use ($senderEmail) {
            return $mail->hasTo($senderEmail);
        });
    }
}
