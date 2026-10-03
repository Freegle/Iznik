<?php

namespace Tests\Feature\Mail;

use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * MODE_IMMEDIATE and MODE_REACH both route straight to sendReachDigests() (a user's
 * location, not group membership, decides reach), so the per-group cursor scenarios
 * this file used to cover no longer exercise anything real - that mechanism is dead,
 * and reach-mail behaviour is covered directly in tests/Unit/Services/UnifiedDigestServiceTest.php.
 * What is left here is genuinely command-level: flags and pacing, not digest content.
 */
class SendUnifiedDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['freegle.digest.immediate_allowlist' => '*']);
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

    public function test_idle_pause_never_disappears_however_slow_the_pass(): void
    {
        // The loop paces itself by sleeping whatever is left of a fixed period after
        // each idle pass. Taken literally that means a pass which overruns the period
        // leaves nothing to sleep, so the loop goes straight round again with no gap.
        // The passes that overrun are the slow ones, and the usual reason for a slow
        // one is a database already struggling, so the moment the pause is needed most
        // is the moment it would vanish. There is a floor to stop that happening.
        $cls = \App\Console\Commands\Mail\SendUnifiedDigestCommand::class;

        // A quick pass waits out the rest of the period, holding the poll rate steady
        // however cheap the queries get.
        $this->assertEqualsWithDelta(1.9, $cls::idlePauseSeconds(0.1), 0.001);

        // A pass that fills the period still pauses, rather than dropping to nothing.
        $this->assertGreaterThan(0, $cls::idlePauseSeconds(2.0));

        // And a pass that badly overruns pauses too. This is the case that matters:
        // before the floor existed this returned nothing at all.
        $this->assertGreaterThanOrEqual(0.5, $cls::idlePauseSeconds(30.0));
    }
}
