<?php

namespace Freegle\Iznik;

class LaravelQueue
{
    /**
     * Inserts a task into background_tasks for async processing by iznik-batch (Laravel).
     * @see ../../iznik-server-go/queue/queue.go for the Go side of this queueing mechanism.
     */
    public static function queueTask(string $taskType, array $data): bool
    {
        global $dbhm;

        $jsonData = json_encode($data);

        if ($jsonData === FALSE) {
            error_log("Failed to marshal task data for type {$taskType}: " . json_last_error_msg());
            return FALSE;
        }

        try {
            $dbhm->preExec(
                "INSERT INTO background_tasks (task_type, data) VALUES (?, ?)",
                [$taskType, $jsonData]
            );
        } catch (\Throwable $e) {
            error_log("Failed to queue task type {$taskType}: " . $e->getMessage());
            return FALSE;
        }

        return TRUE;
    }
}
