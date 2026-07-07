<?php

namespace Tests\Unit\Services\Mail;

use App\Models\UserEmail;
use App\Services\Mail\Incoming\BounceService;
use App\Services\Mail\SmtpFailureClassifier;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmtpFailureClassifierTest extends TestCase
{
    private SmtpFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new SmtpFailureClassifier;
    }

    // -------------------------------------------------------------------------
    // isPermanent — PERMANENT_PATTERNS coverage
    // -------------------------------------------------------------------------

    #[DataProvider('permanentErrorProvider')]
    public function test_is_permanent_matches_known_permanent_errors(string $errorMessage): void
    {
        $this->assertTrue($this->classifier->isPermanent($errorMessage), "Expected permanent: '{$errorMessage}'");
    }

    public static function permanentErrorProvider(): array
    {
        return [
            // RFC 5321 5xx permanent failure codes
            '550 mailbox unavailable'             => ['550 Mailbox unavailable'],
            '550 with text'                       => ['SMTP error: 550 No such user'],
            '551 user not local'                  => ['551 User not local; please try forwarding'],
            '552 storage exceeded'                => ['552 Exceeded storage allocation'],
            '553 mailbox name'                    => ['553 Mailbox name not allowed'],
            '554 transaction failed'              => ['554 Transaction failed'],
            '501 5.1.3 syntax'                    => ['501 5.1.3 Bad recipient address syntax'],
            '501 5.1.3 with spaces'               => ['501  5.1.3 extended description'],
            '5.0.1 bad destination'               => ['Permanent error: 5.0.1'],
            '5.0.3 address syntax'                => ['Error code 5.0.3'],
            '5.1.1 bad mailbox'                   => ['SMTP 5.1.1 mailbox does not exist'],
            '5.1.3 bad address syntax'            => ['Delivery failed: 5.1.3'],
            '5.2.1 mailbox disabled'              => ['5.2.1 Mailbox disabled, not accepting messages'],
            'non-ASCII characters lowercase'      => ['Address contains non-ASCII characters'],
            'non-ASCII characters uppercase'      => ['NON-ASCII CHARACTERS not allowed'],
            'Invalid address lowercase'           => ['Invalid address: foo@'],
            'Invalid address mixed case'          => ['INVALID ADDRESS detected'],
            'Bad recipient address syntax lower'  => ['Bad recipient address syntax'],
            'Bad recipient address syntax upper'  => ['BAD RECIPIENT ADDRESS SYNTAX in header'],
            'RFC 2822 compliance'                 => ['Address does not comply with addr-spec of RFC 2822'],
            'RFC 2822 uppercase'                  => ['Email DOES NOT COMPLY WITH ADDR-SPEC OF RFC 2822'],
        ];
    }

    #[DataProvider('nonPermanentErrorProvider')]
    public function test_is_permanent_returns_false_for_non_permanent_errors(string $errorMessage): void
    {
        $this->assertFalse($this->classifier->isPermanent($errorMessage), "Expected NOT permanent: '{$errorMessage}'");
    }

    public static function nonPermanentErrorProvider(): array
    {
        return [
            'empty string'              => [''],
            'generic message'           => ['Something went wrong'],
            'connection refused'        => ['Connection refused to mail-host:25'],
            'timed out'                 => ['Connection to "mail-host:25" timed out'],
            'service unavailable 421'   => ['421 Service not available'],
            '5500 is not 550'           => ['5500 is not a match'],
            '5510 is not 551'           => ['5510 extra digit'],
            // Boundary: 5.2.2 is NOT in permanent patterns (it's not 5.2.1)
            '5.2.2 not in patterns'     => ['5.2.2 Mailbox full'],
            // 5.1.2 not matching /5\.[01]\.[13]/ because it ends in 2
            '5.1.2 not matched'         => ['5.1.2 Bad destination system'],
            // Transient patterns should not be caught by isPermanent
            'connection reset'          => ['Connection reset by peer'],
            'closed unexpectedly'       => ['Connection has been closed unexpectedly'],
            'unable to connect'         => ['stream_socket_client(): Unable to connect'],
        ];
    }

    public function test_is_permanent_word_boundary_550(): void
    {
        // "5500" should not match /\b550\b/
        $this->assertFalse($this->classifier->isPermanent('Error 5500'));
        $this->assertTrue($this->classifier->isPermanent('Error 550'));
        $this->assertTrue($this->classifier->isPermanent('550'));
        $this->assertTrue($this->classifier->isPermanent('Response: 550 '));
    }

    public function test_is_permanent_word_boundary_554(): void
    {
        $this->assertFalse($this->classifier->isPermanent('5540'));
        $this->assertTrue($this->classifier->isPermanent('554'));
        $this->assertTrue($this->classifier->isPermanent('Error 554 Transaction failed'));
    }

    public function test_is_permanent_case_insensitive_patterns(): void
    {
        $this->assertTrue($this->classifier->isPermanent('NON-ASCII CHARACTERS in address'));
        $this->assertTrue($this->classifier->isPermanent('Non-Ascii Characters found'));
        $this->assertTrue($this->classifier->isPermanent('INVALID ADDRESS'));
        $this->assertTrue($this->classifier->isPermanent('invalid address: malformed'));
        $this->assertTrue($this->classifier->isPermanent('BAD RECIPIENT ADDRESS SYNTAX'));
        $this->assertTrue($this->classifier->isPermanent('bad recipient address syntax'));
    }

    public function test_is_permanent_rfc_2822_pattern_is_case_insensitive(): void
    {
        $this->assertTrue($this->classifier->isPermanent('does not comply with addr-spec of RFC 2822'));
        $this->assertTrue($this->classifier->isPermanent('Does Not Comply With Addr-Spec Of RFC 2822'));
        $this->assertTrue($this->classifier->isPermanent('DOES NOT COMPLY WITH ADDR-SPEC OF RFC 2822'));
    }

    public function test_is_permanent_501_requires_5_1_3(): void
    {
        // 501 alone does NOT match /\b501\s+5\.1\.3\b/ — only the combined form matches
        $this->assertFalse($this->classifier->isPermanent('501 Bad parameters'));
        $this->assertTrue($this->classifier->isPermanent('501 5.1.3 Bad address'));
        $this->assertTrue($this->classifier->isPermanent('501  5.1.3'));  // multiple spaces
    }

    public function test_is_permanent_5_dot_pattern_only_matches_01_and_13(): void
    {
        // Pattern /\b5\.[01]\.[13]\b/ matches: 5.0.1, 5.0.3, 5.1.1, 5.1.3
        $this->assertTrue($this->classifier->isPermanent('code 5.0.1 returned'));
        $this->assertTrue($this->classifier->isPermanent('code 5.0.3 returned'));
        $this->assertTrue($this->classifier->isPermanent('code 5.1.1 returned'));
        $this->assertTrue($this->classifier->isPermanent('code 5.1.3 returned'));
        // NOT: 5.0.2, 5.1.2, 5.1.4
        $this->assertFalse($this->classifier->isPermanent('code 5.0.2 returned'));
        $this->assertFalse($this->classifier->isPermanent('code 5.1.2 returned'));
        $this->assertFalse($this->classifier->isPermanent('code 5.1.4 returned'));
    }

    // -------------------------------------------------------------------------
    // isTransient — TRANSIENT_PATTERNS coverage
    // -------------------------------------------------------------------------

    #[DataProvider('transientErrorProvider')]
    public function test_is_transient_matches_known_transient_errors(string $errorMessage): void
    {
        $this->assertTrue($this->classifier->isTransient($errorMessage), "Expected transient: '{$errorMessage}'");
    }

    public static function transientErrorProvider(): array
    {
        return [
            'Connection refused'                       => ['Connection refused'],
            'connection refused lowercase'             => ['connection refused to server'],
            'Connection to host timed out'             => ['Connection to "mail-host:25" timed out'],
            'Connection with host timed out'           => ['Connection with "smtp.example.com:587" timed out'],
            'Connection could not be established'      => ['Connection could not be established with host'],
            'connection could not be established low'  => ['connection could not be established'],
            'stream_socket_client'                     => ['stream_socket_client(): Unable to connect to mail:25'],
            'stream_socket_client uppercase'           => ['STREAM_SOCKET_CLIENT(): UNABLE TO CONNECT'],
            'Connection reset by peer'                 => ['Connection reset by peer'],
            'connection reset by peer lower'           => ['connection reset by peer detected'],
            'closed unexpectedly'                      => ['Connection has been closed unexpectedly'],
            'closed unexpectedly mixed case'           => ['Transport HAS BEEN CLOSED UNEXPECTEDLY'],
            '421 service unavailable'                  => ['421 Service not available, try again later'],
            '421 alone'                                => ['421'],
            '421 in context'                           => ['SMTP error: 421 Too many connections'],
        ];
    }

    #[DataProvider('nonTransientErrorProvider')]
    public function test_is_transient_returns_false_for_non_transient_errors(string $errorMessage): void
    {
        $this->assertFalse($this->classifier->isTransient($errorMessage), "Expected NOT transient: '{$errorMessage}'");
    }

    public static function nonTransientErrorProvider(): array
    {
        return [
            'empty string'              => [''],
            'generic error'             => ['An unknown error occurred'],
            '550 permanent failure'     => ['550 Mailbox unavailable'],
            '554 transaction failed'    => ['554 Transaction failed'],
            'non-ASCII characters'      => ['non-ASCII characters'],
            'Invalid address'           => ['Invalid address'],
            '5.2.1 mailbox disabled'    => ['5.2.1 Mailbox disabled'],
            '4210 not 421'              => ['Error code 4210'],
            // "timed out" without the quoted-host pattern does not match
            'timed out without quotes'  => ['Connection timed out'],
            'Connection with no quote'  => ['Connection to mail-host:25 timed out'],
        ];
    }

    public function test_is_transient_connection_to_pattern_requires_quoted_host(): void
    {
        // The regex is: /Connection (?:to|with) "[^"]+" timed out/i
        // Must have a quoted hostname.
        $this->assertTrue($this->classifier->isTransient('Connection to "smtp.example.com:25" timed out'));
        $this->assertTrue($this->classifier->isTransient('Connection with "mail.example.com" timed out'));
        // Without quotes: no match
        $this->assertFalse($this->classifier->isTransient('Connection to smtp.example.com:25 timed out'));
    }

    public function test_is_transient_421_word_boundary(): void
    {
        // /\b421\b/ should not match 4210 or 5421
        $this->assertFalse($this->classifier->isTransient('Error 4210'));
        $this->assertFalse($this->classifier->isTransient('Error 5421'));
        $this->assertTrue($this->classifier->isTransient('421 Try again'));
        $this->assertTrue($this->classifier->isTransient('Error 421 retry'));
    }

    public function test_is_transient_stream_socket_escaped_parens(): void
    {
        // The pattern uses \(\) to match literal parens
        $this->assertTrue($this->classifier->isTransient('stream_socket_client(): Unable to connect to host'));
        $this->assertFalse($this->classifier->isTransient('stream_socket_client Unable to connect'));
    }

    // -------------------------------------------------------------------------
    // Mutual exclusivity: permanent and transient are disjoint
    // -------------------------------------------------------------------------

    public function test_permanent_and_transient_are_mutually_exclusive_for_typical_errors(): void
    {
        $permanentExamples = [
            '550 Mailbox unavailable',
            '554 Transaction failed',
            'non-ASCII characters',
            '5.2.1 Mailbox disabled',
            'does not comply with addr-spec of RFC 2822',
        ];

        $transientExamples = [
            'Connection refused',
            'Connection to "host:25" timed out',
            'Connection reset by peer',
            '421 Try again',
            'stream_socket_client(): Unable to connect',
        ];

        foreach ($permanentExamples as $msg) {
            $this->assertFalse($this->classifier->isTransient($msg), "Permanent error should not be transient: '{$msg}'");
        }

        foreach ($transientExamples as $msg) {
            $this->assertFalse($this->classifier->isPermanent($msg), "Transient error should not be permanent: '{$msg}'");
        }
    }

    // -------------------------------------------------------------------------
    // recordPermanentBounce — user-not-found path (no DB writes needed)
    // -------------------------------------------------------------------------

    public function test_record_permanent_bounce_logs_debug_when_email_not_found(): void
    {
        Log::spy();

        $nonExistentEmail = 'no-such-user-' . uniqid() . '@example.com';
        $this->classifier->recordPermanentBounce($nonExistentEmail, '550 Mailbox unavailable');

        Log::shouldHaveReceived('debug')
            ->once()
            ->with('SMTP bounce recipient not in users_emails', \Mockery::on(function ($context) use ($nonExistentEmail) {
                return $context['email'] === $nonExistentEmail;
            }));
    }

    public function test_record_permanent_bounce_with_trace_id_when_email_not_found(): void
    {
        Log::spy();

        $nonExistentEmail = 'no-such-user-' . uniqid() . '@example.com';
        $traceId = 'trace-' . uniqid();

        // Should not throw; should log debug about missing email
        $this->classifier->recordPermanentBounce($nonExistentEmail, '550 Mailbox unavailable', $traceId);

        Log::shouldHaveReceived('debug')->once();
    }

    public function test_record_permanent_bounce_records_bounce_when_email_exists(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails()->first()->email;

        Log::spy();

        // Should call recordBounce and checkAndSuspendUser via BounceService
        // (the service itself is tested separately; here we just verify no exception
        // and that an info log is produced at least once, noting SmtpFailureClassifier
        // itself emits one 'Recorded SMTP bounce' info entry)
        $this->classifier->recordPermanentBounce($email, '550 Mailbox unavailable');

        Log::shouldHaveReceived('info')
            ->atLeast()->once()
            ->with('Recorded SMTP bounce', \Mockery::on(function ($context) use ($email) {
                return $context['email'] === $email;
            }));
    }

    public function test_record_permanent_bounce_with_trace_id_when_email_exists(): void
    {
        // Covers the `if ($traceId !== null) { $bounceService->updateEmailTracking(...) }` branch (line 119).
        $user = $this->createTestUser();
        $email = $user->emails()->first()->email;
        $traceId = 'trace-' . uniqid();

        Log::spy();

        // No EmailTracking record exists for this traceId, so updateEmailTracking returns false —
        // but line 119 is still executed.
        $this->classifier->recordPermanentBounce($email, '550 Mailbox unavailable', $traceId);

        Log::shouldHaveReceived('info')
            ->atLeast()->once()
            ->with('Recorded SMTP bounce', \Mockery::on(function ($context) use ($email) {
                return $context['email'] === $email;
            }));
    }

    public function test_record_permanent_bounce_catches_exceptions_and_logs_error(): void
    {
        // Bind a BounceService that throws so we can verify the catch block
        $this->app->bind(BounceService::class, function () {
            $mock = \Mockery::mock(BounceService::class);
            $mock->shouldReceive('recordBounce')->andThrow(new \RuntimeException('DB error'));
            return $mock;
        });

        $user = $this->createTestUser();
        $email = $user->emails()->first()->email;

        Log::spy();

        // Must not throw
        $this->classifier->recordPermanentBounce($email, '550 Mailbox unavailable');

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to record SMTP bounce', \Mockery::on(function ($context) use ($email) {
                return $context['recipient'] === $email
                    && str_contains($context['error'], 'DB error');
            }));
    }
}
