<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes housekeeping task results from the Chrome extension.
 *
 * Currently handles:
 * - facebook-deletion: looks up Facebook user IDs, puts matching Freegle users into limbo
 */
class HousekeeperService
{
    /**
     * Process a housekeeping notification.
     */
    public function process(array $data): void
    {
        $task = $data['task'] ?? NULL;
        $status = $data['status'] ?? NULL;
        $summary = $data['summary'] ?? '';
        $taskData = $data['data'] ?? [];

        Log::info("Housekeeper: processing {$task} ({$status}): {$summary}");

        $results = [];
        $logLines = [];

        if ($task === 'facebook-deletion' && $status === 'success') {
            $results = $this->processFacebookDeletion($taskData, $logLines);
        }

        // Build a one-line status summary from results.
        $generatedSummary = $this->generateSummary($task, $status, $summary, $results);

        // Build full log text.
        $logText = implode("\n", $logLines);

        // Record this task run with log and summary.
        if ($task) {
            DB::table('housekeeper_tasks')->updateOrInsert(
                ['task_key' => $task],
                [
                    'name' => $task,
                    'last_run_at' => now(),
                    'last_status' => $status,
                    'last_summary' => $generatedSummary,
                    'last_log' => $logText ?: null,
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Process Facebook deletion user IDs.
     *
     * For each Facebook user ID, find the corresponding Freegle user and put them
     * into limbo (14-day grace period). This mirrors the logic in
     * iznik-server/http/facebook/facebook_unsubscribe.php.
     */
    protected function processFacebookDeletion(array $taskData, array &$logLines): array
    {
        $ids = $taskData['ids'] ?? [];
        $results = [];

        $logLines[] = 'Processing ' . count($ids) . ' Facebook user ID(s)';

        foreach ($ids as $fbId) {
            $fbId = (string) $fbId;

            // Look up Freegle user by Facebook login.
            $userId = DB::table('users_logins')
                ->where('type', 'Facebook')
                ->where('uid', $fbId)
                ->value('userid');

            if (! $userId) {
                $results[] = [
                    'facebook_id' => $fbId,
                    'status' => 'not_found',
                ];
                $logLines[] = "FB {$fbId}: not found in Freegle";
                Log::info("Housekeeper: Facebook ID {$fbId} not found");
                continue;
            }

            // Check if already deleted.
            $deleted = DB::table('users')
                ->where('id', $userId)
                ->value('deleted');

            if ($deleted) {
                $results[] = [
                    'facebook_id' => $fbId,
                    'freegle_id' => $userId,
                    'status' => 'already_deleted',
                ];
                $logLines[] = "FB {$fbId} → user #{$userId}: already deleted";
                Log::info("Housekeeper: Facebook ID {$fbId} -> user {$userId} already deleted");
                continue;
            }

            // Put user into limbo (14-day grace period).
            DB::table('users')
                ->where('id', $userId)
                ->update(['deleted' => now()]);

            $results[] = [
                'facebook_id' => $fbId,
                'freegle_id' => $userId,
                'status' => 'limbo',
            ];

            $logLines[] = "FB {$fbId} → user #{$userId}: marked for deletion (14-day limbo)";
            Log::info("Housekeeper: Facebook ID {$fbId} -> user {$userId} marked for deletion (limbo)");
        }

        return $results;
    }

    /**
     * Generate a one-line status summary for display in the dashboard.
     */
    protected function generateSummary(string $task, string $status, string $extensionSummary, array $results): string
    {
        if ($status !== 'success') {
            return "Failed: {$extensionSummary}";
        }

        if ($task === 'facebook-deletion') {
            $total = count($results);
            $limbo = count(array_filter($results, fn($r) => $r['status'] === 'limbo'));
            $notFound = count(array_filter($results, fn($r) => $r['status'] === 'not_found'));
            $alreadyDeleted = count(array_filter($results, fn($r) => $r['status'] === 'already_deleted'));

            $parts = [];
            if ($limbo > 0) $parts[] = "{$limbo} marked for deletion";
            if ($notFound > 0) $parts[] = "{$notFound} not found";
            if ($alreadyDeleted > 0) $parts[] = "{$alreadyDeleted} already deleted";

            return "Processed {$total} IDs: " . ($parts ? implode(', ', $parts) : 'no action needed');
        }

        return $extensionSummary;
    }
}
