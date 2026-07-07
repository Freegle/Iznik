<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshCommand extends Command
{
    protected $signature = 'deploy:refresh';

    protected $description = 'Refresh application after deployment: clear environment caches, restart workers and daemons';

    /**
     * Cache key for storing last deployed version.
     */
    public const VERSION_CACHE_KEY = 'deploy:last_version';

    /**
     * Supervisor programs to restart.
     */
    protected array $supervisorPrograms = [
        'mail-spooler',
    ];

    public function handle(): int
    {
        $this->info('Refreshing application after deployment...');
        $this->newLine();

        $this->clearAndOptimize();
        $this->restartQueueWorkers();
        $this->restartSupervisorPrograms();
        $this->updateDeployedVersion();

        $this->newLine();
        $this->info('Done!');

        return Command::SUCCESS;
    }

    protected function clearAndOptimize(): void
    {
        $this->info('Clearing environment-specific caches...');

        // IMPORTANT: Do NOT use optimize:clear or optimize to regenerate services.php!
        // services.php and packages.php are committed to git and must not be deleted
        // at runtime - doing so causes race conditions when multiple processes bootstrap
        // Laravel simultaneously. See: https://github.com/orchestral/testbench/issues/202
        //
        // However, we DO run package:discover to ensure packages.php is in sync with
        // composer.lock after deployment (in case new packages were added).

        // Step 1: Verify bootstrap cache files exist and run package:discover
        $this->verifyBootstrapCache();

        // Step 2: Clear environment-specific caches (config, routes, events, views).

        try {
            Artisan::call('config:clear');
            $this->line('  <info>✓</info> Cleared config cache');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Config clear failed: '.$e->getMessage());
        }

        try {
            Artisan::call('route:clear');
            $this->line('  <info>✓</info> Cleared route cache');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Route clear failed: '.$e->getMessage());
        }

        try {
            Artisan::call('event:clear');
            $this->line('  <info>✓</info> Cleared event cache');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Event clear failed: '.$e->getMessage());
        }

        try {
            // view:cache clears then precompiles all views.
            // Precompilation prevents race conditions during view compilation.
            Artisan::call('view:cache');
            $this->line('  <info>✓</info> Precompiled views');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> View cache failed: '.$e->getMessage());
        }

        try {
            Artisan::call('cache:clear');
            $this->line('  <info>✓</info> Cleared application cache');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Cache clear failed: '.$e->getMessage());
        }
    }

    protected function verifyBootstrapCache(): void
    {
        $servicesPath = base_path('bootstrap/cache/services.php');
        $packagesPath = base_path('bootstrap/cache/packages.php');

        // Check services.php exists and has content
        if (! file_exists($servicesPath) || filesize($servicesPath) === 0) {
            $this->line('  <comment>⚠</comment> services.php missing or empty - this should be committed to git');
        } else {
            $this->line('  <info>✓</info> services.php verified');
        }

        // Run package:discover to ensure packages.php is current
        // This is safe even if the file exists - it just updates it
        try {
            Artisan::call('package:discover');
            $this->line('  <info>✓</info> Package manifest updated');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Package discover failed: '.$e->getMessage());
        }

        // Verify packages.php now exists and has content
        if (! file_exists($packagesPath) || filesize($packagesPath) === 0) {
            $this->error('  packages.php is missing or empty after package:discover');
        } else {
            $this->line('  <info>✓</info> packages.php verified');
        }
    }

    protected function restartQueueWorkers(): void
    {
        $this->newLine();
        $this->info('Restarting queue workers...');

        try {
            Artisan::call('queue:restart');
            $this->line('  <info>✓</info> Queue workers signaled');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> '.$e->getMessage());
        }
    }

    protected function restartSupervisorPrograms(): void
    {
        $this->newLine();
        $this->info('Restarting supervisor programs...');

        // Check if supervisorctl is available.
        exec('which supervisorctl 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->line('  <comment>⚠</comment> supervisorctl not available');

            return;
        }

        foreach ($this->supervisorPrograms as $program) {
            $this->restartProgram($program);
        }
    }

    protected function restartProgram(string $program): void
    {
        exec("supervisorctl restart {$program} 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            $this->line("  <info>✓</info> {$program}");
        } else {
            $this->line("  <comment>⚠</comment> {$program}: ".implode(' ', $output));
        }
    }

    protected function updateDeployedVersion(): void
    {
        $versionFile = base_path('version.txt');

        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
            Cache::forever(self::VERSION_CACHE_KEY, $version);
            $this->newLine();
            $this->line('  <info>✓</info> Recorded deployed version');
        }

        // Read the deployed git commit SHA from the working-directory checkout.
        // This is more reliable than BUILD_INFO (which isn't set in production deploys)
        // and allows the Go API to query the DB to find out what Laravel commit is running.
        $commit = $this->readGitHead(base_path());
        if ($commit) {
            DB::table('config')->upsert(
                [['key' => 'deploy.laravel_commit', 'value' => $commit]],
                ['key'],
                ['value'],
            );
            $this->line("  <info>✓</info> Recorded Laravel deploy commit: {$commit}");
        }
    }

    /**
     * Read the HEAD commit SHA from a git working directory.
     */
    protected function readGitHead(string $dir): ?string
    {
        $headFile = $dir . '/.git/HEAD';
        if (! file_exists($headFile)) {
            return null;
        }

        $head = trim(file_get_contents($headFile));

        // Detached HEAD: the file contains the SHA directly.
        if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
            return $head;
        }

        // Symbolic ref: e.g. "ref: refs/heads/master"
        if (str_starts_with($head, 'ref: ')) {
            $refPath = $dir . '/.git/' . substr($head, 5);
            if (file_exists($refPath)) {
                return trim(file_get_contents($refPath));
            }
        }

        return null;
    }

    /**
     * Get the current version from the version file.
     */
    public static function getCurrentVersion(): ?string
    {
        $versionFile = base_path('version.txt');

        if (! file_exists($versionFile)) {
            return null;
        }

        return trim(file_get_contents($versionFile));
    }

    /**
     * Get the last deployed version from cache.
     */
    public static function getDeployedVersion(): ?string
    {
        return Cache::get(self::VERSION_CACHE_KEY);
    }
}
