<?php

namespace App\Console\Commands\Mail;

use App\Services\EmailSpoolerService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;

class ProcessSpoolCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'mail:spool:process
                            {--limit=100 : Maximum emails to process per run}
                            {--cleanup : Clean up old sent emails}
                            {--cleanup-days=7 : Days to keep sent emails when cleaning up}
                            {--retry-failed : Retry all failed emails (invalid format only)}
                            {--stats : Show backlog statistics only}
                            {--daemon : Run continuously with 1 second sleep between batches}';

    protected $description = 'Process spooled emails from the file-based queue';

    public function handle(EmailSpoolerService $spooler): int
    {
        if ($this->option('stats')) {
            return $this->showStats($spooler);
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($spooler);
        }

        if ($this->option('retry-failed')) {
            return $this->retryFailed($spooler);
        }

        if ($this->option('daemon')) {
            return $this->runDaemon($spooler);
        }

        return $this->processBatch($spooler);
    }

    protected function processBatch(EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Processing email spool (limit: {$limit})...");

        $stats = $spooler->processSpool($limit);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Sent', $stats['sent']],
                ['Retried', $stats['retried']],
                ['Stuck Alerts', $stats['stuck_alerts']],
            ]
        );

        if ($stats['stuck_alerts'] > 0) {
            $this->error('SMTP delivery issues detected - check logs for details.');
        }

        return Command::SUCCESS;
    }

    protected function runDaemon(EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option('limit');
        $cleanupDays = (int) $this->option('cleanup-days');
        $lastCleanup = 0;

        $this->registerShutdownHandlers();
        $this->info('Running in daemon mode. Press Ctrl+C to stop.');

        // Reclaim any files orphaned in sending/ by a previous process that
        // died mid-send (supervisor restart / OOM / container restart). The
        // spooler is single-process (numprocs=1) so nothing is in flight at
        // startup — this is race-free and lossless. Only done on the daemon
        // path, never the one-shot path (which could race a running daemon).
        $reclaimed = $spooler->reclaimOrphanedSending();
        if ($reclaimed > 0) {
            $this->line(sprintf(
                '[%s] Reclaimed %d orphaned spool file(s) from sending/ on startup',
                now()->toTimeString(),
                $reclaimed
            ));
        }

        while (! $this->shouldStop()) {
            $stats = $spooler->processSpool($limit);

            if ($stats['processed'] > 0) {
                $this->line(sprintf(
                    '[%s] Processed: %d, Sent: %d, Retried: %d',
                    now()->toTimeString(),
                    $stats['processed'],
                    $stats['sent'],
                    $stats['retried']
                ));

                if ($stats['stuck_alerts'] > 0) {
                    $this->error(sprintf(
                        '[%s] ALERT: %d emails stuck for 5+ minutes - SMTP issue!',
                        now()->toTimeString(),
                        $stats['stuck_alerts']
                    ));
                }
            }

            // Run sent directory cleanup every hour within the daemon.
            // This ensures cleanup happens even if the scheduled command fails.
            $now = time();
            if ($now - $lastCleanup >= 3600) {
                $deleted = $spooler->cleanupSent($cleanupDays);
                if ($deleted > 0) {
                    $this->line(sprintf(
                        '[%s] Cleanup: deleted %d sent emails older than %d days',
                        now()->toTimeString(),
                        $deleted,
                        $cleanupDays
                    ));
                }
                $lastCleanup = $now;
            }

            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->info('Shutting down gracefully...');

        return Command::SUCCESS;
    }

    protected function showStats(EmailSpoolerService $spooler): int
    {
        $stats = $spooler->getBacklogStats();

        $this->info('Email Spool Statistics');
        $this->newLine();

        $statusColor = match ($stats['status']) {
            'healthy' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            default => 'white',
        };

        $statusValue = $stats['status'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Pending', $stats['pending_count']],
                ['Sending', $stats['sending_count']],
                ['Failed', $stats['failed_count']],
                ['Oldest Pending', $stats['oldest_pending_at'] ?? 'N/A'],
                ['Oldest Age (minutes)', $stats['oldest_pending_age_minutes'] ?? 'N/A'],
                ['Status', "<fg={$statusColor}>{$statusValue}</>"],
            ]
        );

        return Command::SUCCESS;
    }

    protected function cleanup(EmailSpoolerService $spooler): int
    {
        $days = (int) $this->option('cleanup-days');

        $this->info("Cleaning up sent emails older than {$days} days...");

        $deleted = $spooler->cleanupSent($days);

        $this->info("Deleted {$deleted} old sent emails.");

        return Command::SUCCESS;
    }

    protected function retryFailed(EmailSpoolerService $spooler): int
    {
        $this->info('Retrying all failed emails...');

        $count = $spooler->retryAllFailed();

        $this->info("Moved {$count} emails back to pending queue.");

        return Command::SUCCESS;
    }
}
