<?php

namespace App\Console\Commands\AzureArchive;

use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\AzureArchiveAlert;
use App\Services\AzureArchive\AzureClient;
use App\Services\AzureArchive\AzureUnavailable;
use App\Services\AzureArchive\BudgetFigures;
use App\Services\AzureArchive\Bytes;
use App\Services\AzureArchive\EgressBudget;
use App\Services\AzureArchive\FigureGatherer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Where the Azure archive copy is, and whether Azure is charging anything.
 *
 * With --report, raises AzureArchiveAlert in Sentry when Azure has charged for bandwidth, when
 * egress is over the ceiling (members' views included), or when a stop is latched. Scheduled
 * hourly once the read-only Azure app is set up, whether or not the copy is enabled, so the bill
 * is watched from the first day.
 *
 *   php artisan azure-archive:status
 *   php artisan azure-archive:status --clear-stop
 */
#[AsCommand(name: 'azure-archive:status')]
class StatusCommand extends Command
{
    protected $signature = 'azure-archive:status
                            {--report : Raise an alert in Sentry when anything needs a person}
                            {--clear-stop : Clear a latched stop, after a person has looked at the bill}
                            {--requeue-failed : Give failed rows one more fetch, after a person has looked at why}';

    protected $description = 'Progress of the Azure archive copy, its egress budget and the Azure bill';

    public function handle(): int
    {
        $ledger = new ArchiveLedger();

        if ($this->option('clear-stop')) {
            $was = $ledger->latchedStop();
            $ledger->clearStop();
            $this->info($was === null ? 'No stop was latched.' : "Cleared: {$was}");

            return Command::SUCCESS;
        }

        if ($this->option('requeue-failed')) {
            $this->info('Requeued ' . $ledger->requeue() . ' row(s) for one more fetch.');

            return Command::SUCCESS;
        }

        $this->progress($ledger);

        $azure = AzureClient::fromConfig();

        if (! $azure->configured()) {
            $this->warn('The read-only Azure app is not set up (' . implode(', ', $azure->missingSettings()) . '): no egress or bill to show.');

            return Command::SUCCESS;
        }

        $gatherer = FigureGatherer::fromConfig($azure, $ledger);
        $figures = $gatherer->gather();
        $decision = EgressBudget::fromConfig()->decide($figures);
        $f = $decision->figures;

        $this->table(['Egress', 'Value'], [
            ['Ceiling, any 31 days', Bytes::gb($f['ceiling_bytes'])],
            ['Azure egress, last 31 days', Bytes::gb($f['azure_egress_31d'])],
            ['Of which members\' views', Bytes::gb($f['site_bytes_31d'])],
            ['Copy, last 31 days', Bytes::gb($f['our_bytes_31d'])],
            ['Copy, last 24 hours', Bytes::gb($f['our_bytes_24h'])],
            ['Daily share for the copy', Bytes::gb($f['daily_share'])],
            ['Allowed now', Bytes::gb($decision->allowedBytes)],
        ]);

        foreach ($decision->refusals as $refusal) {
            $this->warn($refusal);
        }

        $this->bill($gatherer);
        $this->eta($ledger, (float) $f['daily_share']);

        if ($this->option('report')) {
            $this->report($ledger, $figures, $f['ceiling_bytes'], $decision->latch);
        }

        return Command::SUCCESS;
    }

    private function progress(ArchiveLedger $ledger): void
    {
        $rows = array_map(fn ($r) => [$r->source, $r->status, number_format($r->n), Bytes::gb($r->bytesfetched), Bytes::gb($r->bytesstored)], $ledger->progress());

        if ($rows === []) {
            $this->line('Nothing copied yet.');
        } else {
            $this->table(['Table', 'Status', 'Rows', 'Egress', 'Stored'], $rows);
        }

        $stop = $ledger->latchedStop();

        if ($stop !== null) {
            $this->error("Stop latched: {$stop}");
        }
    }

    private function bill(FigureGatherer $gatherer): void
    {
        try {
            $month = $gatherer->costs();
            $this->line(sprintf('This billing period so far: %.2f %s in total, %.2f for bandwidth (%.2f GB billed).', $month->total(), $month->currency, $month->bandwidthCost(), $month->bandwidthGb()));

            $charged = array_filter($month->rows, fn ($r) => $r['cost'] > 0);

            if ($charged !== []) {
                $this->table(['Category', 'Meter', 'Quantity', 'Cost'], array_map(
                    fn ($r) => [$r['category'], $r['meter'], number_format($r['quantity'], 2), number_format($r['cost'], 4)],
                    $charged
                ));
            }

            $last = $gatherer->costs('TheLastBillingMonth');
            $this->line(sprintf('Last billing period: %.2f %s in total, %.2f for bandwidth (%.2f GB billed).', $last->total(), $last->currency, $last->bandwidthCost(), $last->bandwidthGb()));
        } catch (AzureUnavailable $e) {
            $this->warn('The bill could not be read: ' . $e->getMessage());
        }
    }

    private function eta(ArchiveLedger $ledger, float $dailyShare): void
    {
        $measured = $ledger->measurement();

        if (empty($measured['rows'])) {
            $this->line('Run azure-archive:measure for an estimate of the time left.');

            return;
        }

        $done = 0;
        $fetched = 0;
        $fetchedRows = 0;

        foreach ($ledger->progress() as $r) {
            $done += $r->n;

            if ($r->status !== 'Superseded') {
                $fetched += $r->bytesfetched;
                $fetchedRows += $r->n;
            }
        }

        $left = max(0, array_sum($measured['rows']) - $done);
        $perRow = $fetchedRows > 0 ? $fetched / $fetchedRows : 150000;
        $days = $dailyShare > 0 ? $left * $perRow / $dailyShare : null;

        $this->line(sprintf(
            '%s rows left of %s measured on %s, about %s to fetch; %s.',
            number_format($left),
            number_format(array_sum($measured['rows'])),
            $measured['at'] ?? '?',
            Bytes::gb($left * $perRow),
            $days === null ? 'no daily share at present' : 'about ' . number_format($days) . ' days at the current share'
        ));
    }

    private function report(ArchiveLedger $ledger, BudgetFigures $figures, int|float $ceiling, ?string $latch): void
    {
        $problems = [];
        $egress31d = $figures->azureEgress31d;
        $bandwidthCost = $figures->bandwidthCost;

        // Unreadable figures stop the copy, which is safe, but they also leave the bill unwatched.
        if ($figures->metricsError !== null) {
            $problems[] = "Azure's egress figures cannot be read or trusted: {$figures->metricsError}";
        }

        if ($figures->costError !== null) {
            $problems[] = "the Azure bill cannot be read: {$figures->costError}";
        }

        if ($latch !== null) {
            $ledger->latchStop($latch);
        }

        $stop = $ledger->latchedStop();

        if ($stop !== null) {
            $problems[] = "the copy is stopped: {$stop}";
        }

        if ($bandwidthCost !== null && $bandwidthCost > 0) {
            $problems[] = sprintf('Azure has charged %.2f for bandwidth this billing period', $bandwidthCost);
        }

        if ($egress31d !== null && $egress31d > $ceiling) {
            $problems[] = 'egress over the last 31 days is ' . Bytes::gb($egress31d) . ', over the ceiling of ' . Bytes::gb($ceiling);
        }

        if ($problems !== []) {
            report(new AzureArchiveAlert('azure-archive: ' . implode('; ', $problems) . '.'));
            $this->error('Reported: ' . implode('; ', $problems));
        }
    }
}
