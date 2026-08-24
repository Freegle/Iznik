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
     * publishTrialGroups mirrors RIPPLE_WITHIN_GROUPS into the shared `config` table so the Go API
     * (which runs on a different server and can't read this batch env var) can scope the rippling
     * dashboard to the trial groups. Upserts a comma-separated id list keyed 'ripple.within_groups'.
     */
    public function test_publishes_trial_group_set_to_config(): void
    {
        Http::fake();
        config(['freegle.ripple.within_groups' => ['111', '222']]);
        DB::table('config')->where('key', 'ripple.within_groups')->delete();

        // A real (non-dry-run) run mirrors RIPPLE_WITHIN_GROUPS into config via handle(),
        // so the Go API (a different server, no access to this batch env var) can read the
        // trial set. --dry-run deliberately skips the publish (covered by the run-clean test).
        $this->artisan('ripple:expand', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame(
            '111,222',
            DB::table('config')->where('key', 'ripple.within_groups')->value('value'),
            'RIPPLE_WITHIN_GROUPS is mirrored into config for the Go API to read'
        );

        // Re-running with a changed set upserts (one row, updated value), not a duplicate.
        config(['freegle.ripple.within_groups' => ['333']]);
        $this->artisan('ripple:expand', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame('333', DB::table('config')->where('key', 'ripple.within_groups')->value('value'));
        $this->assertSame(1, DB::table('config')->where('key', 'ripple.within_groups')->count());

        DB::table('config')->where('key', 'ripple.within_groups')->delete();
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
     * --within-group accepts a comma-separated list of group ids (the experiment scope) and resolves
     * it to the union of those groups' polyindex polygons. MySQL ST_Union is binary, so the command
     * chains it over per-id subqueries; this exercises that path with two real group polygons.
     */
    public function test_within_group_accepts_a_csl_and_resolves_the_union(): void
    {
        Http::fake();
        $g1 = $this->createTestGroup();
        $g2 = $this->createTestGroup();
        DB::statement(
            "UPDATE `groups` SET polyindex = ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857) WHERE id = ?",
            [$g1->id]
        );
        DB::statement(
            "UPDATE `groups` SET polyindex = ST_GeomFromText('POLYGON((0.0 51.4,0.2 51.4,0.2 51.6,0.0 51.6,0.0 51.4))', 3857) WHERE id = ?",
            [$g2->id]
        );

        $this->artisan('ripple:expand', [
            '--within-group' => $g1->id.','.$g2->id,
            '--dry-run' => true,
            '--limit' => 10,
        ])
            ->expectsOutputToContain('union of 2 area polygon(s)')
            ->assertExitCode(0);
    }

    /**
     * Single-instance guard: the scheduler's withoutOverlapping() is unreliable for
     * runInBackground() jobs (the overlap mutex is freed when the foreground tick forks), so on
     * 2026-06-26 dozens of ripple:expand runs piled up and starved the serial worker with
     * messages_groups/logs lock waits. The command now takes a DB-backed Cache lock and exits
     * cleanly if another run holds it. We prove it SKIPPED the body by asserting publishTrialGroups
     * (which only runs inside the guarded run()) never wrote the trial-group config row.
     */
    public function test_second_run_exits_without_working_while_the_lock_is_held(): void
    {
        Http::fake();
        config(['freegle.ripple.within_groups' => ['111', '222']]);
        DB::table('config')->where('key', 'ripple.within_groups')->delete();

        $held = Cache::lock('ripple:expand:run', 30);
        $this->assertTrue($held->get(), 'precondition: acquire the lock as another run');

        try {
            $this->artisan('ripple:expand', ['--limit' => 1])
                ->expectsOutputToContain('Another ripple:expand run is in progress')
                ->assertExitCode(0);

            $this->assertNull(
                DB::table('config')->where('key', 'ripple.within_groups')->value('value'),
                'a locked-out run must not reach publishTrialGroups (body was skipped)'
            );
        } finally {
            $held->release();
        }

        DB::table('config')->where('key', 'ripple.within_groups')->delete();
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
