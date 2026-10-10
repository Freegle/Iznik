<?php

namespace App\Console\Commands\Message;

use App\Services\Automod\AutomodService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutomodCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:automod';

    protected $description = 'Run the automod flowchart over Pending posts in shadow/approve-trial groups';

    public function handle(AutomodService $service): int
    {
        $this->registerShutdownHandlers();

        Log::info('Starting automod processing');
        $this->info('Reviewing content-check-clean pending messages against the automod chart...');

        $stats = $service->process();

        $this->info(
            "Reviewed: {$stats['reviewed']}, Unavailable: {$stats['unavailable']}, Errors: {$stats['errors']}"
        );

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Automod processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
