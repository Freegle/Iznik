<?php

namespace Tests\Unit\Services;

use App\Services\LogRotationService;
use Tests\TestCase;

class LogRotationServiceTest extends TestCase
{
    private string $dir;
    private LogRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LogRotationService();
        $this->dir = sys_get_temp_dir().'/logrot_test_'.uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * Create a fixture file with a controlled modification time.
     */
    private function writeFile(string $name, string $contents, int $ageDays = 0): string
    {
        $path = $this->dir.'/'.$name;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
        // file_put_contents bumps mtime to now; pin it back to the desired age.
        touch($path, time() - ($ageDays * 86400));

        return $path;
    }

    public function test_prune_deletes_files_older_than_retention(): void
    {
        $old = $this->writeFile('laravel-old.log', 'old', 10);
        $recent = $this->writeFile('laravel-recent.log', 'recent', 2);

        $result = $this->service->prune($this->dir, 7);

        $this->assertFalse(file_exists($old), 'file older than 7 days should be deleted');
        $this->assertTrue(file_exists($recent), 'file within 7 days should be kept');
        $this->assertSame(1, $result['deleted']);
    }

    public function test_prune_preserves_gitignore_even_when_old(): void
    {
        $gitignore = $this->writeFile('.gitignore', "*\n!.gitignore", 30);

        $result = $this->service->prune($this->dir, 7);

        $this->assertTrue(file_exists($gitignore), '.gitignore must never be pruned');
        $this->assertSame(0, $result['deleted']);
    }

    public function test_prune_recurses_into_subdirectories(): void
    {
        $old = $this->writeFile('cron/some_job.log', 'old', 10);

        $result = $this->service->prune($this->dir, 7);

        $this->assertFalse(file_exists($old), 'old file in subdirectory should be pruned');
        $this->assertSame(1, $result['deleted']);
    }

    public function test_prune_dry_run_reports_without_deleting(): void
    {
        $old = $this->writeFile('laravel-old.log', 'old', 10);

        $result = $this->service->prune($this->dir, 7, true);

        $this->assertTrue(file_exists($old), 'dry run must not delete');
        $this->assertSame(1, $result['deleted'], 'dry run still reports the would-be count');
    }

    public function test_compress_gzips_rotated_logs_and_removes_original(): void
    {
        $content = str_repeat("a log line that compresses well\n", 100);
        $rotated = $this->writeFile('laravel-2020-01-01.log', $content, 2);

        $result = $this->service->compress($this->dir);

        $this->assertFalse(file_exists($rotated), 'original removed after compression');
        $this->assertTrue(file_exists($rotated.'.gz'), 'gzip created');
        $this->assertSame($content, gzdecode(file_get_contents($rotated.'.gz')), 'gzip round-trips the original content');
        $this->assertSame(1, $result['compressed']);
    }

    public function test_compress_skips_files_modified_today(): void
    {
        $live = $this->writeFile('scheduler.log', 'live content', 0);

        $result = $this->service->compress($this->dir);

        $this->assertTrue(file_exists($live), 'a file written today (still live) must not be compressed');
        $this->assertFalse(file_exists($live.'.gz'));
        $this->assertSame(0, $result['compressed']);
    }

    public function test_compress_skips_already_compressed_files(): void
    {
        $gz = $this->writeFile('laravel-2020-01-01.log.gz', 'already gz', 5);

        $result = $this->service->compress($this->dir);

        $this->assertTrue(file_exists($gz));
        $this->assertSame(0, $result['compressed']);
    }

    public function test_compress_handles_supervisor_numbered_backups(): void
    {
        $backup = $this->writeFile('scheduler.log.1', 'supervisor backup', 3);

        $result = $this->service->compress($this->dir);

        $this->assertFalse(file_exists($backup), 'numbered .log.N backup should be compressed');
        $this->assertTrue(file_exists($backup.'.gz'));
        $this->assertSame(1, $result['compressed']);
    }

    public function test_compress_ignores_non_log_files(): void
    {
        $txt = $this->writeFile('mjml-diff-report.txt', 'report', 5);

        $result = $this->service->compress($this->dir);

        $this->assertTrue(file_exists($txt), 'non-.log files are not compressed');
        $this->assertFalse(file_exists($txt.'.gz'));
        $this->assertSame(0, $result['compressed']);
    }

    public function test_compress_dry_run_reports_without_writing(): void
    {
        $rotated = $this->writeFile('laravel-2020-01-01.log', 'data', 2);

        $result = $this->service->compress($this->dir, true);

        $this->assertTrue(file_exists($rotated), 'dry run keeps the original');
        $this->assertFalse(file_exists($rotated.'.gz'), 'dry run writes no gz');
        $this->assertSame(1, $result['compressed'], 'dry run still reports the would-be count');
    }

    public function test_prune_on_missing_directory_is_a_noop(): void
    {
        $result = $this->service->prune($this->dir.'/does-not-exist', 7);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(0, $result['bytes']);
    }

    public function test_compress_on_missing_directory_is_a_noop(): void
    {
        $result = $this->service->compress($this->dir.'/does-not-exist');

        $this->assertSame(0, $result['compressed']);
    }
}
