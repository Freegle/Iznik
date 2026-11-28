<?php

namespace App\Console\Commands\Servers;

use App\Mail\Servers\ServerSizingReportMail;
use App\Services\EmailSpoolerService;
use App\Services\Servers\KatapultClient;
use App\Services\Servers\ServerSizingReport;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'servers:rightsize-report')]
class RightsizeReportCommand extends Command
{
    protected $signature = 'servers:rightsize-report
                            {--dry-run : Print the verdicts and send nothing}
                            {--no-review : Leave out the AI review}
                            {--to= : Send to these addresses (comma-separated) instead of SERVERS_REPORT_TO}';

    protected $description = 'Mail the weekly report on whether each server is the right size';

    public function handle(ServerSizingReport $report, KatapultClient $katapult): int
    {
        // One or more addresses, separated by commas. Each gets its own copy.
        $to = array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->option('to') ?: config('freegle.servers.report_to', '')))
        ))));
        $dryRun = (bool) $this->option('dry-run');

        if (! $katapult->configured()) {
            $this->info('KATAPULT_API_TOKEN is not set; nothing to report on.');

            return self::SUCCESS;
        }

        if ($to === [] && ! $dryRun) {
            $this->info('SERVERS_REPORT_TO is not set; nobody to send the report to.');

            return self::SUCCESS;
        }

        $built = $report->build(! $this->option('no-review') && ! $dryRun);
        $mail = new ServerSizingReportMail($built['analysis'], $built['review'], $to[0] ?? 'nobody@example.org');

        $this->table(
            ['Server', 'Package', 'A month', 'Verdict'],
            array_map(
                fn (array $card) => [$card['name'], strtok($card['package'], ':'), $card['cost'], $card['verdict']],
                $mail->cards()
            )
        );

        if ($dryRun) {
            $this->info('Dry run: nothing sent.');

            return self::SUCCESS;
        }

        // The report is built once; each address gets a mail of its own.
        foreach ($to as $address) {
            app(EmailSpoolerService::class)->spool(
                new ServerSizingReportMail($built['analysis'], $built['review'], $address)
            );
        }
        $this->info('Sizing report sent to '.implode(', ', $to).($built['review'] === null ? ' without the AI review.' : '.'));

        return self::SUCCESS;
    }
}
