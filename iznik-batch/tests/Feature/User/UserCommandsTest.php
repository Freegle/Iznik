<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    public function test_process_bounced_command_runs_successfully(): void
    {
        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_process_bounced_displays_stats(): void
    {
        $this->artisan('mail:bounced')
            ->expectsOutputToContain('Processing bounced emails')
            ->expectsOutputToContain('Processed:')
            ->expectsOutputToContain('Marked invalid:')
            ->assertExitCode(0);
    }

    public function test_process_bounced_with_bounced_email(): void
    {
        $user = $this->createTestUser();

        // Add a bounced email.
        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('bounced'),
            'bounced' => now()->subDays(1),
            'added' => now()->subDays(30),
        ]);

        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_cleanup_command_runs_successfully(): void
    {
        $this->artisan('users:cleanup')
            ->assertExitCode(0);
    }

    public function test_cleanup_displays_table(): void
    {
        $this->artisan('users:cleanup')
            ->expectsOutputToContain('Running user cleanup')
            ->expectsOutputToContain('Delete Yahoo Groups users')
            ->expectsOutputToContain('Forget inactive users')
            ->expectsOutputToContain('Process GDPR forgets')
            ->expectsOutputToContain('Delete fully forgotten users')
            ->assertExitCode(0);
    }

    public function test_cleanup_dry_run(): void
    {
        $this->artisan('users:cleanup --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

}
