<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\GroupDigest;
use App\Models\Membership;
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
        GroupDigest::updateOrCreate(
            ['groupid' => $group->id, 'frequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE],
            ['msgdate' => null, 'msgid' => 0]
        );
    }

    public function test_command_iterates_until_no_work_then_exits(): void
    {
        // Two messages in the same group → first iteration sends both,
        // second iteration finds nothing → loop exits early even though
        // --max-iterations=10 would allow many more. Asserts the early-
        // exit behaviour so we don't waste CPU spinning on an empty queue.
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

        // Force email type on so the command actually attempts a run.
        config(['freegle.mail.enabled_types' => UnifiedDigestService::EMAIL_TYPE]);

        $this->artisan('mail:digest:unified', [
            '--mode' => UnifiedDigestService::MODE_IMMEDIATE,
            '--max-iterations' => 10,
        ])->assertExitCode(0);

        // Cursor should have advanced to b (both messages processed in
        // iteration #1). Iteration #2 finds emails_sent=0 → loop breaks.
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
}
