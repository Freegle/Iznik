<?php

namespace App\Console\Commands\Message;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\MessageRemapSubjectsService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemapSubjectsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'messages:remap-subjects
                            {--dry-run : Show what would be remapped without making changes}';

    protected $description = 'Update message subjects when associated location names have changed';

    public function handle(MessageRemapSubjectsService $service): int
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

            Log::info('Starting message subject remap');

            $result = $service->remapSubjects();

            $this->info("Remapped {$result['changed']} message subjects.");
            Log::info('Message subject remap complete', $result);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
