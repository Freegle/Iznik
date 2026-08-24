<?php

namespace Tests\Unit\Services;

use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * Regression test for the rename race in EmailSpoolerService::processSpool().
 *
 * processSpool() globs the pending directory then rename()s each file into
 * sending/. When a second processor (another mail:spool:process run or a
 * supervisor worker) claims a file first, rename() fails on a now-missing path.
 * The code intends to skip such files (`if (!rename(...)) { warn; continue; }`),
 * but an un-suppressed rename() warning is promoted to an ErrorException by
 * Laravel's handler, crashing the whole run. The fix suppresses the warning so
 * the graceful skip actually runs.
 */
class EmailSpoolerProcessSpoolRaceTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    public function test_process_spool_skips_unmovable_file_without_crashing(): void
    {
        $pending = $this->testSpoolDir.'/pending/mail_race.json';
        file_put_contents($pending, json_encode(['to' => [], 'subject' => 'race']));

        // Simulate the file being claimed concurrently: remove the sending
        // directory so rename() fails (the same warning the real race produces).
        $sending = $this->testSpoolDir.'/sending';
        array_map('unlink', glob($sending.'/*') ?: []);
        rmdir($sending);

        // Pre-fix this threw ErrorException; it must now skip gracefully.
        $stats = $this->spooler->processSpool();

        $this->assertIsArray($stats);
        $this->assertSame(0, $stats['processed']);
        // The unmovable file is left in pending for another processor to handle.
        $this->assertFileExists($pending);
    }
}
