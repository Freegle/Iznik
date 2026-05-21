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

    /**
     * Wait for tn:sync completion data to be written into background_tasks.data JSON.
     *
     * Returns decoded data array when tn_sync_finished, tn_sync_status,
     * tn_sync_finished_at, and tn_sync_exit_code are present.
     * Returns NULL on timeout, failure, missing task, run_id mismatch, or query error.
     */
    public static function waitForTNSyncCompletionData(
        int $taskId,
        string $runId,
        int $timeoutSeconds = 60,
        int $pollIntervalMs = 1000
    ): ?array {
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
                    "SELECT failed_at, data FROM background_tasks WHERE id = ? LIMIT 1",
                    [$taskId]
                );
            } catch (\Throwable $e) {
                error_log("Failed waiting for tn_sync completion data for task {$taskId}: " . $e->getMessage());
                return NULL;
            }

            if (empty($rows)) {
                error_log("Failed waiting for tn_sync completion data for task {$taskId}: task not found");
                return NULL;
            }

            $task = $rows[0];

            if (!is_null($task['failed_at'])) {
                return NULL;
            }

            $data = json_decode((string) ($task['data'] ?? ''), TRUE);

            if (!is_array($data)) {
                usleep($pollIntervalMs * 1000);
                continue;
            }

            if (($data['run_id'] ?? NULL) !== $runId) {
                error_log("Failed waiting for tn_sync completion data for task {$taskId}: run_id mismatch");
                return NULL;
            }

            $hasCompletionData =
                ($data['tn_sync_finished'] ?? FALSE) === TRUE
                && isset($data['tn_sync_status'])
                && isset($data['tn_sync_finished_at'])
                && array_key_exists('tn_sync_exit_code', $data);

            if ($hasCompletionData) {
                return $data;
            }

            usleep($pollIntervalMs * 1000);
        } while (microtime(TRUE) < $deadline);

        return NULL;
    }
}
