<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Tests for the mail:incoming Artisan command.
 *
 * This command is the entry point for Postfix to deliver incoming emails.
 * It reads the raw email from stdin and routes it using IncomingMailService.
 */
class IncomingMailCommandTest extends TestCase
{
    use EmailFixtures;

    // ========================================
    // Basic Command Execution Tests
    // ========================================

    public function test_command_exists(): void
    {
        $this->artisan('list')
            ->expectsOutputToContain('mail:incoming');
    }

    public function test_command_requires_sender_argument(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');
        $this->artisan('mail:incoming');
    }

    public function test_command_requires_recipient_argument(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');
        $this->artisan('mail:incoming', ['sender' => 'test@test.com']);
    }

    public function test_command_processes_basic_email(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $this->createMembership($user, $group);

        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free item available.');

        // Simulate piping email via stdin
        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => $group->nameshort.'@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,  // Test option to pass content
        ])->assertSuccessful();
    }

    // ========================================
    // Exit Code Tests
    // ========================================

    public function test_returns_zero_on_successful_routing(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'digestoff-12345-67890@users.ilovefreegle.org',
            'Subject' => 'Turn off digest',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'digestoff-12345-67890@users.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(0);
    }

    public function test_returns_zero_for_dropped_messages(): void
    {
        // Auto-reply messages should be dropped but return success
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someone@users.ilovefreegle.org',
            'Subject' => 'Out of Office',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am out of office.');

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'someone@users.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(0);
    }

    public function test_returns_success_on_malformed_content(): void
    {
        // Even with completely invalid content, command should return success
        // to prevent Postfix from retrying endlessly. Invalid messages are dropped.
        $this->artisan('mail:incoming', [
            'sender' => '',
            'recipient' => '',
            '--stdin-content' => '',
        ])->assertSuccessful();
    }

    // ========================================
    // System Address Routing Tests
    // ========================================

    public function test_routes_digestoff_command(): void
    {
        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'digestoff-12345-67890@users.ilovefreegle.org',
            'Subject' => 'Turn off digest',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'digestoff-12345-67890@users.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();
    }

    public function test_routes_subscribe_command(): void
    {
        $group = $this->createTestGroup();

        $rawEmail = $this->createMinimalEmail([
            'From' => 'newuser@example.com',
            'To' => $group->nameshort.'-subscribe@groups.ilovefreegle.org',
            'Subject' => 'Subscribe',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'newuser@example.com',
            'recipient' => $group->nameshort.'-subscribe@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();
    }

    public function test_routes_unsubscribe_command(): void
    {
        $group = $this->createTestGroup();

        $rawEmail = $this->createMinimalEmail([
            'From' => 'member@example.com',
            'To' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            'Subject' => 'Unsubscribe',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'member@example.com',
            'recipient' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();
    }

    // ========================================
    // Bounce Handling Tests
    // ========================================

    public function test_routes_bounce_message(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bounced')]);
        $userEmail = $user->emails->first();

        $rawEmail = $this->createBounceEmail(
            $userEmail->email,
            '5.1.1',
            'User unknown'
        );

        $this->artisan('mail:incoming', [
            'sender' => 'MAILER-DAEMON@ilovefreegle.org',
            'recipient' => 'bounce-'.$user->id.'-12345@users.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();

        // Verify the bounce was recorded
        $userEmail->refresh();
        $this->assertNotNull($userEmail->bounced);
    }

    // ========================================
    // Chat Reply Tests
    // ========================================

    public function test_routes_chat_notification_reply(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Item inquiry',
        ], 'Yes, still available!');

        $initialCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->artisan('mail:incoming', [
            'sender' => $user1Email,
            'recipient' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();

        $finalCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->assertEquals($initialCount + 1, $finalCount);
    }

    // ========================================
    // Group Post Tests
    // ========================================

    public function test_routes_approved_member_post(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free item for collection.');

        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => $group->nameshort.'@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();
    }

    public function test_routes_moderated_member_post(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('moderated')]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'MODERATED']);

        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free item for collection.');

        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => $group->nameshort.'@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();
    }

    // ========================================
    // Output and Logging Tests
    // ========================================

    public function test_outputs_routing_result(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off digest',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            '--stdin-content' => $rawEmail,
        ])->expectsOutput('ToSystem')
            ->assertSuccessful();
    }

    public function test_verbose_mode_shows_details(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off digest',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            '--stdin-content' => $rawEmail,
            '-v' => true,
        ])->expectsOutputToContain('Turn off digest')
            ->assertSuccessful();
    }

    // ========================================
    // Error Handling Tests
    // ========================================

    public function test_handles_empty_stdin_gracefully(): void
    {
        // Empty email content should not crash
        $this->artisan('mail:incoming', [
            'sender' => 'test@test.com',
            'recipient' => 'test@test.com',
            '--stdin-content' => '',
        ])->assertSuccessful();  // Returns 0 even for invalid content (dropped)
    }

    public function test_handles_malformed_email_gracefully(): void
    {
        // Completely malformed content
        $this->artisan('mail:incoming', [
            'sender' => 'test@test.com',
            'recipient' => 'test@test.com',
            '--stdin-content' => 'This is not a valid email at all',
        ])->assertSuccessful();  // Returns 0 even for invalid content (dropped)
    }

    // ========================================
    // Transient Error Handling Tests
    // ========================================

    public function test_returns_tempfail_on_connection_refused(): void
    {
        // Mock IncomingMailService to throw a connection error
        $this->mock(\App\Services\Mail\Incoming\IncomingMailService::class)
            ->shouldReceive('route')
            ->andThrow(new \PDOException('Connection refused', 2002));

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'test@groups.ilovefreegle.org',
            'Subject' => 'Test',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'test@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(75);  // EX_TEMPFAIL
    }

    public function test_returns_tempfail_on_too_many_connections(): void
    {
        $this->mock(\App\Services\Mail\Incoming\IncomingMailService::class)
            ->shouldReceive('route')
            ->andThrow(new \PDOException('Too many connections', 1040));

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'test@groups.ilovefreegle.org',
            'Subject' => 'Test',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'test@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(75);  // EX_TEMPFAIL
    }

    public function test_returns_tempfail_on_deadlock(): void
    {
        $this->mock(\App\Services\Mail\Incoming\IncomingMailService::class)
            ->shouldReceive('route')
            ->andThrow(new \PDOException('Deadlock found when trying to get lock', 1213));

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'test@groups.ilovefreegle.org',
            'Subject' => 'Test',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'test@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(75);  // EX_TEMPFAIL
    }

    public function test_returns_tempfail_on_connection_timed_out(): void
    {
        $this->mock(\App\Services\Mail\Incoming\IncomingMailService::class)
            ->shouldReceive('route')
            ->andThrow(new \Exception('Connection timed out'));

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'test@groups.ilovefreegle.org',
            'Subject' => 'Test',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'test@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(75);  // EX_TEMPFAIL
    }

    public function test_returns_success_on_non_transient_error(): void
    {
        // Non-transient errors should return success to prevent endless retries
        $this->mock(\App\Services\Mail\Incoming\IncomingMailService::class)
            ->shouldReceive('route')
            ->andThrow(new \Exception('Some random error'));

        $rawEmail = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'test@groups.ilovefreegle.org',
            'Subject' => 'Test',
        ]);

        $this->artisan('mail:incoming', [
            'sender' => 'user@example.com',
            'recipient' => 'test@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertExitCode(0);  // EX_OK - drop message to prevent retry loop
    }

    // ========================================
    // Log Generation Tests
    // ========================================

    public function test_received_log_written_when_email_posted(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('logtest')]);
        $this->createMembership($user, $group);

        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;
        $msgId = '<test-log-'.uniqid().'@example.com>';

        $rawEmail = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test log item (London)',
            'Message-Id' => $msgId,
        ], 'Test body for log.');

        $this->artisan('mail:incoming', [
            'sender' => $userEmail,
            'recipient' => $group->nameshort.'@groups.ilovefreegle.org',
            '--stdin-content' => $rawEmail,
        ])->assertSuccessful();

        $message = DB::table('messages')->where('messageid', $msgId)->first();
        $this->assertNotNull($message, 'Message should have been created');

        $log = DB::table('logs')
            ->where('type', 'Message')
            ->where('subtype', 'Received')
            ->where('msgid', $message->id)
            ->first();

        $this->assertNotNull($log, 'Message/Received log entry should be written');
        $this->assertEquals($group->id, $log->groupid);
        $this->assertEquals($user->id, $log->user);
        $this->assertEquals($msgId, $log->text);
    }

    // ========================================
    // Helper Methods
    // ========================================

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
}
