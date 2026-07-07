<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateAdsTargetCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'donations:update-ads-target
                            {--dry-run : Show what would be updated without actually changing}';

    protected $description = 'Update the ads-off donation target based on recent donations';

    public function handle(DonationService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting ads target update', ['dry_run' => $dryRun]);

            $stats = $service->updateAdsTarget($dryRun);

            $this->info("Target: {$stats['target_max']}, Donated 24h: {$stats['donated_24h']}, Remaining: {$stats['remaining']}, Ads enabled: {$stats['ads_enabled']}");
            Log::info('Ads target update complete', $stats);

            return Command::SUCCESS;
        });
    }
}
