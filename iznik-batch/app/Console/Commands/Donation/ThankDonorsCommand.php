<?php

namespace App\Console\Commands\Donation;

use App\Services\DonationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ThankDonorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:donations:thank
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send thank you emails to recent donors';

    /**
     * Execute the console command.
     */
    public function handle(DonationService $donationService): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('Starting donor thank you process', ['dry_run' => $dryRun]);
        $this->info('Thanking donors...');

        $stats = $donationService->thankDonors($dryRun);

        $this->info("Processed: {$stats['processed']}");
        $this->info("Emails sent: {$stats['emails_sent']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('Donor thank you process complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
