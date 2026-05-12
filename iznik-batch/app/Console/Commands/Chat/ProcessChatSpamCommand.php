<?php

namespace App\Console\Commands\Chat;

use App\Services\ChatSpamService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessChatSpamCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'chats:process-spam
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Warn innocent users who chatted with spammers; auto-mark spam chat messages';

    public function handle(ChatSpamService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting chat spam processing', ['dry_run' => $dryRun]);

            $warned = $service->warnInnocentUsers($dryRun);
            $marked = $service->autoMarkSpam($dryRun);

            $verb = $dryRun ? 'would warn' : 'warned';
            $verb2 = $dryRun ? 'would auto-mark' : 'auto-marked';
            $this->info("Chat spam: {$verb} {$warned} innocent users, {$verb2} {$marked} messages.");
            Log::info('Chat spam processing complete', ['warned' => $warned, 'auto_marked' => $marked, 'dry_run' => $dryRun]);

            return Command::SUCCESS;
        });
    }
}
