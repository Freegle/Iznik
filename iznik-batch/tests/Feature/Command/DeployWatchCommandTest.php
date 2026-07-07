<?php

namespace Tests\Feature\Command;

use App\Console\Commands\Deploy\RefreshCommand;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeployWatchCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cached version before each test.
        Cache::forget(RefreshCommand::VERSION_CACHE_KEY);
    }

    public function test_first_run_records_version_without_refresh(): void
    {
        $this->artisan('deploy:watch')
            ->expectsOutput('First run - recording current version.')
            ->assertSuccessful();

        // Should have cached the version.
        $cachedVersion = Cache::get(RefreshCommand::VERSION_CACHE_KEY);
        $this->assertNotNull($cachedVersion);
    }

    public function test_no_change_skips_refresh(): void
    {
        // First run to record version.
        $this->artisan('deploy:watch');

        // Second run should see no change.
        $this->artisan('deploy:watch')
            ->expectsOutput('Version unchanged - no refresh needed.')
            ->assertSuccessful();
    }

    public function test_version_change_detected(): void
    {
        // First run to record version.
        $this->artisan('deploy:watch');

        // Change the cached version to simulate a new deployment.
        Cache::forever(RefreshCommand::VERSION_CACHE_KEY, "999\ncommit: fake\nsha: fake\ntimestamp: fake");

        // Touch the version file to make it recent (within settle time).
        touch(base_path('version.txt'));

        // Should detect change but wait for settle time.
        $this->artisan('deploy:watch --settle=300')
            ->expectsOutput('Version changed but file upload may still be in progress - waiting...')
            ->assertSuccessful();
    }

    public function test_force_triggers_refresh(): void
    {
        // The --force flag triggers deploy:refresh which runs cache operations.
        // We verify the output rather than mocking Artisan::call() (which causes
        // "removed error handlers" warnings affecting all tests).
        $this->artisan('deploy:watch --force')
            ->expectsOutput('Forcing deployment refresh...')
            ->expectsOutput('Refreshing application after deployment...')
            ->assertSuccessful();
    }

    public function test_missing_version_file_handled(): void
    {
        // Temporarily rename the version file.
        $versionFile = base_path('version.txt');
        $backupFile = base_path('version.txt.bak');

        if (file_exists($versionFile)) {
            rename($versionFile, $backupFile);
        }

        try {
            $this->artisan('deploy:watch')
                ->expectsOutput('No version.txt file found - skipping check.')
                ->assertSuccessful();
        } finally {
            // Restore the version file.
            if (file_exists($backupFile)) {
                rename($backupFile, $versionFile);
            }
        }
    }
}
