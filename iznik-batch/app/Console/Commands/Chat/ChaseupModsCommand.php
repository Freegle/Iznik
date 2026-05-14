<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatChaseupModsService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;

class ChaseupModsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'chats:chaseup-mods
                            {--dry-run : Count eligible chats without sending emails}';

    protected $description = 'Notify group mods about User2Mod chats where the member has had no reply';

    public function handle(ChatChaseupModsService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no emails will be sent.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            $stats = $service->chaseupMods($dryRun);

            $verb = $dryRun ? 'Would notify' : 'Notified';
            $this->info("{$verb} {$stats['notified']} mod(s) across {$stats['chats_checked']} chat(s) checked.");

            return Command::SUCCESS;
        });
    }
}
