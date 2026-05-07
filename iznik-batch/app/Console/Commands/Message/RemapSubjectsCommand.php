<?php

namespace App\Console\Commands\Message;

use App\Services\MessageRemapSubjectsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemapSubjectsCommand extends Command
{
    protected $signature = 'messages:remap-subjects';

    protected $description = 'Update message subjects when associated location names have changed';

    public function handle(MessageRemapSubjectsService $service): int
    {
        Log::info('Starting message subject remap');

        $result = $service->remapSubjects();

        $this->info("Remapped {$result['changed']} message subjects.");
        Log::info('Message subject remap complete', $result);

        return Command::SUCCESS;
    }
}
