<?php

namespace Tests\Feature\Cleanup;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CleanupSessionsCommandTest extends TestCase
{
    public function test_cleanup_sessions_command_runs_successfully(): void
    {
        $this->artisan('cleanup:sessions')
            ->assertExitCode(0);
    }

    public function test_cleanup_sessions_displays_stats(): void
    {
        $this->artisan('cleanup:sessions')
            ->expectsOutputToContain('Cleaning up session data')
            ->expectsOutputToContain('Cleaning up old sessions')
            ->expectsOutputToContain('Cleaning up old login links')
            ->expectsOutputToContain('Session cleanup')
            ->assertExitCode(0);
    }

    public function test_cleanup_sessions_with_custom_days(): void
    {
        $this->artisan('cleanup:sessions', ['--days' => 7])
            ->assertExitCode(0);
    }

    public function test_cleanup_sessions_removes_old_data(): void
    {
        $user = $this->createTestUser();

        // Create an old session.
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 1,
            'token' => 'old-' . uniqid(),
            'date' => now()->subDays(40),
            'lastactive' => now()->subDays(40),
        ]);

        $this->artisan('cleanup:sessions', ['--days' => 31])
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('sessions')
            ->where('userid', $user->id)
            ->where('series', 1)
            ->count());
    }

    public function test_cleanup_sessions_dry_run_does_not_delete(): void
    {
        $user = $this->createTestUser();

        $token = 'dryrun-' . uniqid();
        DB::table('sessions')->insert([
            'userid' => $user->id,
            'series' => 2,
            'token' => $token,
            'date' => now()->subDays(40),
            'lastactive' => now()->subDays(40),
        ]);

        $this->artisan('cleanup:sessions', ['--days' => 31, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would remove')
            ->assertExitCode(0);

        // Session should still exist.
        $this->assertEquals(1, DB::table('sessions')
            ->where('userid', $user->id)
            ->where('series', 2)
            ->count());
    }
}
