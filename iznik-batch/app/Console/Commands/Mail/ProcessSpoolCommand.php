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
                            {--daemon : Run continuously with 1 second sleep between batches}
                            {--worker= : Worker id, giving this daemon a private claim area so several can run at once}';

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

        // Claim into a private area when we have been given a worker id, so
        // several daemons can run without reclaiming each other's in-flight
        // mail. MUST happen before the reclaim below, or a restarting worker's
        // very first act is a sweep of the shared dir - the duplicate-send
        // hazard this exists to prevent.
        $worker = $this->option('worker');
        $spooler->setWorkerId($worker);

        // Reclaim files orphaned in sending/ by a previous process that died
        // mid-send (supervisor restart / OOM / container restart). Takes this
        // worker's OWN area unconditionally - only our dead predecessor can
        // have left anything there - plus an age-gated sweep of sibling areas
        // for workers that died and never came back. Only done on the daemon
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

            // Hourly housekeeping. Gated to a SINGLE worker: sent/ is large
            // enough that merely counting it can take minutes, and N daemons
            // each walking it hourly multiplies that IO against the very
            // filesystem the sends are reading from. The scheduled
            // `mail:spool:process --cleanup` covers it independently anyway.
            $now = time();
            $housekeeper = $worker === null || $worker === '' || $worker === '00';
            if ($housekeeper && $now - $lastCleanup >= 3600) {
                // Re-run the age-gated sibling sweep here as well as at startup,
                // so a file stranded by a worker that never returns self-heals
                // without waiting for a restart.
                $swept = $spooler->reclaimStaleSiblingSending();
                if ($swept > 0) {
                    $this->line(sprintf(
                        '[%s] Reclaimed %d stale spool file(s) from other worker areas',
                        now()->toTimeString(),
                        $swept
                    ));
                }

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
