<?php

namespace App\Console\Commands\AI;

use App\Services\MicroVolunteeringScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MicroVolunteeringScoreCommand extends Command
{
    protected $signature = 'microvolunteering:score';

    protected $description = 'Score microvolunteering actions and promote accurate users';

    public function handle(MicroVolunteeringScoreService $service): int
    {
        Log::info('Starting microvolunteering score and promote');

        $result = $service->scoreAndPromote();

        $this->info("Promoted {$result['promoted']} users to Moderate trust level.");
        Log::info('Microvolunteering score complete', $result);

        return Command::SUCCESS;
    }
}
