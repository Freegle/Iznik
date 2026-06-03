<?php

namespace App\Console\Commands\Stats;

use App\Services\StatsGenerationService;
use Illuminate\Console\Command;

/**
 * Fast Weight-only stats regeneration over a date range. Used after the
 * messages_items backfill to recover the Weight figures (and, by
 * implication, the on-the-fly CO2 / reuse-value calculations that read
 * from them) without re-running the full 17-type per-group aggregation
 * the existing `messages:backfill-items --stats` pipeline runs.
 *
 * Disjoint date ranges across workers are safe — the stats table's
 * (date, groupid, type) is the natural key and the service uses REPLACE
 * semantics, so two workers covering the same date would be idempotent.
 */
class RegenerateWeightsCommand extends Command
{
    protected $signature = 'stats:regenerate-weights
                            {--from= : Start date YYYY-MM-DD (inclusive)}
                            {--to= : End date YYYY-MM-DD (inclusive, default = today)}
                            {--dry-run : Show what would change without writing}';

    protected $description = 'Fast Weight-only stats regeneration for a date range (batched, all-groups-per-date)';

    public function handle(StatsGenerationService $stats): int
    {
        $from = $this->option('from');
        if (! $from) {
            $this->error('--from required (YYYY-MM-DD).');

            return Command::FAILURE;
        }
        $to = $this->option('to') ?: date('Y-m-d');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s Weight stats for %s .. %s',
            $dryRun ? 'Would regenerate' : 'Regenerating',
            $from,
            $to,
        ));

        $started = microtime(true);
        $result = $stats->regenerateWeightForRange($from, $to, $dryRun);
        $elapsed = microtime(true) - $started;

        $this->info(sprintf(
            'Done: %d dates processed, %d rows %s in %.1fs',
            $result['datesProcessed'],
            $result['rowsWritten'],
            $dryRun ? 'would be written' : 'written',
            $elapsed,
        ));

        return Command::SUCCESS;
    }
}
