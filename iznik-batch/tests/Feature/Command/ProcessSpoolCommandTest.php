<?php

namespace Tests\Feature\Command;

use Tests\TestCase;

class ProcessSpoolCommandTest extends TestCase
{
    public function test_process_spool_command_runs(): void
    {
        $this->artisan('mail:spool:process')
            ->assertExitCode(0);
    }

    public function test_stats_option_shows_statistics(): void
    {
        $this->artisan('mail:spool:process', ['--stats' => true])
            ->expectsOutputToContain('Email Spool Statistics')
            ->expectsOutputToContain('Pending')
            ->expectsOutputToContain('Failed')
            ->expectsOutputToContain('Status')
            ->assertExitCode(0);
    }

    public function test_cleanup_option_runs_cleanup(): void
    {
        $this->artisan('mail:spool:process', ['--cleanup' => true])
            ->expectsOutputToContain('Cleaning up sent emails')
            ->expectsOutputToContain('Deleted')
            ->assertExitCode(0);
    }

    public function test_retry_failed_option(): void
    {
        $this->artisan('mail:spool:process', ['--retry-failed' => true])
            ->expectsOutputToContain('Retrying all failed emails')
            ->expectsOutputToContain('Moved')
            ->assertExitCode(0);
    }

    public function test_custom_limit_option(): void
    {
        $this->artisan('mail:spool:process', ['--limit' => 50])
            ->expectsOutputToContain('Processing email spool')
            ->assertExitCode(0);
    }
}
