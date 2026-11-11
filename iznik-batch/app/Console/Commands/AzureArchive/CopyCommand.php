<?php

namespace App\Console\Commands\AzureArchive;

use App\Services\AzureArchive\ArchiveCopier;
use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\AzureArchiveAlert;
use App\Services\AzureArchive\AzureClient;
use App\Services\AzureArchive\BlobFetcher;
use App\Services\AzureArchive\Bytes;
use App\Services\AzureArchive\EgressBudget;
use App\Services\AzureArchive\FigureGatherer;
use App\Services\AzureArchive\LosslessJpeg;
use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Copy the next archived images from Azure into the images bucket, as far as the free egress
 * allows (docs/ops/runbooks/azure-archive-migration.md).
 *
 * Nothing is fetched unless AZURE_ARCHIVE_ENABLED is on, the read-only Azure app can show Azure's
 * own egress and cost figures, and those leave room. Scheduled every 15 minutes once enabled.
 *
 *   php artisan azure-archive:copy --dry-run
 */
#[AsCommand(name: 'azure-archive:copy')]
class CopyCommand extends Command
{
    protected $signature = 'azure-archive:copy
                            {--dry-run : Show the figures, the decision and the next blobs, and fetch nothing}
                            {--max-mb= : Fetch at most this many MB in this run, if the budget allows that much}';

    protected $description = 'Copy archived images from Azure to the images bucket within the free egress';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('freegle.azure_archive.enabled')) {
            $this->info('AZURE_ARCHIVE_ENABLED is off; nothing fetched.');

            return Command::SUCCESS;
        }

        $azure = AzureClient::fromConfig();

        if (! $azure->configured()) {
            $this->error('The read-only Azure app is not set up (' . implode(', ', $azure->missingSettings()) . '), so egress cannot be checked. Nothing fetched.');

            return Command::FAILURE;
        }

        $jpeg = null;

        if (config('freegle.azure_archive.optimise')) {
            $jpeg = new LosslessJpeg();

            if (! $jpeg->available()) {
                $this->error('jpegtran and djpeg are not installed (libjpeg-turbo-progs). Nothing fetched.');

                return Command::FAILURE;
            }
        }

        $lock = Cache::lock('azure-archive:copy', 3600);

        if (! $lock->get()) {
            $this->warn('Another copy is running.');

            return Command::SUCCESS;
        }

        try {
            return $this->copyWithinBudget($azure, $jpeg, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function copyWithinBudget(AzureClient $azure, ?LosslessJpeg $jpeg, bool $dryRun): int
    {
        $ledger = new ArchiveLedger();
        $budget = EgressBudget::fromConfig();
        $decision = $budget->decide(FigureGatherer::fromConfig($azure, $ledger)->gather());

        if ($decision->latch !== null && ! $dryRun) {
            $ledger->latchStop($decision->latch);
            report(new AzureArchiveAlert('azure-archive: ' . $decision->latch . '. The copy is stopped until a person clears it.'));
        }

        $f = $decision->figures;
        $this->table(['Figure', 'Value'], [
            ['Ceiling, any 31 days', Bytes::gb($f['ceiling_bytes'])],
            ['Azure egress, last 31 days', Bytes::gb($f['azure_egress_31d'])],
            ['Of which members\' views', Bytes::gb($f['site_bytes_31d'])],
            ['Copy, last 31 days', Bytes::gb($f['our_bytes_31d'])],
            ['Copy, last 24 hours', Bytes::gb($f['our_bytes_24h'])],
            ['Daily share for the copy', Bytes::gb($f['daily_share'])],
            ['Bandwidth charged this billing period', $f['bandwidth_cost'] === null ? 'unknown' : number_format((float) $f['bandwidth_cost'], 2)],
            ['Allowed now', Bytes::gb($decision->allowedBytes)],
        ]);

        foreach ($decision->refusals as $refusal) {
            $this->warn($refusal);
        }

        $allowance = $decision->allowedBytes;

        if ($this->option('max-mb') !== null) {
            $allowance = min($allowance, max(0, (int) $this->option('max-mb')) * 1000 * 1000);
        }

        $copier = new ArchiveCopier(
            $ledger,
            BlobFetcher::fromConfig(),
            new ObjectStore(),
            $jpeg,
            (string) config('freegle.azure_archive.key_prefix', 'archive/'),
            // At most 50 minutes, so a run always ends inside its hour-long lock.
            min(50, max(1, (int) config('freegle.azure_archive.run_max_minutes', 40))) * 60,
        );

        if ($dryRun) {
            $this->warn('[DRY RUN] nothing fetched. The next blobs:');

            foreach ($copier->preview(20) as $name) {
                $this->line("  {$name}");
            }

            return Command::SUCCESS;
        }

        if ($decision->refusals !== []) {
            return Command::FAILURE;
        }

        if ($allowance <= ArchiveCopier::BLOB_RESERVE_BYTES) {
            $this->info('No egress left in the budget for now; nothing fetched.');

            return Command::SUCCESS;
        }

        $result = $copier->copy($allowance);
        $s = $result['stats'];

        $this->table(['Metric', 'Value'], [
            ['Fetched', $s['fetched']],
            ['Copied', $s['copied']],
            ['Missing on Azure', $s['missing']],
            ['Failed', $s['failed']],
            ['Superseded (served from uploads)', $s['superseded']],
            ['Egress counted', Bytes::gb($s['bytes_fetched'])],
            ['Stored (after lossless optimisation)', Bytes::gb($s['bytes_stored']) . ' of ' . Bytes::gb($s['bytes_original'])],
        ]);

        $stopped = $result['stopped'];

        if ($stopped !== null) {
            $this->line($stopped['message']);

            if ($stopped['kind'] === ArchiveCopier::STOP_BUCKET) {
                report(new ObjectStoreUnavailable('azure-archive:copy: ' . $stopped['message']));
            }

            if ($stopped['kind'] === ArchiveCopier::STOP_SUSPECT) {
                // Latched: every run would otherwise mark another batch of rows on the same fault.
                $ledger->latchStop($stopped['message']);
                report(new AzureArchiveAlert('azure-archive:copy stopped until a person clears it: ' . $stopped['message']));
            }

            if (! in_array($stopped['kind'], [ArchiveCopier::STOP_BUDGET, ArchiveCopier::STOP_TIME], true)) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
