<?php

namespace App\Console\Commands\AzureArchive;

use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\ArchiveSources;
use App\Services\AzureArchive\AzureClient;
use App\Services\AzureArchive\AzureUnavailable;
use App\Services\AzureArchive\Bytes;
use App\Services\AzureArchive\EgressBudget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Measure the archive before anything is copied: archived rows per table from our database, and,
 * once the read-only Azure app is set up, what Azure holds by tier, members' egress and the bill.
 * Reads only; fetches no blob. The row counts are kept for azure-archive:status.
 *
 *   php artisan azure-archive:measure
 */
#[AsCommand(name: 'azure-archive:measure')]
class MeasureCommand extends Command
{
    /**
     * Retail prices in GBP, North Europe, from prices.azure.com on 2026-11-11, for the estimate
     * only. Read operations per 10,000 by tier, data retrieval per GB.
     */
    private const READ_PER_10K = ['Hot' => 0.003, 'Cool' => 0.0075, 'Cold' => 0.0752];

    private const RETRIEVAL_PER_GB = ['Hot' => 0.0, 'Cool' => 0.0075, 'Cold' => 0.0226];

    /** Mean blob size from a 223-row sample on 2026-11-11, used for the estimate. */
    private const SAMPLE_MEAN_BYTES = 150000;

    protected $signature = 'azure-archive:measure
                            {--window=1000000 : Ids counted per query}';

    protected $description = 'Count the archived images and read Azure\'s size, tier, egress and bill, fetching nothing';

    public function handle(): int
    {
        $window = max(1000, (int) $this->option('window'));
        $rows = [];
        $superseded = [];

        foreach (ArchiveSources::sources() as $source) {
            [$rows[$source], $superseded[$source]] = $this->count($source, $window);
        }

        (new ArchiveLedger())->saveMeasurement($rows, $superseded);

        $this->table(['Table', 'Prefix', 'Archived rows', 'Of which served from uploads'], array_map(
            fn (string $s) => [$s, ArchiveSources::prefix($s), number_format($rows[$s]), number_format($superseded[$s])],
            ArchiveSources::sources()
        ));

        $toCopy = array_sum($rows) - array_sum($superseded);
        $this->line('Rows to copy: ' . number_format($toCopy));

        $azure = AzureClient::fromConfig();

        if (! $azure->configured()) {
            $this->warn('The read-only Azure app is not set up (' . implode(', ', $azure->missingSettings()) . '): no size, tier, egress or bill.');

            return Command::SUCCESS;
        }

        try {
            $account = $azure->storageAccount();
            $this->table(['Account', 'Location', 'SKU', 'Kind', 'Default tier'], [array_values($account)]);

            $tiers = $azure->blobsByTier();
            $this->table(['Tier', 'Blobs (with thumbnails)', 'Size'], array_map(
                fn (string $tier, array $v) => [$tier, number_format($v['count']), Bytes::gb($v['bytes'])],
                array_keys($tiers),
                $tiers
            ));

            $now = CarbonImmutable::now('UTC');
            $egress = array_sum($azure->egressByHour($now->subDays(31), $now));
            $this->line('Egress, last 31 days (members\' views and anything else): ' . Bytes::gb($egress));

            $ceiling = EgressBudget::fromConfig()->ceilingBytes();
            $share = max(0.0, ($ceiling - $egress * (float) config('freegle.azure_archive.site_growth', 1.25)) / 31);
            $copyBytes = $toCopy * self::SAMPLE_MEAN_BYTES;
            $this->line('Expected copy: about ' . Bytes::gb($copyBytes) . ' (' . number_format(self::SAMPLE_MEAN_BYTES / 1000) . ' KB a row from the sample).');
            $this->line($share > 0
                ? 'At ' . Bytes::gb($share) . ' a day inside the ceiling, about ' . number_format($copyBytes / $share) . ' days.'
                : 'Members\' views alone leave no room under the ceiling.');

            $tier = $account['accessTier'] ?: 'Hot';
            $reads = $toCopy / 10000 * (self::READ_PER_10K[$tier] ?? self::READ_PER_10K['Cool']);
            $retrieval = $copyBytes / 1e9 * (self::RETRIEVAL_PER_GB[$tier] ?? self::RETRIEVAL_PER_GB['Cool']);
            $this->line(sprintf('Unavoidable request charges for the whole copy at %s list prices: about GBP %.2f in reads, GBP %.2f in retrieval.', $tier, $reads, $retrieval));

            foreach (['BillingMonthToDate', 'TheLastBillingMonth'] as $timeframe) {
                $costs = $azure->costs($timeframe);
                $this->line(sprintf('%s: total %.2f %s, bandwidth %.2f for %.2f GB.', $timeframe, $costs->total(), $costs->currency, $costs->bandwidthCost(), $costs->bandwidthGb()));
            }
        } catch (AzureUnavailable $e) {
            $this->error('Azure: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @return array{0: int, 1: int} archived rows, and those with an uploads id */
    private function count(string $source, int $window): array
    {
        $max = (int) DB::table($source)->max('id');
        $archived = 0;
        $superseded = 0;

        for ($from = 0; $from <= $max; $from += $window) {
            $range = fn () => DB::table($source)
                ->whereBetween('id', [$from, $from + $window - 1])
                ->where('archived', 1);

            $archived += $range()->count();
            $superseded += $range()->whereNotNull('externaluid')->where('externaluid', '<>', '')->count();
        }

        return [$archived, $superseded];
    }
}
