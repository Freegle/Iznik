<?php

namespace Tests\Feature\AzureArchive;

use App\Console\Commands\AzureArchive\CheckServingCommand;
use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\AzureArchiveAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The commands end to end, with Azure faked: the switches, the figures they read, the latched
 * stop, and the serving check. The rule under all of them: no fetch unless Azure's own figures
 * say there is room.
 */
class AzureArchiveCommandsTest extends TestCase
{
    private int $blobFetches = 0;

    private float $bandwidthCost = 0.0;

    private float $egressPerHour = 1000.0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('images');
        Cache::flush();
        DB::table(ArchiveLedger::TABLE)->delete();
        DB::table('config')->where('key', 'like', 'azure_archive%')->delete();

        config([
            'freegle.azure_archive' => array_merge((array) config('freegle.azure_archive'), AzureClientTest::settings(), [
                'enabled' => true,
                'optimise' => false,
                'serving_url' => 'https://images.example.test/archive',
            ]),
            'freegle.delivery.base_url' => 'https://delivery.example.test',
        ]);
    }

    private function fakeAzure(array $extra = []): void
    {
        Http::fake($extra + AzureClientTest::token() + [
            'freegle.blob.core.windows.net/*' => function () {
                $this->blobFetches++;

                return Http::response('img', 200, ['Content-MD5' => base64_encode(md5('img', true))]);
            },
            'management.azure.com/*/Microsoft.Insights/metrics*' => function (Request $request) {
                if ($request->data()['metricnames'] !== 'Egress') {
                    $value = $request->data()['metricnames'] === 'BlobCapacity' ? 1.3e12 : 1.6e7;

                    return Http::response(['value' => [['timeseries' => [[
                        'metadatavalues' => [['name' => ['value' => 'tier'], 'value' => 'Hot']],
                        'data' => [['timeStamp' => '2026-11-11T00:00:00Z', 'average' => $value]],
                    ]]]]]);
                }

                [$start, $end] = explode('/', $request->data()['timespan']);
                $s = CarbonImmutable::parse($start);

                return Http::response(AzureClientTest::egress($s, (int) $s->diffInHours(CarbonImmutable::parse($end)), $this->egressPerHour));
            },
            'management.azure.com/*storageAccounts/freegle?api-version*' => Http::response([
                'name' => 'freegle', 'location' => 'northeurope', 'sku' => ['name' => 'Standard_GRS'],
                'kind' => 'StorageV2', 'properties' => ['accessTier' => 'Hot'],
            ]),
            'management.azure.com/*/Microsoft.CostManagement/query*' => fn () => Http::response(AzureClientTest::costAnswer([
                [$this->bandwidthCost, 3.0, 'Bandwidth', '', 'Standard Data Transfer Out', 'GBP'],
                [0.4, 30.0, 'Storage', '', 'Hot LRS Data Stored', 'GBP'],
            ])),
        ]);
    }

    private function archivedAttachment(): int
    {
        return (int) DB::table('messages_attachments')->insertGetId(['contenttype' => 'image/jpeg', 'archived' => 1]);
    }

    public function test_switched_off_it_fetches_nothing_and_asks_azure_nothing(): void
    {
        config(['freegle.azure_archive.enabled' => false]);
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('AZURE_ARCHIVE_ENABLED is off')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_without_the_azure_app_it_fetches_nothing(): void
    {
        config(['freegle.azure_archive.client_secret' => '']);
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('AZURE_ARCHIVE_CLIENT_SECRET')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_with_room_under_the_ceiling_it_copies(): void
    {
        $this->fakeAzure();
        $id = $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->assertSuccessful();

        Storage::disk('images')->assertExists("archive/img_{$id}.jpg");
        $this->assertSame('Copied', DB::table(ArchiveLedger::TABLE)->where('blobname', "img_{$id}.jpg")->value('status'));
    }

    public function test_the_max_mb_option_can_only_lower_the_allowance(): void
    {
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy', ['--max-mb' => 0])->assertSuccessful();

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_egress_already_at_the_ceiling_fetches_nothing(): void
    {
        // 31 days at 110 MB an hour is 81.8 GB.
        $this->egressPerHour = 110e6;
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('No egress left')->assertSuccessful();

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_a_bandwidth_charge_latches_a_stop_and_alerts_and_fetches_nothing(): void
    {
        Exceptions::fake();
        $this->bandwidthCost = 0.07;
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
        $this->assertNotNull((new ArchiveLedger())->latchedStop());
        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), '0.07'));

        // The charge clears in Azure's figures, but the stop holds until a person clears it.
        $this->bandwidthCost = 0.0;
        Cache::flush();
        $this->artisan('azure-archive:copy')->expectsOutputToContain('--clear-stop')->assertFailed();
        $this->assertSame(0, $this->blobFetches);

        $this->artisan('azure-archive:status', ['--clear-stop' => true])->expectsOutputToContain('Cleared')->assertSuccessful();
        $this->artisan('azure-archive:copy')->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, $this->blobFetches);
    }

    public function test_an_unreadable_bill_fetches_nothing(): void
    {
        $this->fakeAzure(['management.azure.com/*/Microsoft.CostManagement/query*' => Http::response(['error' => ['message' => 'Too many requests']], 429)]);
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('Cost Management could not be read')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_the_dry_run_names_the_next_blobs_and_fetches_nothing(): void
    {
        config(['freegle.azure_archive.enabled' => false]);
        $this->fakeAzure();
        $id = $this->archivedAttachment();

        $this->artisan('azure-archive:copy', ['--dry-run' => true])
            ->expectsOutputToContain("img_{$id}.jpg")
            ->assertSuccessful();

        $this->assertSame(0, $this->blobFetches);
        $this->assertSame(0, DB::table(ArchiveLedger::TABLE)->count());
    }

    public function test_status_reports_a_charge_to_sentry(): void
    {
        Exceptions::fake();
        $this->bandwidthCost = 1.25;
        $this->fakeAzure();

        $this->artisan('azure-archive:status', ['--report' => true])->assertSuccessful();

        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), '1.25'));
        $this->assertNotNull((new ArchiveLedger())->latchedStop());
    }

    public function test_status_reports_members_views_over_the_ceiling(): void
    {
        Exceptions::fake();
        $this->egressPerHour = 110e6;
        $this->fakeAzure();

        $this->artisan('azure-archive:status', ['--report' => true])->assertSuccessful();

        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), 'over the ceiling'));
    }

    public function test_status_is_quiet_when_all_is_well(): void
    {
        Exceptions::fake();
        $this->fakeAzure();

        $this->artisan('azure-archive:status', ['--report' => true])
            ->expectsOutputToContain('This billing period so far')
            ->assertSuccessful();

        Exceptions::assertNothingReported();
    }

    public function test_status_without_the_azure_app_still_shows_progress(): void
    {
        config(['freegle.azure_archive.tenant_id' => '']);
        Http::fake();

        $this->artisan('azure-archive:status', ['--report' => true])
            ->expectsOutputToContain('Nothing copied yet')
            ->expectsOutputToContain('AZURE_ARCHIVE_TENANT_ID')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_measure_counts_archived_rows_and_keeps_the_counts(): void
    {
        config(['freegle.azure_archive.tenant_id' => '']);
        $before = DB::table('messages_attachments')->where('archived', 1)->count();
        $this->archivedAttachment();
        DB::table('messages_attachments')->insert(['contenttype' => 'image/jpeg', 'archived' => 1, 'externaluid' => 'freegletusd-x']);

        $this->artisan('azure-archive:measure')->assertSuccessful();

        $measured = (new ArchiveLedger())->measurement();
        $this->assertSame($before + 2, $measured['rows']['messages_attachments']);
        $this->assertGreaterThanOrEqual(1, $measured['superseded']['messages_attachments']);
    }

    public function test_check_serving_passes_when_the_bucket_answers(): void
    {
        DB::table(ArchiveLedger::TABLE)->insert(['source' => 'chat_images', 'sourceid' => 7, 'blobname' => 'mimg_7.jpg', 'status' => 'Copied', 'storedbytes' => 5]);
        Http::fake([
            'images.example.test/archive/mimg_7.jpg' => Http::response('12345', 200, ['X-Archive-Source' => 'bucket']),
            'delivery.example.test/*' => Http::response('thumb', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('azure-archive:check-serving')->expectsOutputToContain('all served from the bucket')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'delivery.example.test/?w=250&h=250&url=' . rawurlencode('https://images.example.test/archive/mimg_7.jpg')));
        $result = json_decode((string) DB::table('config')->where('key', CheckServingCommand::RESULT_KEY)->value('value'), true);
        $this->assertSame(1, $result['checked']);
        $this->assertSame([], $result['misses']);
    }

    public function test_check_serving_catches_a_copied_image_answered_by_azure(): void
    {
        Exceptions::fake();
        DB::table(ArchiveLedger::TABLE)->insert(['source' => 'chat_images', 'sourceid' => 7, 'blobname' => 'mimg_7.jpg', 'status' => 'Copied', 'storedbytes' => 5]);
        Http::fake([
            'images.example.test/*' => Http::response('12345', 200, ['X-Archive-Source' => 'azure']),
            'delivery.example.test/*' => Http::response('thumb', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('azure-archive:check-serving', ['--report' => true])->expectsOutputToContain('served by azure')->assertFailed();

        Exceptions::assertReported(AzureArchiveAlert::class);
    }

    public function test_check_serving_catches_a_wrong_length_and_a_broken_thumbnail(): void
    {
        DB::table(ArchiveLedger::TABLE)->insert([
            ['source' => 'chat_images', 'sourceid' => 7, 'blobname' => 'mimg_7.jpg', 'status' => 'Copied', 'storedbytes' => 9],
            ['source' => 'newsfeed_images', 'sourceid' => 8, 'blobname' => 'fimg_8.jpg', 'status' => 'Copied', 'storedbytes' => 5],
        ]);
        Http::fake([
            'images.example.test/*' => Http::response('12345', 200, ['X-Archive-Source' => 'bucket']),
            'delivery.example.test/*' => Http::response('Not found', 404, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('azure-archive:check-serving')
            ->expectsOutputToContain('mimg_7.jpg: 5 bytes, expected 9')
            ->expectsOutputToContain('fimg_8.jpg: thumbnail: HTTP 404')
            ->assertFailed();
    }

    public function test_measure_with_the_azure_app_reads_the_account_tiers_egress_and_bill(): void
    {
        $this->fakeAzure();

        $this->artisan('azure-archive:measure')
            ->expectsOutputToContain('Standard_GRS')
            ->expectsOutputToContain('1,300.00 GB')
            ->expectsOutputToContain('days.')
            ->expectsOutputToContain('Unavoidable request charges')
            ->expectsOutputToContain('TheLastBillingMonth: total')
            ->assertSuccessful();
    }

    public function test_measure_reports_an_azure_failure(): void
    {
        $this->fakeAzure(['management.azure.com/*storageAccounts/freegle?api-version*' => Http::response(['error' => ['message' => 'denied']], 403)]);

        $this->artisan('azure-archive:measure')->expectsOutputToContain('HTTP 403')->assertFailed();
    }

    public function test_status_shows_progress_and_the_time_left(): void
    {
        $this->fakeAzure();
        DB::table(ArchiveLedger::TABLE)->insert([
            ['source' => 'chat_images', 'sourceid' => 1, 'blobname' => 'mimg_1.jpg', 'status' => 'Copied', 'azurebytes' => 150000, 'storedbytes' => 140000],
            ['source' => 'chat_images', 'sourceid' => 2, 'blobname' => 'mimg_2.jpg', 'status' => 'Superseded', 'azurebytes' => null, 'storedbytes' => null],
        ]);
        (new ArchiveLedger())->saveMeasurement(['chat_images' => 1002], ['chat_images' => 1]);

        $this->artisan('azure-archive:status')
            ->expectsOutputToContain('Superseded')
            ->expectsOutputToContain('1,000 rows left of 1,002')
            ->assertSuccessful();
    }

    public function test_status_asks_for_a_measurement_before_estimating(): void
    {
        $this->fakeAzure();

        $this->artisan('azure-archive:status')->expectsOutputToContain('Run azure-archive:measure')->assertSuccessful();
    }

    public function test_status_says_when_the_bill_cannot_be_read(): void
    {
        $this->fakeAzure(['management.azure.com/*/Microsoft.CostManagement/query*' => Http::response(['error' => ['message' => 'throttled']], 429)]);

        $this->artisan('azure-archive:status')->expectsOutputToContain('The bill could not be read')->assertSuccessful();
    }

    public function test_status_shows_a_latched_stop(): void
    {
        $this->fakeAzure();
        (new ArchiveLedger())->latchStop('Azure charged 0.50');

        $this->artisan('azure-archive:status')->expectsOutputToContain('Stop latched: Azure charged 0.50')->assertSuccessful();
    }

    public function test_clear_stop_with_nothing_latched_says_so(): void
    {
        $this->artisan('azure-archive:status', ['--clear-stop' => true])->expectsOutputToContain('No stop was latched')->assertSuccessful();
    }

    public function test_unreadable_egress_fetches_nothing(): void
    {
        $this->fakeAzure(['management.azure.com/*/Microsoft.Insights/metrics*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('Azure egress figures could not be read')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_a_second_copy_while_one_runs_does_nothing(): void
    {
        $this->fakeAzure();
        $this->archivedAttachment();
        $lock = Cache::lock('azure-archive:copy', 60);
        $lock->get();

        try {
            $this->artisan('azure-archive:copy')->expectsOutputToContain('Another copy is running')->assertSuccessful();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_with_optimisation_on_it_copies_through_the_real_tools(): void
    {
        config(['freegle.azure_archive.optimise' => true]);
        $this->fakeAzure();
        $id = $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->assertSuccessful();

        Storage::disk('images')->assertExists("archive/img_{$id}.jpg");
    }

    public function test_a_bucket_that_is_down_fails_the_run_and_reports_it(): void
    {
        Exceptions::fake();
        $this->fakeAzure();
        $this->archivedAttachment();
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->method('size')->willThrowException(\League\Flysystem\UnableToRetrieveMetadata::fileSize(
            'archive/.probe',
            'unavailable',
            new \Aws\S3\Exception\S3Exception('HeadObject failed', new \Aws\Command('HeadObject'), ['response' => new \GuzzleHttp\Psr7\Response(503)])
        ));
        Storage::set('images', $disk);

        $this->artisan('azure-archive:copy')->expectsOutputToContain('The bucket is unavailable')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
        Exceptions::assertReported(\App\Services\ImageStore\ObjectStoreUnavailable::class);
    }

    public function test_check_serving_with_no_address_does_nothing(): void
    {
        config(['freegle.azure_archive.serving_url' => '']);
        Http::fake();

        $this->artisan('azure-archive:check-serving')->expectsOutputToContain('AZURE_ARCHIVE_SERVING_URL is blank')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_check_serving_catches_no_answer_and_an_unlabelled_answer(): void
    {
        DB::table(ArchiveLedger::TABLE)->insert([
            ['source' => 'chat_images', 'sourceid' => 7, 'blobname' => 'mimg_7.jpg', 'status' => 'Copied', 'storedbytes' => 5],
            ['source' => 'newsfeed_images', 'sourceid' => 8, 'blobname' => 'fimg_8.jpg', 'status' => 'Copied', 'storedbytes' => 5],
            ['source' => 'communityevents_images', 'sourceid' => 9, 'blobname' => 'cimg_9.jpg', 'status' => 'Copied', 'storedbytes' => 5],
        ]);
        Http::fake([
            'images.example.test/archive/mimg_7.jpg' => Http::failedConnection('timed out'),
            'images.example.test/archive/fimg_8.jpg' => Http::response('12345', 200),
            'images.example.test/archive/cimg_9.jpg' => Http::response('12345', 200, ['X-Archive-Source' => 'bucket']),
            'delivery.example.test/*' => Http::failedConnection('refused'),
        ]);

        $this->artisan('azure-archive:check-serving')
            ->expectsOutputToContain('mimg_7.jpg: no answer: timed out')
            ->expectsOutputToContain('fimg_8.jpg: served by an unknown store')
            ->expectsOutputToContain('cimg_9.jpg: thumbnail: no answer: refused')
            ->assertFailed();
    }

    /** @return array<string, array{0: mixed}> */
    public static function badCeilings(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'at the free allowance' => [100], 'a typo' => [800], 'not a number' => ['eighty']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCeilings')]
    public function test_a_ceiling_outside_1_to_95_gb_fetches_nothing(mixed $ceiling): void
    {
        config(['freegle.azure_archive.ceiling_gb' => $ceiling]);
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('AZURE_ARCHIVE_CEILING_GB')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
    }

    public function test_the_switch_is_on_only_for_true_or_1(): void
    {
        foreach (['no' => false, 'off' => false, 'false' => false, 'yes' => false, 'true' => true, '1' => true] as $value => $on) {
            $this->assertSame($on, \App\Support\EnvSetting::flag($value), "AZURE_ARCHIVE_ENABLED={$value}");
        }
    }

    public function test_status_alerts_when_azure_figures_cannot_be_read(): void
    {
        Exceptions::fake();
        $this->fakeAzure(['management.azure.com/*/Microsoft.Insights/metrics*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->artisan('azure-archive:status', ['--report' => true])->assertSuccessful();

        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), 'egress figures cannot be read'));
    }

    public function test_status_alerts_when_the_bill_cannot_be_read(): void
    {
        Exceptions::fake();
        $this->fakeAzure(['management.azure.com/*/Microsoft.CostManagement/query*' => Http::response(['error' => ['message' => 'not supported for this offer']], 400)]);

        $this->artisan('azure-archive:status', ['--report' => true])->assertSuccessful();

        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), 'bill cannot be read'));
    }

    public function test_requeue_gives_final_failures_one_more_fetch(): void
    {
        DB::table(ArchiveLedger::TABLE)->insert(['source' => 'chat_images', 'sourceid' => 3, 'blobname' => 'mimg_3.jpg', 'status' => 'Failed', 'attempts' => 2, 'error' => 'The bytes did not match']);

        $this->artisan('azure-archive:status', ['--requeue-failed' => true])->expectsOutputToContain('Requeued 1 row')->assertSuccessful();

        $row = DB::table(ArchiveLedger::TABLE)->where('blobname', 'mimg_3.jpg')->first();
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringStartsWith(ArchiveLedger::RETRY, $row->error);
    }

    public function test_a_container_that_is_not_there_stops_the_copy_and_alerts(): void
    {
        Exceptions::fake();
        $this->fakeAzure(['freegle.blob.core.windows.net/*' => Http::response('', 404, ['x-ms-error-code' => 'ContainerNotFound'])]);
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('ContainerNotFound')->assertFailed();

        Exceptions::assertReported(fn (AzureArchiveAlert $e) => str_contains($e->getMessage(), 'not there or not public'));
        $this->assertSame(0, DB::table(ArchiveLedger::TABLE)->where('status', 'Missing')->count());
    }

    public function test_egress_figures_azure_has_not_caught_up_with_fetch_nothing(): void
    {
        // The copy fetched 300 MB in the last hours, and Azure shows almost nothing for them.
        DB::table(ArchiveLedger::TABLE)->insert(['source' => 'chat_images', 'sourceid' => 4, 'blobname' => 'mimg_4.jpg', 'status' => 'Copied', 'azurebytes' => 300000000, 'fetchedat' => CarbonImmutable::now('UTC')->subHours(3)]);
        $this->fakeAzure();
        $this->archivedAttachment();

        $this->artisan('azure-archive:copy')->expectsOutputToContain('its figures are behind')->assertFailed();

        $this->assertSame(0, $this->blobFetches);
    }
}
