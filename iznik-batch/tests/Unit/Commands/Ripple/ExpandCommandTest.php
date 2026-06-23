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
