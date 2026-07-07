<?php

namespace App\Console\Commands\Message;

use App\Services\VisualiseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateVisualiseCommand extends Command
{
    protected $signature = 'messages:update-visualise
                            {--ago=30 minutes ago : How far back to scan for taken messages}
                            {--dry-run : Log what would be inserted without writing to the database}';

    protected $description = 'Scan recent taken OFFER messages and insert visualisation flow records';

    public function handle(VisualiseService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ago    = $this->option('ago');

        if ($dryRun) {
            $this->info('DRY RUN — no database writes will be made.');
        }

        Log::info('Starting visualise scan', ['ago' => $ago, 'dry_run' => $dryRun]);

        $count = $service->scan($ago, $dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would insert' : 'Inserted';
        $this->info("{$prefix} {$count} visualise record(s).");
        Log::info('Visualise scan complete', ['count' => $count, 'dry_run' => $dryRun]);

        return Command::SUCCESS;
    }
}
