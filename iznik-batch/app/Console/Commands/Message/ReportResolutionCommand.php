<?php

namespace App\Console\Commands\Message;

use App\Services\ReportResolutionService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReportResolutionCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'reports:resolve';

    protected $description = 'Take down posts whose member reports have reached quorum, and tell everyone involved';

    public function handle(ReportResolutionService $service): int
    {
        $this->registerShutdownHandlers();

        Log::info('ReportResolution: starting run');

        $resolved = $service->resolvePending();

        if (empty($resolved)) {
            $this->info('No reports reached quorum.');
        } else {
            $this->info('Resolved: '.implode(', ', $resolved));
        }

        Log::info('ReportResolution: run complete', ['resolved' => $resolved]);

        return Command::SUCCESS;
    }
}
