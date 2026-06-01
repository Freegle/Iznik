<?php

namespace App\Console\Commands\Mail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Re-drive mail SpoolMail jobs that have landed in failed_jobs.
 *
 * SpoolMail keeps retrying a failed render/send for 24h; only jobs still
 * failing after that window dead-letter to failed_jobs. The usual cause is a
 * render bug that needs a code fix. Once the fix is deployed, run this to
 * re-queue the parked emails so they go out — nothing was lost, just held.
 *
 * Scoped to SpoolMail jobs so it won't disturb any other failed jobs.
 */
class RetryFailedMailCommand extends Command
{
    protected $signature = 'mail:retry-failed
        {--limit=0 : Max jobs to re-drive (0 = all)}';

    protected $description = 'Re-queue mail SpoolMail jobs sitting in failed_jobs (e.g. after deploying a render-bug fix)';

    public function handle(): int
    {
        $query = DB::table('failed_jobs')
            ->where('payload', 'like', '%SpoolMail%')
            ->orderBy('id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $uuids = $query->pluck('uuid')->all();

        if (empty($uuids)) {
            $this->info('No failed SpoolMail jobs to retry.');

            return self::SUCCESS;
        }

        $this->info('Re-queueing ' . count($uuids) . ' failed SpoolMail job(s)...');

        // queue:retry moves the rows back onto the queue for the workers to
        // pick up; it accepts a list of failed-job uuids.
        Artisan::call('queue:retry', ['id' => $uuids]);
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }
}
