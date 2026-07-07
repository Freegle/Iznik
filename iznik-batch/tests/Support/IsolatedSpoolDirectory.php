<?php

namespace Tests\Support;

use App\Services\EmailSpoolerService;

/**
 * Trait for tests that need isolated spool directories.
 *
 * When running tests in parallel with ParaTest, tests that use the EmailSpoolerService
 * can interfere with each other if they share the same spool directory. This trait
 * provides an isolated spool directory for each test.
 *
 * Usage:
 *   use IsolatedSpoolDirectory;
 *
 *   protected function setUp(): void {
 *       parent::setUp();
 *       $this->setUpIsolatedSpoolDirectory();
 *   }
 *
 *   protected function tearDown(): void {
 *       $this->tearDownIsolatedSpoolDirectory();
 *       parent::tearDown();
 *   }
 */
trait IsolatedSpoolDirectory
{
    protected EmailSpoolerService $spooler;
    protected string $testSpoolDir;

    /**
     * Set up an isolated spool directory for this test.
     */
    protected function setUpIsolatedSpoolDirectory(): void
    {
        // Use a unique spool directory for each test.
        $this->testSpoolDir = storage_path('spool/mail-test-' . uniqid('', true));

        // Create a spooler with the test-specific directory.
        $this->spooler = new EmailSpoolerService();

        // Override the spool directory using reflection.
        $reflection = new \ReflectionClass($this->spooler);

        $spoolDirProperty = $reflection->getProperty('spoolDir');
        $spoolDirProperty->setAccessible(true);
        $spoolDirProperty->setValue($this->spooler, $this->testSpoolDir);

        $pendingDirProperty = $reflection->getProperty('pendingDir');
        $pendingDirProperty->setAccessible(true);
        $pendingDirProperty->setValue($this->spooler, $this->testSpoolDir . '/pending');

        $sendingDirProperty = $reflection->getProperty('sendingDir');
        $sendingDirProperty->setAccessible(true);
        $sendingDirProperty->setValue($this->spooler, $this->testSpoolDir . '/sending');

        $failedDirProperty = $reflection->getProperty('failedDir');
        $failedDirProperty->setAccessible(true);
        $failedDirProperty->setValue($this->spooler, $this->testSpoolDir . '/failed');

        $sentDirProperty = $reflection->getProperty('sentDir');
        $sentDirProperty->setAccessible(true);
        $sentDirProperty->setValue($this->spooler, $this->testSpoolDir . '/sent');

        // Create directories.
        $ensureMethod = $reflection->getMethod('ensureDirectoriesExist');
        $ensureMethod->setAccessible(true);
        $ensureMethod->invoke($this->spooler);

        // Bind as singleton so any code resolving from container uses our test instance.
        $this->app->instance(EmailSpoolerService::class, $this->spooler);
    }

    /**
     * Clean up the isolated spool directory.
     */
    protected function tearDownIsolatedSpoolDirectory(): void
    {
        if (isset($this->testSpoolDir) && is_dir($this->testSpoolDir)) {
            $this->recursiveDeleteSpoolDir($this->testSpoolDir);
        }
    }

    /**
     * Recursively delete a directory.
     */
    protected function recursiveDeleteSpoolDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDeleteSpoolDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
