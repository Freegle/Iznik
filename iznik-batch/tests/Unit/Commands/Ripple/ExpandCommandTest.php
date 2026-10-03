<?php

namespace Tests\Unit\Commands\Ripple;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpandCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Rippling ships dark; enable it so the command exercises the real engine path.
        config(['freegle.ripple.enabled' => true]);
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');
    }

    public function test_command_runs_clean_and_reports(): void
    {
        Http::fake(); // no spatial posts → no routing calls, but be safe

        $this->artisan('ripple:expand', ['--dry-run' => true, '--limit' => 10])
            ->expectsOutputToContain('Initialised:')
            ->assertExitCode(0);
    }

    /**
     * The site tells a member when a post is due to reach their area, and that date comes
     * from the hazard schedule: tick k goes live at arrival + hazard_hours[k-1]. The API
     * runs on a different server, so the schedule is published here rather than restated
     * there, where the two could drift and we would quote an arrival that never happens.
     */
    public function test_publishes_hazard_hours_to_config(): void
    {
        Http::fake();
        config(['freegle.ripple.hazard_hours' => [1, 3, 6, 12, 24, 48, 72, 120, 168]]);
        DB::table('config')->where('key', 'ripple.hazard_hours')->delete();

        $this->artisan('ripple:expand', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame(
            [1, 3, 6, 12, 24, 48, 72, 120, 168],
            json_decode((string) DB::table('config')->where('key', 'ripple.hazard_hours')->value('value'), true),
            'the hazard schedule is mirrored into config for the Go API to read'
        );

        // A changed schedule upserts rather than duplicating, and re-dates every estimate
        // the API gives from that point on.
        config(['freegle.ripple.hazard_hours' => [2, 4, 8]]);
        $this->artisan('ripple:expand', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame(
            [2, 4, 8],
            json_decode((string) DB::table('config')->where('key', 'ripple.hazard_hours')->value('value'), true)
        );
        $this->assertSame(1, DB::table('config')->where('key', 'ripple.hazard_hours')->count());

        DB::table('config')->where('key', 'ripple.hazard_hours')->delete();
    }

    /**
     * Single-instance guard: the scheduler's withoutOverlapping() is unreliable for
     * runInBackground() jobs (the overlap mutex is freed when the foreground tick forks), so on
     * 2026-06-26 dozens of ripple:expand runs piled up and starved the serial worker with
     * messages_groups/logs lock waits. The command now takes a DB-backed Cache lock and exits
     * cleanly if another run holds it. We prove it SKIPPED the body by asserting publishHazardHours
     * (which only runs inside the guarded run()) never wrote the hazard-hours config row.
     */
    public function test_second_run_exits_without_working_while_the_lock_is_held(): void
    {
        Http::fake();
        DB::table('config')->where('key', 'ripple.hazard_hours')->delete();

        $held = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue($held->get(), 'precondition: acquire the lock as another run');

        try {
            $this->artisan('ripple:expand', ['--limit' => 1])
                ->expectsOutputToContain('Another ripple:expand run is in progress')
                ->assertExitCode(0);

            $this->assertNull(
                DB::table('config')->where('key', 'ripple.hazard_hours')->value('value'),
                'a locked-out run must not reach publishHazardHours (body was skipped)'
            );
        } finally {
            $held->release();
        }

        DB::table('config')->where('key', 'ripple.hazard_hours')->delete();
    }

    /**
     * A normal run releases the lock when it finishes, so the next scheduled run can acquire it.
     */
    public function test_normal_run_releases_the_lock_when_it_finishes(): void
    {
        Http::fake();

        $this->artisan('ripple:expand', ['--limit' => 1])->assertExitCode(0);

        $after = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue(
            $after->get(),
            'lock must be free after a normal run completes (released in finally)'
        );
        $after->release();
    }

    /**
     * Controlled one-offs are exempt from the single-instance lock: an operator must be able to run
     * --dry-run / --msgid alongside the scheduled cron (they do no bulk expansion). With the lock
     * held, both still execute their body (proved by their run-only output lines).
     */
    public function test_dry_run_is_exempt_from_the_single_instance_lock(): void
    {
        Http::fake();

        $held = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue($held->get());

        try {
            // Assert the dry-run notice line ('...no reach will be written') AND the summary line
            // ('Initialised:'), proving the body ran despite the held lock. Deliberately NOT
            // 'DRY RUN': the summary line is prefixed '[DRY RUN] Initialised: ...', so it contains
            // BOTH substrings. Laravel's PendingCommand registers one doWrite matcher per expected
            // substring; a line matching several is consumed by the FIRST matcher only, so a
            // 'DRY RUN' expectation would swallow the summary line and starve the 'Initialised:'
            // matcher (false "Output does not contain 'Initialised:'"). 'no reach will be written'
            // appears only on the notice line, so the two expectations never overlap.
            $this->artisan('ripple:expand', ['--dry-run' => true, '--limit' => 1])
                ->expectsOutputToContain('no reach will be written')
                ->expectsOutputToContain('Initialised:')
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }

    public function test_msgid_run_is_exempt_from_the_single_instance_lock(): void
    {
        Http::fake();

        $held = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue($held->get());

        try {
            $this->artisan('ripple:expand', ['--msgid' => 999999])
                ->expectsOutputToContain('Restricting run to message ID: 999999')
                ->assertExitCode(0);
        } finally {
            $held->release();
        }
    }
}
