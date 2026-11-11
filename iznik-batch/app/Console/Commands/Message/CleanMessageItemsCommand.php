<?php

namespace App\Console\Commands\Message;

use App\Services\MessageItemsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Give new and live posts the single item they are about (messages_cleaned_items).
 * docs/developers/reference/message-items.md.
 */
#[AsCommand(name: 'messages:clean-items')]
class CleanMessageItemsCommand extends Command
{
    protected $signature = 'messages:clean-items {--limit=1000 : Posts to look at this run}';

    protected $description = 'Work out the single item each post is about, for pictures, matching and search';

    public function handle(MessageItemsService $service): int
    {
        $done = $service->process(max(1, (int) $this->option('limit')));
        $this->info("Gave {$done} posts their item.");

        return self::SUCCESS;
    }
}
