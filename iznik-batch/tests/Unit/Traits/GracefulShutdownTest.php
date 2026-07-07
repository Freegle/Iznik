<?php

namespace Tests\Unit\Traits;

use App\Traits\GracefulShutdown;
use PHPUnit\Framework\TestCase;

class GracefulShutdownTest extends TestCase
{
    private object $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = new class {
            use GracefulShutdown;

            public bool $infoLogged = false;

            public function info(string $message): void
            {
                $this->infoLogged = true;
            }

            public function testSetupSignalHandlers(): void
            {
                $this->setupSignalHandlers();
            }

            public function testRegisterShutdownHandlers(): void
            {
                $this->registerShutdownHandlers();
            }

            public function testShouldStop(): bool
            {
                return $this->shouldStop();
            }

            public function testShouldAbort(): bool
            {
                return $this->shouldAbort();
            }

            public function testSetAbortFile(string $scriptName): self
            {
                return $this->setAbortFile($scriptName);
            }

            public function testCreateAbortFile(): void
            {
                $this->createAbortFile();
            }

            public function testRemoveAbortFile(): void
            {
                $this->removeAbortFile();
            }

            public function testLogShutdownIfStopping(): bool
            {
                return $this->logShutdownIfStopping();
            }

            public function setShouldStopFlag(bool $value): void
            {
                $this->shouldStop = $value;
            }

            public function getAbortFilePath(): ?string
            {
                return $this->abortFilePath;
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up any abort files created during tests.
        $abortPath = $this->instance->getAbortFilePath();
        if ($abortPath && file_exists($abortPath)) {
            unlink($abortPath);
        }

        parent::tearDown();
    }

    public function test_setup_signal_handlers_can_be_called(): void
    {
        // This just tests that the method doesn't throw an exception.
        $this->instance->testSetupSignalHandlers();
        $this->assertTrue(true);
    }

    public function test_register_shutdown_handlers_can_be_called(): void
    {
        // This just tests that the method doesn't throw an exception.
        $this->instance->testRegisterShutdownHandlers();
        $this->assertTrue(true);
    }

    public function test_should_stop_returns_false_initially(): void
    {
        $this->assertFalse($this->instance->testShouldStop());
    }

    public function test_should_stop_returns_true_when_flag_set(): void
    {
        $this->instance->setShouldStopFlag(true);

        $this->assertTrue($this->instance->testShouldStop());
    }

    public function test_should_abort_is_alias_for_should_stop(): void
    {
        $this->assertFalse($this->instance->testShouldAbort());

        $this->instance->setShouldStopFlag(true);

        $this->assertTrue($this->instance->testShouldAbort());
    }

    public function test_set_abort_file_sets_path(): void
    {
        $result = $this->instance->testSetAbortFile('test-script');

        $this->assertSame($this->instance, $result);
        $this->assertEquals('/tmp/iznik.test-script.abort', $this->instance->getAbortFilePath());
    }

    public function test_create_abort_file_creates_file(): void
    {
        $this->instance->testSetAbortFile('test-create');
        $this->instance->testCreateAbortFile();

        $this->assertFileExists('/tmp/iznik.test-create.abort');
    }

    public function test_create_abort_file_does_nothing_without_path(): void
    {
        // Without setting abort file first, this should do nothing.
        $this->instance->testCreateAbortFile();
        $this->assertTrue(true);
    }

    public function test_remove_abort_file_removes_file(): void
    {
        $this->instance->testSetAbortFile('test-remove');
        $this->instance->testCreateAbortFile();

        $this->assertFileExists('/tmp/iznik.test-remove.abort');

        $this->instance->testRemoveAbortFile();

        $this->assertFileDoesNotExist('/tmp/iznik.test-remove.abort');
    }

    public function test_remove_abort_file_does_nothing_without_file(): void
    {
        $this->instance->testSetAbortFile('nonexistent');
        // File doesn't exist, but this shouldn't throw.
        $this->instance->testRemoveAbortFile();
        $this->assertTrue(true);
    }

    public function test_remove_abort_file_does_nothing_without_path(): void
    {
        // Without setting abort file first, this should do nothing.
        $this->instance->testRemoveAbortFile();
        $this->assertTrue(true);
    }

    public function test_should_stop_detects_abort_file(): void
    {
        $this->instance->testSetAbortFile('test-detect');

        $this->assertFalse($this->instance->testShouldStop());

        // Create the abort file.
        touch('/tmp/iznik.test-detect.abort');

        $this->assertTrue($this->instance->testShouldStop());
    }

    public function test_log_shutdown_if_stopping_returns_false_when_not_stopping(): void
    {
        $this->assertFalse($this->instance->testLogShutdownIfStopping());
        $this->assertFalse($this->instance->infoLogged);
    }

    public function test_log_shutdown_if_stopping_returns_true_and_logs_when_stopping(): void
    {
        $this->instance->setShouldStopFlag(true);

        $this->assertTrue($this->instance->testLogShutdownIfStopping());
        $this->assertTrue($this->instance->infoLogged);
    }
}
