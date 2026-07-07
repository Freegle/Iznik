<?php

namespace App\Traits;

use App\Services\LokiService;
use Throwable;

/**
 * Trait for batch commands to automatically log their execution to Loki.
 *
 * Usage:
 *   use App\Traits\LogsBatchJob;
 *
 *   class MyCommand extends Command
 *   {
 *       use LogsBatchJob;
 *
 *       public function handle(): int
 *       {
 *           return $this->runWithLogging(function () {
 *               // Your command logic here
 *               return Command::SUCCESS;
 *           });
 *       }
 *   }
 */
trait LogsBatchJob
{
    /**
     * Run the command with automatic Loki logging.
     *
     * Logs start, completion, and failure events with duration tracking.
     *
     * @param callable $callback The command logic to execute
     * @param array $context Additional context to include in logs
     * @return int Command exit code
     */
    protected function runWithLogging(callable $callback, array $context = []): int
    {
        $loki = app(LokiService::class);
        $jobName = $this->getJobName();
        $startTime = microtime(true);

        /* Log job start - check for output before accessing options/arguments (may not be set in unit tests) */
        $hasOutput = property_exists($this, 'output') && $this->output !== null;
        $loki->logBatchJob($jobName, 'started', array_merge([
            'signature' => $this->signature ?? null,
            'options' => $hasOutput ? $this->options() : [],
            'arguments' => $hasOutput ? $this->arguments() : [],
        ], $context));

        try {
            $result = $callback();
            $duration = round(microtime(true) - $startTime, 3);

            /* Log job completion */
            $loki->logBatchJob($jobName, 'completed', array_merge([
                'duration_seconds' => $duration,
                'exit_code' => $result,
            ], $context));

            return $result;
        } catch (Throwable $e) {
            $duration = round(microtime(true) - $startTime, 3);

            /* Log job failure */
            $loki->logBatchJob($jobName, 'failed', array_merge([
                'duration_seconds' => $duration,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ], $context));

            throw $e;
        }
    }

    /**
     * Get the job name for logging.
     *
     * Extracts the command name from the signature or falls back to class name.
     */
    protected function getJobName(): string
    {
        if (property_exists($this, 'signature') && $this->signature) {
            /* Extract command name from signature (before any arguments/options) */
            $signature = $this->signature;
            if (preg_match('/^([a-z0-9:_-]+)/i', trim($signature), $matches)) {
                return $matches[1];
            }
        }

        /* Fallback to class name */
        return class_basename(static::class);
    }
}
