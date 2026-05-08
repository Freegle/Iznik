<?php

namespace App\Console\Commands\Message;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MessageIllustrationsService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateIllustrationsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'messages:generate-illustrations
                            {--dry-run : Show what would be generated without making changes}';

    protected $description = 'Generate AI illustrations for messages that have no photos';

    public function handle(MessageIllustrationsService $service): int
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

            Log::info('Starting message illustrations generation');

            $result = $service->processIllustrations();

            $this->info("Illustrations: {$result['processed']} processed, {$result['cleaned']} cleaned up.");
            Log::info('Message illustrations complete', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
