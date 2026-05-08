<?php

namespace App\Console\Commands\AI;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MicroVolunteeringScoreService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MicroVolunteeringScoreCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'microvolunteering:score
                            {--dry-run : Show what would be scored without making changes}';

    protected $description = 'Score microvolunteering actions and promote accurate users';

    public function handle(MicroVolunteeringScoreService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            if ($this->option('dry-run')) {
                $this->info('Dry run — no changes made.');
                return Command::SUCCESS;
            }

            Log::info('Starting microvolunteering score and promote');

            $result = $service->scoreAndPromote();

            $this->info("Promoted {$result['promoted']} users to Moderate trust level.");
            Log::info('Microvolunteering score complete', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
