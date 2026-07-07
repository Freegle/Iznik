<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CronJobStatusListener
{
    /**
     * Extract the artisan command name from a scheduled event's command string.
     *
     * The command string looks like: '/usr/bin/php' 'artisan' mail:chat:user2user --max-iterations=60 --spool
     * We want to extract: mail:chat:user2user --max-iterations=60 --spool
     */
    public static function extractCommand(string $raw): ?string
    {
        // Match everything after 'artisan' (with optional quotes around it).
        if (preg_match("/['\"]?artisan['\"]?\s+(.+)$/", $raw, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $command = self::extractCommand($event->task->command ?? '');

        if (!$command) {
            return;
        }

        try {
            DB::table('cron_job_status')->updateOrInsert(
                ['command' => $command],
                [
                    'last_run_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('CronJobStatus: failed to record start', ['command' => $command, 'error' => $e->getMessage()]);
        }
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $command = self::extractCommand($event->task->command ?? '');

        if (!$command) {
            return;
        }

        // Read output if it was captured to a file.
        $output = null;
        $outputPath = $event->task->output ?? '/dev/null';

        if ($outputPath !== '/dev/null' && file_exists($outputPath)) {
            $output = file_get_contents($outputPath);

            // Truncate to 64KB to avoid filling the DB.
            if (strlen($output) > 65536) {
                $output = substr($output, -65536);
            }
        }

        try {
            DB::table('cron_job_status')->updateOrInsert(
                ['command' => $command],
                [
                    'last_finished_at' => now(),
                    'last_exit_code' => $event->task->exitCode,
                    'last_output' => $output,
                    'updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('CronJobStatus: failed to record finish', ['command' => $command, 'error' => $e->getMessage()]);
        }
    }
}
