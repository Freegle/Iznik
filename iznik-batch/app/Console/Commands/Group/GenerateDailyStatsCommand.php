<?php

namespace App\Console\Commands\Group;

use App\Services\StatsGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateDailyStatsCommand extends Command
{
    protected $signature = 'stats:generate-daily
                            {--date= : Specific date (YYYY-MM-DD) to generate, default = yesterday}
                            {--from= : Start of inclusive backfill range (YYYY-MM-DD)}
                            {--to= : End of inclusive backfill range (YYYY-MM-DD), default = yesterday}
                            {--dry-run : Count what would be written without writing}';

    protected $description = 'Generate the daily per-group `stats` rows (mirrors V1 cron/group_stats.php Stats::generate())';

    public function handle(StatsGenerationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dates = $this->resolveDates();
        if ($dates === null) {
            $this->error('Provide either --date OR --from/--to (not both).');
            return Command::FAILURE;
        }

        $verb = $dryRun ? 'Would generate' : 'Generating';
        $this->info("{$verb} stats for " . count($dates) . ' date(s): ' . implode(', ', $dates));

        $grandTotalRows = 0;
        $grandTotalGroups = 0;
        foreach ($dates as $date) {
            $start = microtime(true);
            $result = $service->generateForAllGroups($date, $dryRun);
            $elapsed = round(microtime(true) - $start, 1);

            $grandTotalRows += $result['rows_written'];
            $grandTotalGroups = $result['groups'];

            $this->line(sprintf(
                '  %s: %d groups, %d rows %s (%.1fs)',
                $date,
                $result['groups'],
                $result['rows_written'],
                $dryRun ? 'would be written' : 'written',
                $elapsed,
            ));

            if (!$dryRun) {
                Log::info('stats:generate-daily', [
                    'date' => $date,
                    'groups' => $result['groups'],
                    'rows' => $result['rows_written'],
                    'elapsed' => $elapsed,
                ]);
            }
        }

        $this->info(sprintf(
            'Done. %d total rows %s across %d groups.',
            $grandTotalRows,
            $dryRun ? 'would be written' : 'written',
            $grandTotalGroups,
        ));

        return Command::SUCCESS;
    }

    /**
     * Returns ordered list of YYYY-MM-DD strings to process, or null on conflict.
     */
    private function resolveDates(): ?array
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($date && ($from || $to)) {
            return null;
        }

        if ($date) {
            return [CarbonImmutable::parse($date)->toDateString()];
        }

        if ($from) {
            $start = CarbonImmutable::parse($from)->startOfDay();
            $end = $to
                ? CarbonImmutable::parse($to)->startOfDay()
                : CarbonImmutable::yesterday();
            $dates = [];
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                $dates[] = $d->toDateString();
            }
            return $dates;
        }

        // Default: yesterday only — matches V1 group_stats.php.
        return [CarbonImmutable::yesterday()->toDateString()];
    }
}
