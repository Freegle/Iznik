<?php

namespace Tests\Unit\Support;

use App\Mail\Admin\ModNotifMail;
use App\Models\UserEmail;
use App\Support\SafeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as SymfonyInvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException as SymfonyTransportException;
use Tests\TestCase;

class SafeMailTest extends TestCase
{
    /**
     * Permanent failure (non-ASCII local-part etc) must be caught: bounce
     * recorded for the recipient, warning logged, false returned, NO re-throw.
     */
    public function test_permanent_failure_marks_recipient_bouncing_and_returns_false(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;
        $emailId = $user->emails->first()->id;

        // Ensure starting bounced=0 so we can detect the increment.
        DB::table('users_emails')->where('id', $emailId)->update(['bounced' => 0]);

        // Stub Mail::to(...)->send() to throw the same exception Symfony Mailer
        // throws for non-ASCII local-part addresses.
        Mail::shouldReceive('to')
            ->once()
            ->andReturnSelf();
        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new SymfonyInvalidArgumentException(
                'Invalid addresses: non-ASCII characters not supported in local-part of email.'
            ));

        Log::spy();

        $mail = $this->makeFakeMailable();
        $delivered = SafeMail::send($mail, $email, 'Recipient Name');

        $this->assertFalse($delivered, 'permanent failure must return false, not throw');

        // Bounce was recorded against this users_emails row.
        $bounced = DB::table('users_emails')->where('id', $emailId)->value('bounced');
        $this->assertGreaterThan(0, $bounced, 'bounced counter should have incremented');

        // Logged at warning level (so it does NOT escalate to Sentry as error).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx = []) => str_contains((string) $msg, 'permanent-failure recipient'));
        Log::shouldNotHaveReceived('error');
    }

    /**
     * Transient SMTP failures (mail-host hiccup mid-send: closed-unexpectedly,
     * connect-refused, timed-out) must be swallowed at WARNING level — they
     * are not the recipient's fault and one blip during a 50k-recipient run
     * shouldn't crash the whole job or trigger Sentry. The recipient is NOT
     * marked as bouncing because the address itself is fine.
     */
    public function test_transient_failure_logs_warning_skips_recipient_no_bounce(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;
        $emailId = $user->emails->first()->id;
        DB::table('users_emails')->where('id', $emailId)->update(['bounced' => null]);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new SymfonyTransportException(
            'Connection to "mail-host:25" has been closed unexpectedly.'
        ));

        Log::spy();

        $delivered = SafeMail::send($this->makeFakeMailable(), $email);

        $this->assertFalse($delivered, 'transient failure must return false, not throw');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx = []) => str_contains((string) $msg, 'transient SMTP failure'));
        Log::shouldNotHaveReceived('error');

        $bounced = DB::table('users_emails')->where('id', $emailId)->value('bounced');
        $this->assertNull($bounced, 'transient failure must NOT mark email as bouncing');
    }

    /**
     * The "timed out" variant of the same TransportException class —
     * production hit this on 2026-05-15 07:31/08:01 with the actual message
     * `Connection to "mail-host:25" timed out.` and the old `[^"]+` regex
     * didn't match the quoted host, so SafeMail re-threw.
     */
    public function test_transient_failure_timeout_variant_is_caught(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;
        $emailId = $user->emails->first()->id;
        DB::table('users_emails')->where('id', $emailId)->update(['bounced' => null]);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new SymfonyTransportException(
            'Connection to "mail-host:25" timed out.'
        ));

        $delivered = SafeMail::send($this->makeFakeMailable(), $email);

        $this->assertFalse($delivered, 'timed-out variant must be classified transient, not re-thrown');

        $bounced = DB::table('users_emails')->where('id', $emailId)->value('bounced');
        $this->assertNull($bounced, 'transient timeout must NOT mark email as bouncing');
    }

    /**
     * Anything not classified as permanent OR transient must still propagate —
     * unknown failure modes should be loud, not silently swallowed.
     */
    public function test_unknown_failure_propagates(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException(
            'Something nobody has seen before'
        ));

        $this->expectException(\RuntimeException::class);
        SafeMail::send($this->makeFakeMailable(), $email);
    }

    /**
     * Successful send returns true and does not record a bounce.
     */
    public function test_successful_send_returns_true(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnNull();

        $delivered = SafeMail::send($this->makeFakeMailable(), $email);

        $this->assertTrue($delivered);
    }

    private function makeFakeMailable(): ModNotifMail
    {
        // Cheapest concrete MjmlMailable in the codebase. We never actually
        // render — Mail::send is mocked.
        return new ModNotifMail(
            recipientName: 'Test',
            recipientEmail: 'irrelevant@example.com',
            recipientUserId: null,
            subject: 'TEST',
            htmlSummary: '<p>x</p>',
            textSummary: 'x',
        );
    }
}
