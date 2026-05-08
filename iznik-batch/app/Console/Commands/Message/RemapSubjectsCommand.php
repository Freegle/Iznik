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
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting changes but not writing.');
            } else {
                Log::info('Starting message subject remap');
            }

            $result = $service->remapSubjects($dryRun);

            $verb = $dryRun ? 'Would remap' : 'Remapped';
            $this->info("{$verb} {$result['changed']} of {$result['checked']} message subjects.");

            if (!empty($result['samples'])) {
                $this->line('Sample changes (up to 20):');
                foreach ($result['samples'] as $c) {
                    $this->line(sprintf('  #%d', $c['id']));
                    $this->line(sprintf('    OLD: %s', $c['old']));
                    $this->line(sprintf('    NEW: %s', $c['new']));
                }
            }

            if (!$dryRun) {
                Log::info('Message subject remap complete', ['changed' => $result['changed'], 'checked' => $result['checked']]);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
