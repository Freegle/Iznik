<?php

namespace App\Console\Commands\Message;

use App\Services\MessageSpatialIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateSpatialIndexCommand extends Command
{
    protected $signature = 'messages:update-spatial-index';

    protected $description = 'Update the spatial index for recent messages';

    public function handle(MessageSpatialIndexService $service): int
    {
        Log::info('Starting message spatial index update');

        $result = $service->updateSpatialIndex();

        $this->info("Updated {$result['indexed']} spatial index entries.");
        Log::info('Message spatial index update complete', $result);

        return Command::SUCCESS;
    }
}
