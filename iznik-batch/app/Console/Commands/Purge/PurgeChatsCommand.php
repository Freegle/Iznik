<?php

namespace App\Console\Commands\Purge;

use App\Services\PurgeService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeChatsCommand extends Command
{
    use GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'purge:chats
                            {--spam-days=7 : Days to keep spam chat messages}
                            {--dry-run : Show what would be purged without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Purge old and spam chat data';

    /**
     * Execute the console command.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting chat purge', ['dry_run' => $dryRun]);
        $this->info('Purging chat data...');

        $results = [];

        // Purge spam messages.
        $this->line('Purging spam chat messages...');
        $spamDays = (int) $this->option('spam-days');
        $results['spam_messages'] = $purgeService->purgeSpamChatMessages($spamDays, $dryRun);
        $this->info("  Purged {$results['spam_messages']} spam messages");

        if ($this->shouldAbort()) {
            $this->warn('Aborting due to shutdown signal.');
            return Command::SUCCESS;
        }

        // Purge empty chat rooms.
        $this->line('Purging empty chat rooms...');
        $results['empty_rooms'] = $purgeService->purgeEmptyChatRooms($dryRun);
        $this->info("  Purged {$results['empty_rooms']} empty rooms");

        if ($this->shouldAbort()) {
            $this->warn('Aborting due to shutdown signal.');
            return Command::SUCCESS;
        }

        // Purge orphaned images.
        $this->line('Purging orphaned chat images...');
        $results['orphaned_images'] = $purgeService->purgeOrphanedChatImages($dryRun);
        $this->info("  Purged {$results['orphaned_images']} orphaned images");

        $this->newLine();
        $this->info('Chat purge complete.');
        $this->table(
            ['Category', 'Purged'],
            collect($results)->map(fn ($count, $key) => [str_replace('_', ' ', ucfirst($key)), $count])->toArray()
        );

        Log::info('Chat purge complete', $results);

        return Command::SUCCESS;
    }
}
