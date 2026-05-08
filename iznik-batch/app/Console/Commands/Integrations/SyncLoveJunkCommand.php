<?php

namespace App\Console\Commands\Integrations;

use App\Services\LoveJunkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncLoveJunkCommand extends Command
{
    protected $signature = 'integrations:sync-lovejunk';

    protected $description = 'Sync Freegle offer messages with LoveJunk API';

    public function handle(LoveJunkService $service): int
    {
        Log::info('LoveJunk: Starting sync');

        $result = $service->sync();

        $this->info(sprintf(
            'Sent: %d, Edited: %d, Completed/Deleted: %d, Failed: %d',
            $result['sent'],
            $result['edited'],
            $result['completed_or_deleted'],
            $result['failed']
        ));

        Log::info('LoveJunk: Done', $result);

        return Command::SUCCESS;
    }
}
