<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\Checks\CallbackCheck;
use App\Monitoring\OutcomeResult;
use App\Monitoring\PlatformStatusWriter;
use App\Monitoring\ScheduledOutcomeRegistry;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform status dot in ModTools reads what monitor:scheduled-outcomes
 * publishes. These cover the publishing half — the Go side (serving it, and
 * refusing to serve a stale one as current) is covered in
 * iznik-server-go/test/status_test.go.
 *
 * The registry is faked the same way MonitorScheduledOutcomesCommandTest does
 * it, so these assert on the publishing behaviour rather than on whichever
 * checks happen to be registered today.
 */
class PlatformStatusPublishingTest extends TestCase
{
    /**
     * @param  array<int, \App\Monitoring\OutcomeCheck>  $checks
     */
    private function fakeRegistry(array $checks): void
    {
        $registry = new class($checks) extends ScheduledOutcomeRegistry
        {
            public function __construct(private array $fakeChecks)
            {
            }

            public function checks(): array
            {
                return $this->fakeChecks;
            }
        };

        $this->app->instance(ScheduledOutcomeRegistry::class, $registry);
    }

    private function publishedStatus(): ?array
    {
        $raw = DB::table('config')->where('key', PlatformStatusWriter::CONFIG_KEY)->value('value');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function test_a_clean_pass_publishes_a_status_with_no_problems(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'fine')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::skipped('job:b', 'not applicable')),
        ]);

        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(0);

        $status = $this->publishedStatus();

        $this->assertNotNull($status, 'A clean pass must still publish, or the dot goes stale.');
        $this->assertSame(0, $status['ret']);
        $this->assertFalse($status['error']);
        $this->assertFalse($status['warning']);
        $this->assertSame([], $status['info']);
        $this->assertNotEmpty($status['generated_at']);
    }

    public function test_a_breach_is_published_as_an_error_naming_the_job(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'fine')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'no rows since Tuesday')),
        ]);

        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(1);

        $status = $this->publishedStatus();

        $this->assertTrue($status['error']);
        $this->assertArrayHasKey('job:b', $status['info']);
        $this->assertTrue($status['info']['job:b']['error']);
        $this->assertSame('no rows since Tuesday', $status['info']['job:b']['errortext']);

        // The healthy check is deliberately absent: the modal renders one row
        // per entry, so carrying the OK checks would bury the real problem.
        $this->assertArrayNotHasKey('job:a', $status['info']);
    }

    public function test_a_non_error_severity_publishes_as_a_warning_not_an_error(): void
    {
        $this->fakeRegistry([
            new CallbackCheck(
                'job:b',
                fn ($now) => OutcomeResult::breach('job:b', 'lagging a little', 'warning')
            ),
        ]);

        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(1);

        $status = $this->publishedStatus();

        $this->assertFalse($status['error']);
        $this->assertTrue($status['warning']);
        $this->assertTrue($status['info']['job:b']['warning']);
        $this->assertSame('lagging a little', $status['info']['job:b']['warningtext']);
    }

    public function test_a_later_pass_replaces_the_previous_status(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'broken')),
        ]);
        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(1);
        $this->assertTrue($this->publishedStatus()['error']);

        // Recovery has to clear the dot; a status that only ever accumulates
        // problems would stay red after the problem was fixed.
        $this->fakeRegistry([
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::ok('job:b', 'fixed')),
        ]);
        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(0);

        $status = $this->publishedStatus();
        $this->assertFalse($status['error']);
        $this->assertSame([], $status['info']);

        $this->assertSame(
            1,
            DB::table('config')->where('key', PlatformStatusWriter::CONFIG_KEY)->count(),
            'The status must be upserted into one row, not appended.'
        );
    }

    public function test_only_option_does_not_publish_a_partial_status(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'fine')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'broken')),
        ]);

        $this->artisan('monitor:scheduled-outcomes --only=job:a')->assertExitCode(0);

        // job:b was never evaluated. Publishing this pass would report the
        // whole platform as fine on the evidence of a single check.
        $this->assertNull($this->publishedStatus());
    }

    public function test_disabled_monitoring_does_not_publish(): void
    {
        config(['freegle.monitoring.enabled' => false]);

        $this->fakeRegistry([
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'broken')),
        ]);

        $this->artisan('monitor:scheduled-outcomes')->assertExitCode(0);

        // With monitoring off nothing is evaluated, so the status must go stale
        // and be reported as such, rather than be pinned to a stale "fine".
        $this->assertNull($this->publishedStatus());
    }
}
