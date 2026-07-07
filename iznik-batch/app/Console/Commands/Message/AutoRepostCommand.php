<?php

namespace App\Console\Commands\Message;

use App\Services\AutoRepostService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoRepostCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:auto-repost
                            {--dry-run : Show what would be reposted without actually reposting}';

    protected $description = 'Auto-repost approved messages that are due for reposting based on group settings';

    public function handle(AutoRepostService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting auto-repost processing', ['dry_run' => $dryRun]);
        $this->info('Processing messages for auto-repost...');

        $stats = $service->process($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Reposted: {$stats['reposted']}, Warned: {$stats['warned']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Auto-repost processing complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
