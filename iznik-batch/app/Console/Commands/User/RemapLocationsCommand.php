<?php

namespace App\Console\Commands\User;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\UserLocationRemapService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemapLocationsCommand extends Command
{
    use PreventsOverlapping;
    use GracefulShutdown;

    protected $signature = 'users:remap-locations
                            {--dry-run : Show what would be remapped without making changes}';

    protected $description = 'Update cached location names in user settings when the canonical name has changed';

    public function handle(UserLocationRemapService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');
            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('Dry run — counting changes but not writing.');
            } else {
                Log::info('Starting user location remap');
            }

            $result = $service->remapLocations($dryRun);

            $verb = $dryRun ? 'Would update' : 'Updated';
            $this->info("{$verb} {$result['changed']} of {$result['checked']} users' location data.");

            if (!empty($result['samples'])) {
                $this->line('Sample changes (up to 10):');
                foreach ($result['samples'] as $s) {
                    $this->line(sprintf('  user #%d: %s → %s', $s['userid'], $s['old'] ?? '(unset)', $s['new']));
                }
            }

            if (!$dryRun) {
                Log::info('User location remap complete', ['changed' => $result['changed'], 'checked' => $result['checked']]);
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
