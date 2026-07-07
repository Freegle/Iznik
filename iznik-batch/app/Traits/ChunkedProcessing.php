<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

trait ChunkedProcessing
{
    protected int $chunkSize = 1000;
    protected int $processedCount = 0;
    protected int $logInterval = 1000;

    /**
     * Process records in chunks to avoid memory issues and table locks.
     */
    protected function processInChunks(Builder $query, callable $processor): int
    {
        $this->processedCount = 0;

        $query->chunkById($this->chunkSize, function ($items) use ($processor) {
            foreach ($items as $item) {
                $processor($item);
                $this->processedCount++;

                if ($this->processedCount % $this->logInterval === 0) {
                    $this->logProgress("Processed {$this->processedCount} items...");
                }
            }
        });

        return $this->processedCount;
    }

    /**
     * Process records in chunks, deleting each after processing.
     */
    protected function processAndDelete(Builder $query, callable $processor): int
    {
        $this->processedCount = 0;

        do {
            $items = $query->limit($this->chunkSize)->get();

            foreach ($items as $item) {
                $processor($item);
                $item->delete();
                $this->processedCount++;

                if ($this->processedCount % $this->logInterval === 0) {
                    $this->logProgress("Processed and deleted {$this->processedCount} items...");
                }
            }
        } while ($items->count() > 0);

        return $this->processedCount;
    }

    /**
     * Log progress message.
     */
    protected function logProgress(string $message): void
    {
        if (method_exists($this, 'info')) {
            $this->info($message);
        } else {
            Log::info($message);
        }
    }

    /**
     * Set the chunk size.
     */
    protected function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Set the log interval.
     */
    protected function setLogInterval(int $interval): self
    {
        $this->logInterval = $interval;
        return $this;
    }

    /**
     * Retry a database operation on deadlock.
     *
     * MySQL deadlocks (error 1213) can occur during parallel test execution.
     * This method retries the operation with exponential backoff.
     */
    protected function retryOnDeadlock(callable $operation, int $maxAttempts = 3): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxAttempts) {
            try {
                return $operation();
            } catch (QueryException $e) {
                $lastException = $e;
                // Check for MySQL deadlock error (1213)
                if ($e->getCode() == 40001 || str_contains($e->getMessage(), 'Deadlock')) {
                    $attempts++;
                    if ($attempts < $maxAttempts) {
                        // Exponential backoff: 100ms, 200ms, 400ms...
                        usleep(100000 * pow(2, $attempts - 1));
                        Log::debug("Deadlock detected, retry attempt {$attempts}/{$maxAttempts}");
                        continue;
                    }
                }
                throw $e;
            }
        }

        throw $lastException;
    }
}
