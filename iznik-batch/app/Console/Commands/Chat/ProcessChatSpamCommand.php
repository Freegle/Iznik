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

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');

            return Command::SUCCESS;
        }

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting chat spam processing');

            $warned = $service->warnInnocentUsers();
            $marked = $service->autoMarkSpam();

            $this->info("Chat spam: warned {$warned} innocent users, auto-marked {$marked} messages.");
            Log::info('Chat spam processing complete', ['warned' => $warned, 'auto_marked' => $marked]);

            return Command::SUCCESS;
        });
    }
}
