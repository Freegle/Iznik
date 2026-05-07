<?php

namespace App\Console\Commands\Jobs;

use App\Services\JobIllustrationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateIllustrationsCommand extends Command
{
    protected $signature = 'jobs:generate-illustrations';

    protected $description = 'Generate AI illustrations for canonical job categories';

    public function handle(JobIllustrationsService $service): int
    {
        Log::info('Starting job illustrations generation');

        $result = $service->processIllustrations();

        $this->info("Job illustrations: {$result['processed']} processed, {$result['remaining']} remaining.");
        Log::info('Job illustrations complete', $result);

        return Command::SUCCESS;
    }
}
