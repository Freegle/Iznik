<?php

namespace App\Console\Commands\LoveJunk;

use App\Services\LoveJunkInvoiceService;
use Illuminate\Console\Command;

class SendTnInvoiceCommand extends Command
{
    protected $signature = 'lovejunk:send-tn-invoice
                            {--period= : Override period (YYYY-MM format, defaults to last month)}
                            {--dry-run : Calculate and log the invoice amount without sending email}';

    protected $description = 'Calculate and send the monthly LoveJunk/TrashNothing invoice split';

    public function handle(LoveJunkInvoiceService $service): int
    {
        $period = $this->option('period') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->sendInvoice($period, $dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}TN: {$result['tn']} posts ({$result['tn_percent']}%) — FD: {$result['fd']} posts ({$result['fd_percent']}%)");
        $this->info("{$prefix}TN invoice amount: £{$result['tn_amount']}");

        if (!$dryRun && $result['tn_amount'] > 0) {
            $this->info('Invoice email sent.');
        } elseif (!$dryRun && $result['tn_amount'] === 0.0) {
            $this->warn('No LoveJunk data for this period — no email sent.');
        }

        return self::SUCCESS;
    }
}
