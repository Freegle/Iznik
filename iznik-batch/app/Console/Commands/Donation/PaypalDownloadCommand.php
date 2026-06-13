<?php

namespace App\Console\Commands\Donation;

use App\Services\PaypalDownloadService;
use Illuminate\Console\Command;

/**
 * Fallback PayPal donation downloader (V1 cron/paypal_download.php). The IPN
 * catches donations normally; this backfills any it missed over the last N days.
 */
class PaypalDownloadCommand extends Command
{
    protected $signature = 'donations:paypal-download';

    protected $description = 'Fallback: download recent PayPal donations the IPN missed into users_donations (V1 paypal_download.php)';

    public function handle(PaypalDownloadService $service): int
    {
        $result = $service->download(function (string $date, int $found, int $upserted) {
            $this->line("  {$date}: {$found} found, {$upserted} upserted so far");
        });

        if ($result['skipped']) {
            $this->warn('PayPal credentials not configured — skipped.');

            return self::SUCCESS;
        }

        $this->info(
            "Scanned {$result['days']} day(s): {$result['found']} donation(s) found, {$result['upserted']} upserted."
        );

        return self::SUCCESS;
    }
}
