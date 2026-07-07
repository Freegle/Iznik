<?php

namespace App\Console\Commands\Ripple;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\Ripple\ReplyAttributionBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill of the durable graded-attribution evidence onto legacy
 * rippling_reply_attribution rows (attribution IS NULL). Run once on production after applying
 * migration 2026_07_07_000002; safe to re-run (idempotent - only visits unfilled rows). See
 * ReplyAttributionBackfillService for what is (and deliberately is not) derivable.
 */
class BackfillReplyAttributionCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'ripple:backfill-reply-attribution
                            {--dry-run : Report how many rows would be backfilled without changing anything}
                            {--limit=0 : Max rows to process (0 = no limit)}
                            {--batch=500 : Rows per UPDATE statement}';

    protected $description = 'Backfill durable graded-attribution evidence (notified ledger, rippled-group membership, hard guard) onto legacy rippling reply-attribution rows';

    public function handle(ReplyAttributionBackfillService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $pending = $service->pendingCount();

            if ($this->option('dry-run')) {
                $this->info("[DRY RUN] {$pending} row(s) would be backfilled. Pass no --dry-run to apply.");

                return Command::SUCCESS;
            }

            $updated = $service->backfill(
                max(1, (int) $this->option('batch')),
                (int) $this->option('limit'),
                fn (int $total) => $this->output->write("\rBackfilled {$total} of {$pending}...")
            );
            $this->output->writeln('');
            $this->info("Backfilled {$updated} row(s); " . $service->pendingCount() . ' still pending.');
            Log::info('ripple:backfill-reply-attribution complete', [
                'updated' => $updated,
                'pending_before' => $pending,
            ]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
