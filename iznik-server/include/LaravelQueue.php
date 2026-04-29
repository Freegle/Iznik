<?php

namespace Freegle\Iznik;

class LaravelQueue
{
    /**
     * Inserts a task into background_tasks for async processing by iznik-batch (Laravel).
     * @see ../../iznik-server-go/queue/queue.go for the Go side of this queueing mechanism.
     * @return int|null The ID of the inserted task, or NULL on failure.
     */
    public static function queueTask(string $taskType, array $data): ?int
    {
        global $dbhm;

        $jsonData = json_encode($data);

        if ($jsonData === FALSE) {
            error_log("Failed to marshal task data for type {$taskType}: " . json_last_error_msg());
            return NULL;
        }

        try {
            $dbhm->preExec(
                "INSERT INTO background_tasks (task_type, data) VALUES (?, ?)",
                [$taskType, $jsonData]
            );

            return (int) $dbhm->lastInsertId();
        } catch (\Throwable $e) {
            error_log("Failed to queue task type {$taskType}: " . $e->getMessage());
            return NULL;
        }
    }

    /**
     * Wait for a queued background task to complete processing.
     *
     * Returns TRUE only when processed_at is set and failed_at is NULL.
     * Returns FALSE on timeout, failure, missing task, or query error.
     */
    public static function waitForTaskProcessed(int $taskId, int $timeoutSeconds = 60, int $pollIntervalMs = 1000): bool
    {
        global $dbhr;

        if ($timeoutSeconds < 0) {
            $timeoutSeconds = 0;
        }

        if ($pollIntervalMs < 1) {
            $pollIntervalMs = 1;
        }

        $deadline = microtime(TRUE) + $timeoutSeconds;

        do {
            try {
                $rows = $dbhr->preQuery(
                    "SELECT processed_at, failed_at FROM background_tasks WHERE id = ? LIMIT 1",
                    [$taskId]
                );
            } catch (\Throwable $e) {
                error_log("Failed waiting for task {$taskId}: " . $e->getMessage());
                return FALSE;
            }

            if (empty($rows)) {
                error_log("Failed waiting for task {$taskId}: task not found");
                return FALSE;
            }

            $task = $rows[0];

            if (!is_null($task['failed_at'])) {
                return FALSE;
            }

            if (!is_null($task['processed_at'])) {
                return TRUE;
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(TRUE) < $deadline);

        return FALSE;
    }
}
