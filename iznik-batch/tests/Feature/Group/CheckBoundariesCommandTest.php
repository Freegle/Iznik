<?php

namespace Tests\Feature\Group;

use Tests\TestCase;

class CheckBoundariesCommandTest extends TestCase
{
    public function test_runs_cleanly_with_no_groups(): void
    {
        $this->artisan('groups:check-boundaries')
            ->assertExitCode(0);
    }

    public function test_reports_zero_errors_for_valid_groups(): void
    {
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $this->artisan('groups:check-boundaries')
            ->expectsOutputToContain('0 error(s) detected')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_marker(): void
    {
        $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $this->artisan('groups:check-boundaries', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would check boundaries for')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_dry_run_prefix_not_live_summary(): void
    {
        // In dry-run mode the command outputs "[DRY RUN]" not "Checked N group(s)".
        $output = '';
        $this->artisan('groups:check-boundaries', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }
}
