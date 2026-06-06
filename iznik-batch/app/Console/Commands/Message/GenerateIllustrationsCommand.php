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
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting work but not calling pollinations.ai or writing.');
            } else {
                Log::info('Starting message illustrations generation');
            }

            $result = $service->processIllustrations($dryRun);

            if ($dryRun) {
                $this->info("Illustrations: would clean {$result['cleaned']}, would remove non-active {$result['cleaned_non_active']}, cache-hits {$result['cached_hits']}, would-fetch {$result['would_fetch']} (skipped — costs \$).");
            } else {
                $this->info("Illustrations: {$result['processed']} processed, {$result['cleaned']} cleaned up, {$result['cleaned_non_active']} non-active removed.");
                Log::info('Message illustrations complete', $result);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
