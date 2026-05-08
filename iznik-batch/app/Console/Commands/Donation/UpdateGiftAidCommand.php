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
            if ($this->option('dry-run')) {
                $this->info('DRY RUN — no changes will be made.');

                return Command::SUCCESS;
            }

            $postcodes = $service->identifyPostcodes();
            $houses = $service->identifyHouseNumbers();
            $identified = $service->identifyGiftAidedDonations();

            $sent = $service->sendGiftAidChaseUps();

            $this->info("donations:update-giftaid complete — postcodes: {$postcodes}, houses: {$houses}, identified: {$identified}, chaseups: {$sent}");
            Log::info('donations:update-giftaid complete', [
                'postcodes' => $postcodes,
                'houses' => $houses,
                'identified' => $identified,
                'chaseups' => $sent,
            ]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
