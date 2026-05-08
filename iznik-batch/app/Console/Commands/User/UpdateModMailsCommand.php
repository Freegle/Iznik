<?php

namespace App\Console\Commands\User;

use App\Services\UserModMailsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateModMailsCommand extends Command
{
    protected $signature = 'users:update-modmails
                            {--dry-run : Show counts without making changes}';

    protected $description = 'Sync recent mod actions into users_modmails and prune old entries (V1: users_modmails.php)';

    public function handle(UserModMailsService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');

            return Command::SUCCESS;
        }

        $inserted = $service->updateModMails();
        $deleted = $service->pruneOldEntries();

        $this->info("users:update-modmails complete — inserted: {$inserted}, pruned: {$deleted}");
        Log::info('users:update-modmails complete', ['inserted' => $inserted, 'pruned' => $deleted]);

        return Command::SUCCESS;
    }
}
