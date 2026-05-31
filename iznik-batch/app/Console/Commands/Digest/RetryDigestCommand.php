<?php

namespace App\Console\Commands\Digest;

use App\Services\UnifiedDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drains the digest_retries queue: immediate-digest sends that failed to
 * build/render (e.g. a transient deploy-window template error) are queued
 * per-recipient by UnifiedDigestService and retried here with exponential
 * backoff, giving up (and logging) after RETRY_MAX_ATTEMPTS.
 *
 * Per-recipient retry means we never re-send to recipients who already
 * succeeded — no re-send storm — which is why the live cursor is allowed to
 * advance past a partially-failed message.
 */
class RetryDigestCommand extends Command
{
    protected $signature = 'mail:digest:retry
                            {--limit=500 : Maximum queued rows to process this run}
                            {--dry-run : Show what would be retried without sending}';

    protected $description = 'Retry immediate-digest sends that previously failed to build/render (digest_retries queue).';

    public function handle(UnifiedDigestService $service): int
    {
        if (! Schema::hasTable('digest_retries')) {
            $this->warn('digest_retries table does not exist; nothing to do.');

            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $due = DB::table('digest_retries')
            ->where('nextattempt', '<=', now())
            ->orderBy('nextattempt')
            ->limit($limit)
            ->get();

        $stats = ['sent' => 0, 'gone' => 0, 'own' => 0, 'noemail' => 0, 'failed' => 0, 'gaveup' => 0];

        foreach ($due as $row) {
            $error = null;
            try {
                $status = $service->resendImmediateForUser(
                    (int) $row->userid,
                    (int) $row->msgid,
                    (int) $row->groupid,
                    $dryRun
                );
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
            }

            if ($dryRun) {
                $stats[$status] = ($stats[$status] ?? 0) + 1;

                continue;
            }

            if ($status === 'failed') {
                $attempts = (int) $row->attempts + 1;

                if ($attempts >= UnifiedDigestService::RETRY_MAX_ATTEMPTS) {
                    // Out of attempts — surface it and stop retrying.
                    Log::error('Giving up on immediate digest retry after max attempts', [
                        'userid' => $row->userid,
                        'msgid' => $row->msgid,
                        'attempts' => $attempts,
                        'error' => $error ?? $row->lasterror,
                    ]);
                    DB::table('digest_retries')->where('id', $row->id)->delete();
                    $stats['gaveup']++;
                } else {
                    $delayMinutes = min(60, 2 ** $attempts);
                    DB::table('digest_retries')->where('id', $row->id)->update([
                        'attempts' => $attempts,
                        'lasterror' => mb_substr($error ?? 'unknown', 0, 255),
                        'nextattempt' => now()->addMinutes($delayMinutes),
                    ]);
                    $stats['failed']++;
                }
            } else {
                // sent / gone / own / noemail are all terminal — drop the row.
                DB::table('digest_retries')->where('id', $row->id)->delete();
                $stats[$status] = ($stats[$status] ?? 0) + 1;
            }
        }

        Log::info('Immediate digest retry run complete', $stats + ['dry_run' => $dryRun, 'due' => $due->count()]);
        $this->info('Retry run complete: '.json_encode($stats));

        return Command::SUCCESS;
    }
}
