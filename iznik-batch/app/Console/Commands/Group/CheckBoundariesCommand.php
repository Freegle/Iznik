<?php

namespace App\Console\Commands\Group;

use App\Services\GroupBoundaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckBoundariesCommand extends Command
{
    protected $signature = 'groups:check-boundaries
                            {--dry-run : Report how many groups would be checked without running checks}';

    protected $description = 'Validate spatial CGA/DPA boundary geometry for all Freegle groups';

    public function handle(GroupBoundaryService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no spatial checks will be executed.');
        }

        Log::info('Starting group boundary checks', ['dry_run' => $dryRun]);

        $stats = $service->checkBoundaries($dryRun);

        if ($dryRun) {
            $this->info("[DRY RUN] Would check boundaries for {$stats['total']} group(s).");
        } else {
            $this->info("Checked {$stats['total']} group(s), {$stats['errors']} error(s) detected.");
        }

        Log::info('Group boundary check complete', $stats);

        return Command::SUCCESS;
    }
}
