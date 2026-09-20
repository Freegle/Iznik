<?php

namespace App\Console\Commands\Backup;

use App\Console\BackupDrain;
use Illuminate\Console\Command;

/**
 * Tells the backup script whether batch work is actually being held off.
 *
 * Intended use, from the backup script before it desyncs the node:
 *
 *   php artisan backup:drain-status --wait=120 || echo "drain not in force" | mail ...
 *
 * Deliberately does NOT stop the backup when the answer is no. A backup that did not run
 * is worse than a backup that ran alongside some batch work, so the exit code is a signal
 * for the caller to log or alert on, not a gate to abort behind.
 */
class DrainStatusCommand extends Command
{
    protected $signature = 'backup:drain-status
        {--wait=0 : Seconds to wait for the drain to start before giving up}';

    protected $description = 'Report whether batch work is being held off for the backup (exit 0 if it is)';

    public function handle(): int
    {
        $wait = max(0, (int) $this->option('wait'));
        $deadline = time() + $wait;

        while (true) {
            if (BackupDrain::active()) {
                $endsAt = BackupDrain::endsAt();
                $this->info($endsAt
                    ? 'Batch work is held off until '.$endsAt->format('H:i').'.'
                    : 'Batch work is held off.');

                return self::SUCCESS;
            }

            if (time() >= $deadline) {
                break;
            }

            sleep((int) min(5, max(1, $deadline - time())));
        }

        $this->warn('Batch work is NOT being held off; the backup would run alongside it.');
        $this->line('Check BACKUP_DRAIN_ENABLED, BACKUP_DRAIN_START and BACKUP_DRAIN_MINUTES.');

        return self::FAILURE;
    }
}
