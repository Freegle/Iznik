<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WatchCommand extends Command
{
    protected $signature = 'deploy:watch
                            {--settle=300 : Seconds to wait after file change before triggering refresh (default 5 minutes)}
                            {--force : Force refresh even if version unchanged}';

    protected $description = 'Watch for code deployments and automatically refresh the application';

    /**
     * Minimum age in seconds for the version file before we consider the upload complete.
     */
    protected int $settleTime;

    public function handle(): int
    {
        $this->settleTime = (int) $this->option('settle');

        if ($this->option('force')) {
            $this->info('Forcing deployment refresh...');

            return $this->triggerRefresh();
        }

        $currentVersion = RefreshCommand::getCurrentVersion();
        $deployedVersion = RefreshCommand::getDeployedVersion();

        if ($currentVersion === null) {
            $this->line('No version.txt file found - skipping check.');

            return Command::SUCCESS;
        }

        // First run: just record the current version without refreshing.
        if ($deployedVersion === null) {
            $this->info('First run - recording current version.');
            Cache::forever(RefreshCommand::VERSION_CACHE_KEY, $currentVersion);

            return Command::SUCCESS;
        }

        // Check if version has changed.
        if ($this->versionsMatch($currentVersion, $deployedVersion)) {
            $this->line('Version unchanged - no refresh needed.');

            return Command::SUCCESS;
        }

        // Version changed - check if file upload is complete.
        if (! $this->isUploadComplete()) {
            $this->info('Version changed but file upload may still be in progress - waiting...');

            return Command::SUCCESS;
        }

        $this->info('New deployment detected!');
        $this->line("  Previous: {$this->extractVersionNumber($deployedVersion)}");
        $this->line("  Current:  {$this->extractVersionNumber($currentVersion)}");

        return $this->triggerRefresh();
    }

    /**
     * Check if the version numbers match (comparing just the numeric version).
     */
    protected function versionsMatch(string $current, string $deployed): bool
    {
        return $this->extractVersionNumber($current) === $this->extractVersionNumber($deployed);
    }

    /**
     * Extract just the version number from the version file content.
     */
    protected function extractVersionNumber(string $content): string
    {
        // First line should be the version number.
        $lines = explode("\n", trim($content));

        return trim($lines[0] ?? '0');
    }

    /**
     * Check if enough time has passed since the version file was modified.
     * This helps ensure the file upload is complete.
     */
    protected function isUploadComplete(): bool
    {
        $versionFile = base_path('version.txt');

        if (! file_exists($versionFile)) {
            return false;
        }

        $mtime = filemtime($versionFile);
        $age = time() - $mtime;

        if ($age < $this->settleTime) {
            $this->line("  Version file is {$age}s old, waiting for {$this->settleTime}s settle time...");

            return false;
        }

        return true;
    }

    /**
     * Trigger the deployment refresh.
     */
    protected function triggerRefresh(): int
    {
        $this->newLine();

        return $this->call('deploy:refresh');
    }
}
