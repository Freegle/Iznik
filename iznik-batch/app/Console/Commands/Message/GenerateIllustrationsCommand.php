<?php

namespace App\Console\Commands\Message;

use App\Services\MessageIllustrationsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateIllustrationsCommand extends Command
{
    protected $signature = 'messages:generate-illustrations';

    protected $description = 'Generate AI illustrations for messages that have no photos';

    public function handle(MessageIllustrationsService $service): int
    {
        Log::info('Starting message illustrations generation');

        $result = $service->processIllustrations();

        $this->info("Illustrations: {$result['processed']} processed, {$result['cleaned']} cleaned up.");
        Log::info('Message illustrations complete', $result);

        return Command::SUCCESS;
    }
}
