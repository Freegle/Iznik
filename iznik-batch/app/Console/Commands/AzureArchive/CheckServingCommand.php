<?php

namespace App\Console\Commands\AzureArchive;

use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\ArchiveSources;
use App\Services\AzureArchive\AzureArchiveAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Proves that copied archive images are served from our bucket, the way members get them.
 *
 * For a few copied rows of every kind it fetches the public archive address (the frontend-nginx
 * chain) and the delivery resizer's thumbnail of it. The chain says which store answered in
 * X-Archive-Source; a copied image answered by Azure means the bucket missed it, which the chain
 * would otherwise hide while still spending Azure egress. The result is kept, so the end of the
 * migration can require a recent clean pass.
 *
 *   php artisan azure-archive:check-serving --sample=5
 */
#[AsCommand(name: 'azure-archive:check-serving')]
class CheckServingCommand extends Command
{
    public const RESULT_KEY = 'azure_archive_serving_check';

    protected $signature = 'azure-archive:check-serving
                            {--sample=5 : Copied rows checked per table}
                            {--report : Raise an alert in Sentry on any miss}';

    protected $description = 'Check that copied archive images are served from our bucket';

    public function handle(): int
    {
        $base = rtrim((string) config('freegle.azure_archive.serving_url'), '/');

        if ($base === '') {
            $this->info('AZURE_ARCHIVE_SERVING_URL is blank; nothing to check.');

            return Command::SUCCESS;
        }

        $delivery = rtrim((string) config('freegle.delivery.base_url'), '/');
        $ledger = new ArchiveLedger();
        $checked = 0;
        $misses = [];

        foreach (ArchiveSources::sources() as $source) {
            foreach ($ledger->sampleCopied($source, max(1, (int) $this->option('sample'))) as $row) {
                $checked++;
                $url = "{$base}/{$row->blobname}";
                $miss = $this->checkImage($url, (int) $row->storedbytes)
                    ?? $this->checkThumbnail("{$delivery}/?w=250&h=250&url=" . rawurlencode($url));

                if ($miss !== null) {
                    $misses[] = "{$row->blobname}: {$miss}";
                }
            }
        }

        DB::table('config')->upsert([[
            'key' => self::RESULT_KEY,
            'value' => json_encode(['at' => CarbonImmutable::now('UTC')->toIso8601String(), 'checked' => $checked, 'misses' => $misses]),
        ]], ['key'], ['value']);

        if ($misses === []) {
            $this->info("Checked {$checked} copied image(s): all served from the bucket.");

            return Command::SUCCESS;
        }

        foreach ($misses as $miss) {
            $this->error($miss);
        }

        if ($this->option('report')) {
            report(new AzureArchiveAlert('azure-archive:check-serving: ' . count($misses) . " of {$checked} copied images not served from the bucket, e.g. " . $misses[0]));
        }

        return Command::FAILURE;
    }

    private function checkImage(string $url, int $storedBytes): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException $e) {
            return 'no answer: ' . $e->getMessage();
        }

        if ($response->status() !== 200) {
            return "HTTP {$response->status()}";
        }

        $source = $response->header('X-Archive-Source');

        if ($source !== 'bucket') {
            return 'served by ' . ($source === '' ? 'an unknown store' : $source) . ', not the bucket';
        }

        if (strlen($response->body()) !== $storedBytes) {
            return strlen($response->body()) . " bytes, expected {$storedBytes}";
        }

        return null;
    }

    private function checkThumbnail(string $url): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException $e) {
            return 'thumbnail: no answer: ' . $e->getMessage();
        }

        if ($response->status() !== 200 || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return "thumbnail: HTTP {$response->status()} " . $response->header('Content-Type');
        }

        return null;
    }
}
