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
     * Transient failures (connect refused, timeouts) must NOT be silently
     * swallowed — they should propagate so the cron run surfaces them.
     */
    public function test_transient_failure_propagates_and_does_not_record_bounce(): void
    {
        $user = $this->createTestUser();
        $email = $user->emails->first()->email;
        $emailId = $user->emails->first()->id;
        DB::table('users_emails')->where('id', $emailId)->update(['bounced' => null]);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new SymfonyTransportException(
            'Connection to "mail-host:25" timed out.'
        ));

        $this->expectException(SymfonyTransportException::class);

        try {
            SafeMail::send($this->makeFakeMailable(), $email);
        } finally {
            // Even though it threw, bounced must NOT have been set.
            $bounced = DB::table('users_emails')->where('id', $emailId)->value('bounced');
            $this->assertNull($bounced, 'transient failure must NOT mark email as bouncing');
        }
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
