<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hourly: read every running UX experiment from Loki by arm, record the read in plain English,
 * move traffic towards the arm that is winning, and decide when the time is up or the answer is
 * already clear. Also lists adopted experiments whose code cleanup is overdue, so a forgotten
 * switch is seen within a day. docs/developers/reference/ux-experiments.md.
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

        $days = (int) UxExperimentService::settings()['cleanup_days'];
        $overdue = DB::table('ux_experiments')
            ->where('status', UxExperimentService::STATUS_ADOPTED)
            ->where('decided_at', '<', $now->copy()->subDays($days))
            ->orderBy('decided_at')
            ->get();
        foreach ($overdue as $exp) {
            $this->warn("Cleanup overdue: {$exp->name} was adopted on ".Carbon::parse($exp->decided_at)->toDateString()
                .' and its switch is still in the code.');
        }

        return self::SUCCESS;
    }
}
