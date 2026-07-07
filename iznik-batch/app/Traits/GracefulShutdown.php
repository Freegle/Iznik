<?php

namespace App\Traits;

/**
 * Trait for graceful shutdown of long-running commands.
 *
 * Registers signal handlers for SIGTERM/SIGINT that set a flag.
 * The command's loop should check shouldStop() at safe points
 * (between database operations, etc.) and exit gracefully.
 *
 * This works with supervisor, systemd, docker stop, and Ctrl+C -
 * all of which send SIGTERM first.
 *
 * Also supports file-based abort mechanism for stopping commands
 * from external processes (e.g., deploy:refresh).
 */
trait GracefulShutdown
{
    protected bool $shouldStop = FALSE;

    protected ?string $abortFilePath = NULL;

    /**
     * Register signal handlers for graceful shutdown.
     * Call this at the start of your daemon loop.
     */
    protected function registerShutdownHandlers(): void
    {
        // Skip in testing to avoid PHPUnit issues.
        if ($this->isTestingEnvironment()) {
            return;
        }

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(TRUE);
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = TRUE);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = TRUE);
        }
    }

    /**
     * Alias for registerShutdownHandlers.
     */
    protected function setupSignalHandlers(): void
    {
        $this->registerShutdownHandlers();
    }

    /**
     * Check if we should stop processing.
     * Call this at safe points in your loop.
     * Checks both the signal flag and abort file existence.
     */
    protected function shouldStop(): bool
    {
        if ($this->shouldStop) {
            return TRUE;
        }

        // Check for abort file if configured.
        if ($this->abortFilePath && file_exists($this->abortFilePath)) {
            $this->shouldStop = TRUE;

            return TRUE;
        }

        return FALSE;
    }

    /**
     * Alias for shouldStop.
     */
    protected function shouldAbort(): bool
    {
        return $this->shouldStop();
    }

    /**
     * Set the abort file path for this command.
     *
     * @param  string  $scriptName  Script name used in the abort file path
     */
    protected function setAbortFile(string $scriptName): self
    {
        $this->abortFilePath = "/tmp/iznik.{$scriptName}.abort";

        return $this;
    }

    /**
     * Create the abort file to signal this command should stop.
     */
    protected function createAbortFile(): void
    {
        if ($this->abortFilePath) {
            touch($this->abortFilePath);
        }
    }

    /**
     * Remove the abort file after shutdown is complete.
     */
    protected function removeAbortFile(): void
    {
        if ($this->abortFilePath && file_exists($this->abortFilePath)) {
            unlink($this->abortFilePath);
        }
    }

    /**
     * Log shutdown message if stopping and return whether stopping.
     * Useful for loop exit points.
     *
     * @return bool Whether the command is stopping
     */
    protected function logShutdownIfStopping(): bool
    {
        if ($this->shouldStop()) {
            if (method_exists($this, 'info')) {
                $this->info('Shutdown signal received, stopping gracefully...');
            }

            return TRUE;
        }

        return FALSE;
    }

    protected function isTestingEnvironment(): bool
    {
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            return TRUE;
        }

        return in_array(
            'testing',
            [getenv('APP_ENV'), $_ENV['APP_ENV'] ?? NULL, $_SERVER['APP_ENV'] ?? NULL],
            TRUE
        );
    }
}
