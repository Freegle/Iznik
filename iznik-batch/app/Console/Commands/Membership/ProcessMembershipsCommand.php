<?php

namespace App\Console\Commands\Membership;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MembershipsProcessingService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMembershipsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'memberships:process
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process pending membership history entries: send per-group welcome emails, flag reviewed members';

    public function handle(MembershipsProcessingService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $count = DB::table('memberships_history')
                    ->where('processingrequired', 1)
                    ->count();
                $this->info("Dry run — {$count} entry/entries would be processed.");
                return Command::SUCCESS;
            }

            Log::info('Starting membership processing');

            $count = $service->processAll();

            $this->info("{$count} entry/entries processed");
            Log::info('Membership processing complete', ['count' => $count]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
