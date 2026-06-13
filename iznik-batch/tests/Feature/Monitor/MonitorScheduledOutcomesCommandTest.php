<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\Checks\CallbackCheck;
use App\Monitoring\OutcomeResult;
use App\Monitoring\ScheduledOutcomeRegistry;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for monitor:scheduled-outcomes.
 *
 * The command's job is orchestration: run each registered check, print a line
 * per result, and on any BREACH escalate to Sentry + Log::warning and exit
 * non-zero. SKIPPED results never fail the command.
 *
 * We inject a fake registry (returning controllable CallbackChecks) so these
 * tests exercise the command logic in isolation from the real registry's
 * table/column wiring — that wiring is covered by ScheduledOutcomeRegistryTest.
 *
 * As in EmailHealthCommandTest, we assert on Log::warning + exit code rather
 * than the guarded \Sentry\captureMessage (a global not loaded under test).
 */
class MonitorScheduledOutcomesCommandTest extends TestCase
{
    /**
     * Bind a registry whose checks() returns the given checks.
     *
     * @param  array<int, \App\Monitoring\OutcomeCheck>  $checks
     */
    private function fakeRegistry(array $checks): void
    {
        $registry = new class($checks) extends ScheduledOutcomeRegistry {
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

    public function test_reports_success_when_all_checks_pass(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'fine')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::ok('job:b', 'fine')),
        ]);

        Log::spy();

        $this->artisan('monitor:scheduled-outcomes')
            ->expectsOutputToContain('All scheduled-outcome checks OK.')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_reports_failure_and_logs_when_a_check_breaches(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'fine')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'did not run today')),
        ]);

        Log::spy();

        $this->artisan('monitor:scheduled-outcomes')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'job:b')
                && str_contains((string) $message, 'did not run today')
            );
    }

    public function test_skipped_checks_do_not_cause_failure(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::skipped('job:a', 'disabled')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::ok('job:b', 'fine')),
        ]);

        Log::spy();

        $this->artisan('monitor:scheduled-outcomes')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_only_option_runs_a_single_check(): void
    {
        $this->fakeRegistry([
            new CallbackCheck('job:a', fn ($now) => OutcomeResult::ok('job:a', 'alpha-ran')),
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'beta-breached')),
        ]);

        // Only job:a is run, so the breaching job:b is never evaluated → exit 0.
        $this->artisan('monitor:scheduled-outcomes --only=job:a')
            ->expectsOutputToContain('alpha-ran')
            ->doesntExpectOutputToContain('beta-breached')
            ->assertExitCode(0);
    }

    public function test_disabled_via_config_noops_even_with_a_breach(): void
    {
        config(['freegle.monitoring.enabled' => false]);

        $this->fakeRegistry([
            new CallbackCheck('job:b', fn ($now) => OutcomeResult::breach('job:b', 'would breach')),
        ]);

        Log::spy();

        $this->artisan('monitor:scheduled-outcomes')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        Log::shouldNotHaveReceived('warning');
    }
}
