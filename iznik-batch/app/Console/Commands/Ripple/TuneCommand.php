<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\RippleTuneService;
use Illuminate\Console\Command;

/**
 * §16 self-tuning loop (advisory). Rolls up the week's rippling metrics, flags geographically
 * unusual hotspots (robust median+MAD outliers, not just the overall average), and writes
 * PROPOSED per-category parameter changes for a human to accept. Read-only with respect to the
 * live engine - nothing it writes changes behaviour until a proposal is promoted to active.
 *
 * Like ripple:monitor, this is runnable now but left unscheduled pending the production rollout
 * decision (see plans/rippling-out-rollout/design.md §16).
 */
class TuneCommand extends Command
{
    protected $signature = 'ripple:tune
        {--start= : period start (YYYY-MM-DD); default 7 days before end}
        {--end= : period end (YYYY-MM-DD); default now}';

    protected $description = 'Roll up rippling metrics, flag geographic hotspots, and propose per-category parameter changes (advisory)';

    public function handle(RippleTuneService $service): int
    {
        $result = $service->tune($this->option('start'), $this->option('end'));

        $this->info(sprintf(
            'ripple:tune (%s): %d metric rows, %d hotspots, %d proposals',
            $result['period_start'],
            $result['metrics'],
            $result['hotspots'],
            $result['proposals']
        ));

        return self::SUCCESS;
    }
}
