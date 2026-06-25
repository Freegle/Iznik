<?php

namespace Tests\Unit\Commands\Ripple;

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
}
