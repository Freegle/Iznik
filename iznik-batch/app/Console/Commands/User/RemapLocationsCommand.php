<?php

namespace App\Console\Commands\User;

use App\Services\UserLocationRemapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemapLocationsCommand extends Command
{
    protected $signature = 'users:remap-locations';

    protected $description = 'Update cached location names in user settings when the canonical name has changed';

    public function handle(UserLocationRemapService $service): int
    {
        Log::info('Starting user location remap');

        $result = $service->remapLocations();

        $this->info("Updated {$result['changed']} users' location data.");
        Log::info('User location remap complete', $result);

        return Command::SUCCESS;
    }
}
