<?php

namespace App\Console\Commands\Jobs;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\JobIllustrationsService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateIllustrationsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'jobs:generate-illustrations
                            {--dry-run : Show what would be generated without making changes}';

    protected $description = 'Generate AI illustrations for canonical job categories';

    public function handle(JobIllustrationsService $service): int
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

            Log::info('Starting job illustrations generation');

            $result = $service->processIllustrations();

            $this->info("Job illustrations: {$result['processed']} processed, {$result['remaining']} remaining.");
            Log::info('Job illustrations complete', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
