<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
use App\Services\EmailSpoolerService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendUnifiedDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['freegle.digest.immediate_allowlist' => '*']);
    }

    protected function seedImmediateCursor(Group $group): void
    {
        // msgid has a FK to messages.id (ON DELETE SET NULL), so the value
        // must be NULL or a real message id — never 0.
        GroupDigest::updateOrCreate(
            ['groupid' => $group->id, 'frequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE],
            ['msgdate' => null, 'msgid' => null]
        );
    }

    public function test_command_advances_cursor_after_processing(): void
    {
        // Two messages in the same group → both should be processed in
        // one iteration; cursor advances to the latest.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);
        $this->seedImmediateCursor($group);

        $a = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $b = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $a->id)->update(['arrival' => now()->subMinutes(10)]);
        DB::table('messages_groups')->where('msgid', $b->id)->update(['arrival' => now()->subMinutes(10)]);

        config(['freegle.mail.enabled_types' => UnifiedDigestService::EMAIL_TYPE]);

        // Limit iterations to 2 to keep the test fast (one active iter
        // + one idle that sleeps 1s = ~1s total wall clock).
        $this->artisan('mail:digest:unified', [
            '--mode' => UnifiedDigestService::MODE_IMMEDIATE,
            '--max-iterations' => 2,
        ])->assertExitCode(0);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals($b->id, $cursor->msgid);
    }

    public function test_command_default_max_iterations_is_one(): void
    {
        // Manual / test invocations should stay single-pass by default —
        // the cron schedule passes a higher value explicitly. Guards
        // against an accidental change that would make `php artisan
        // mail:digest:unified ...` loop unboundedly during interactive
        // debugging.
        $cmd = $this->app->make(\App\Console\Commands\Mail\SendUnifiedDigestCommand::class);
        $signature = (new \ReflectionClass($cmd))->getProperty('signature');
        $signature->setAccessible(true);
        $this->assertStringContainsString('--max-iterations=1 ', $signature->getValue($cmd));
    }

    public function test_spool_failure_for_recipient_still_advances_cursor(): void
    {
        // Regression guard for the duplicate-send hazard: if spool() throws
        // for a recipient (e.g. a transient MJML render error, which spool()
        // re-throws because it isn't a permanent address failure) the
        // exception must NOT escape the per-message foreach. If it did,
        // $lastProcessed would never be set, the group cursor would stall,
        // and the next cron tick would re-send the whole batch — the bug that
        // gave one user 27 copies of the same post in 13 min. The recipient
        // is skipped, but the message still counts as processed and the
        // cursor advances.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Stall (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()->subMinutes(10)]);

        config(['freegle.mail.enabled_types' => UnifiedDigestService::EMAIL_TYPE]);

        // Spooler throws for the (only) recipient on every attempt.
        $spooler = \Mockery::mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')
            ->andThrow(new \RuntimeException('simulated transient MJML render failure'));
        $this->app->instance(EmailSpoolerService::class, $spooler);

        $this->artisan('mail:digest:unified', [
            '--mode' => UnifiedDigestService::MODE_IMMEDIATE,
            '--max-iterations' => 2,
        ])->assertExitCode(0);

        // Cursor advanced past the message despite the spool failure.
        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals($msg->id, $cursor->msgid, 'Cursor must advance even when a recipient spool fails');
    }
}
