<?php

namespace Tests\Feature\Command;

use App\Console\Commands\Deploy\RefreshCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeployRefreshCommandTest extends TestCase
{
    /**
     * Test that deploy:refresh runs successfully and produces expected output.
     *
     * Note: We verify behavior via output assertions rather than mocking Artisan::call(),
     * which causes issues with Laravel's error handling (694 "risky" tests in CI).
     * The actual cache operations run during this test, which is safe because:
     * 1. Config/route/event caches are cleared then rebuilt by subsequent tests
     * 2. view:cache precompiles views (doesn't corrupt them)
     * 3. Bootstrap cache files (services.php, packages.php) are verified to exist
     */
    public function test_clears_and_optimizes_caches(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Refreshing application after deployment...')
            ->expectsOutput('Clearing environment-specific caches...')
            ->assertSuccessful();
    }

    public function test_verifies_bootstrap_cache_files(): void
    {
        // Ensure bootstrap cache files exist (they should be committed to git)
        $servicesPath = base_path('bootstrap/cache/services.php');
        $packagesPath = base_path('bootstrap/cache/packages.php');

        $this->assertFileExists($servicesPath, 'services.php should be committed to git');
        $this->assertFileExists($packagesPath, 'packages.php should be committed to git');
        $this->assertGreaterThan(0, filesize($servicesPath), 'services.php should not be empty');
        $this->assertGreaterThan(0, filesize($packagesPath), 'packages.php should not be empty');

        // Run deploy:refresh
        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Verify files still exist and have content after the command ran
        clearstatcache();
        $this->assertFileExists($servicesPath);
        $this->assertFileExists($packagesPath);
        $this->assertGreaterThan(0, filesize($servicesPath), 'services.php should not be empty after refresh');
        $this->assertGreaterThan(0, filesize($packagesPath), 'packages.php should not be empty after refresh');
    }

    public function test_restarts_queue_workers(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Restarting queue workers...')
            ->assertSuccessful();
    }

    public function test_attempts_supervisor_restart(): void
    {
        $this->artisan('deploy:refresh')
            ->expectsOutput('Restarting supervisor programs...')
            ->assertSuccessful();
    }

    public function test_records_deployed_version(): void
    {
        // Ensure version file exists.
        $versionFile = base_path('version.txt');
        $this->assertFileExists($versionFile);

        // Clear any cached version.
        Cache::forget(RefreshCommand::VERSION_CACHE_KEY);

        $this->artisan('deploy:refresh')
            ->assertSuccessful();

        // Check that version was cached.
        $cachedVersion = Cache::get(RefreshCommand::VERSION_CACHE_KEY);
        $this->assertNotNull($cachedVersion);
    }

    public function test_get_current_version_returns_file_content(): void
    {
        $version = RefreshCommand::getCurrentVersion();
        $this->assertNotNull($version);
        $this->assertStringContainsString('0', $version); // Initial version is 0
    }

    /**
     * The deployed commit is read from the checkout's own .git, which the test
     * container does not have (it holds a copy of the tree, not a clone). So the
     * tests make a checkout of their own rather than skipping.
     */
    private array $checkouts = [];

    protected function tearDown(): void
    {
        foreach ($this->checkouts as $dir) {
            foreach (array_reverse($this->filesUnder($dir)) as $path) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
            rmdir($dir);
        }

        parent::tearDown();
    }

    /** @return list<string> every file and directory under $dir, parents first */
    private function filesUnder(string $dir): array
    {
        $found = [];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $found[] = $path;
            if (is_dir($path)) {
                $found = array_merge($found, $this->filesUnder($path));
            }
        }

        return $found;
    }

    /**
     * A checkout whose HEAD is $sha: either the normal shape (HEAD is a symbolic
     * ref to a branch file) or detached (HEAD holds the SHA itself).
     */
    private function fakeCheckout(string $sha, bool $detached = false): string
    {
        $dir = sys_get_temp_dir() . '/deploy-refresh-' . uniqid('', true);
        mkdir($dir . '/.git/refs/heads', 0777, true);
        $this->checkouts[] = $dir;

        if ($detached) {
            file_put_contents($dir . '/.git/HEAD', $sha . "\n");
        } else {
            file_put_contents($dir . '/.git/HEAD', "ref: refs/heads/master\n");
            file_put_contents($dir . '/.git/refs/heads/master', $sha . "\n");
        }

        return $dir;
    }

    private function readGitHead(string $dir): ?string
    {
        return (new \ReflectionMethod(RefreshCommand::class, 'readGitHead'))->invoke(new RefreshCommand(), $dir);
    }

    public function test_deploy_refresh_records_laravel_commit_to_db(): void
    {
        $sha = 'c418742a4c0ec184a4dcbc6d96e03423499be35e';
        $checkout = $this->fakeCheckout($sha);
        $this->app->bind(RefreshCommand::class, fn () => new class ($checkout) extends RefreshCommand {
            public function __construct(private readonly string $checkout)
            {
                parent::__construct();
            }

            protected function repositoryPath(): string
            {
                return $this->checkout;
            }
        });

        DB::table('config')->where('key', 'deploy.laravel_commit')->delete();

        $this->artisan('deploy:refresh')
            ->expectsOutputToContain("Recorded Laravel deploy commit: {$sha}")
            ->assertSuccessful();

        $this->assertSame($sha, DB::table('config')->where('key', 'deploy.laravel_commit')->value('value'));
    }

    public function test_read_git_head_returns_null_for_non_git_dir(): void
    {
        $this->assertNull($this->readGitHead(sys_get_temp_dir()));
    }

    public function test_read_git_head_follows_a_symbolic_ref(): void
    {
        $sha = str_repeat('ab', 20);

        $this->assertSame($sha, $this->readGitHead($this->fakeCheckout($sha)));
    }

    public function test_read_git_head_reads_a_detached_head(): void
    {
        $sha = str_repeat('cd', 20);

        $this->assertSame($sha, $this->readGitHead($this->fakeCheckout($sha, detached: true)));
    }
}
