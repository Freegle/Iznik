<?php

namespace App\Console\Commands\Donation;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\GiftAidClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateGiftAidCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'donations:update-giftaid
                            {--dry-run : Show counts without making changes or sending emails}';

    protected $description = 'Update gift aid data and send chase-up emails (V1: donations_giftaid.php)';

    public function handle(GiftAidClaimService $service): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $dryRun = (bool) $this->option('dry-run');

            if ($dryRun) {
                $this->info('DRY RUN — no changes will be made.');
            }

            $postcodes = $service->identifyPostcodes($dryRun);
            $houses = $service->identifyHouseNumbers($dryRun);
            $identified = $service->identifyGiftAidedDonations($dryRun);

            $sent = $service->sendGiftAidChaseUps($dryRun);

            $verb = $dryRun ? 'would' : 'did';
            $this->info("donations:update-giftaid {$verb} — postcodes: {$postcodes}, houses: {$houses}, identified: {$identified}, chaseups: {$sent}");
            Log::info('donations:update-giftaid complete', [
                'postcodes' => $postcodes,
                'houses' => $houses,
                'identified' => $identified,
                'chaseups' => $sent,
                'dry_run' => $dryRun,
            ]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
