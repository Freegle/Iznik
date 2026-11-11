<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hourly: read every running UX experiment from Loki by arm, record the read in plain English,
 * move traffic towards the arm that is winning, and decide when the time is up or the answer is
 * already clear. Also lists experiments whose code cleanup is due (adopted ones after a week,
 * reverted, inconclusive and stopped ones at once), so a forgotten switch is seen within a day. docs/developers/reference/ux-experiments.md.
 */
#[AsCommand(name: 'ux:experiments-tick')]
class ExperimentsTickCommand extends Command
{
    protected $signature = 'ux:experiments-tick';

    protected $description = 'Read, re-weight and decide the running UX experiments';

    public function handle(UxExperimentService $service): int
    {
        $now = Carbon::now('UTC');
        $lines = $service->tick($now);
        if (! $lines) {
            $this->info('No running experiments.');
        }
        foreach ($lines as $line) {
            $this->info($line);
        }

        foreach ($service->cleanupDue($now) as $exp) {
            $on = Carbon::parse($exp->decided_at)->toDateString();
            if ($exp->status === UxExperimentService::STATUS_ADOPTED) {
                $this->warn("Cleanup overdue: {$exp->name} was adopted on $on and its switch is still in the code.");
            } else {
                $this->warn("Cleanup due: {$exp->name} was {$exp->status} on $on and its code is still in the codebase."
                    ." Remove it and run ux:experiment close {$exp->name} --cleanup-pr=<url>.");
            }
        }

        return self::SUCCESS;
    }
}
