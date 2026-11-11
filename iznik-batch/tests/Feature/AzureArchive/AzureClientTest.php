<?php

namespace Tests\Feature\AzureArchive;

use App\Services\AzureArchive\AzureClient;
use App\Services\AzureArchive\AzureUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Azure figures the copy is paced by. What matters is that a figure Azure did not give is
 * never read as zero.
 */
class AzureClientTest extends TestCase
{
    public const ACCOUNT = '/subscriptions/sub-1/resourceGroups/rg/providers/Microsoft.Storage/storageAccounts/freegle';

    public static function settings(array $over = []): array
    {
        return array_merge([
            'tenant_id' => 'tenant-1',
            'client_id' => 'client-1',
            'client_secret' => 'secret-1',
            'subscription_id' => 'sub-1',
            'storage_account_id' => self::ACCOUNT,
            'storage_account_name' => 'freegle',
        ], $over);
    }

    public static function token(): array
    {
        return ['login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])];
    }

    /** A metrics answer with one point per hour from $from, each $bytes. */
    public static function egress(CarbonImmutable $from, int $hours, float $bytes): array
    {
        $data = [];

        for ($i = 0; $i < $hours; $i++) {
            $data[] = ['timeStamp' => $from->addHours($i)->format('Y-m-d\TH:i:s\Z'), 'total' => $bytes];
        }

        return ['value' => [['name' => ['value' => 'Egress'], 'timeseries' => [['metadatavalues' => [], 'data' => $data]]]]];
    }

    public static function costAnswer(array $rows, string $costColumn = 'Cost'): array
    {
        return ['properties' => [
            'columns' => [
                ['name' => $costColumn, 'type' => 'Number'],
                ['name' => 'UsageQuantity', 'type' => 'Number'],
                ['name' => 'MeterCategory', 'type' => 'String'],
                ['name' => 'MeterSubCategory', 'type' => 'String'],
                ['name' => 'Meter', 'type' => 'String'],
                ['name' => 'Currency', 'type' => 'String'],
            ],
            'rows' => $rows,
            'nextLink' => null,
        ]];
    }

    public function test_it_names_every_missing_setting_and_calls_nothing(): void
    {
        Http::fake();
        $client = new AzureClient(self::settings(['client_secret' => '', 'subscription_id' => ' ']));

        $this->assertFalse($client->configured());
        $this->assertSame(['AZURE_ARCHIVE_CLIENT_SECRET', 'AZURE_ARCHIVE_SUBSCRIPTION_ID'], $client->missingSettings());

        try {
            $client->costs();
            $this->fail('expected AzureUnavailable');
        } catch (AzureUnavailable $e) {
            $this->assertStringContainsString('AZURE_ARCHIVE_SUBSCRIPTION_ID', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_egress_is_summed_by_hour_across_the_seven_day_chunks(): void
    {
        $to = CarbonImmutable::parse('2026-11-11T12:00:00Z');
        $from = $to->subDays(31);
        Http::fake(self::token() + [
            'management.azure.com/*/providers/Microsoft.Insights/metrics*' => function (Request $request) {
                [$start, $end] = explode('/', $request->data()['timespan'] ?? '');
                $s = CarbonImmutable::parse($start);

                return Http::response(self::egress($s, (int) $s->diffInHours(CarbonImmutable::parse($end)), 1000.0));
            },
        ]);

        $hours = (new AzureClient(self::settings()))->egressByHour($from, $to);

        $this->assertCount(31 * 24, $hours);
        $this->assertSame(31 * 24 * 1000.0, array_sum($hours));
        $this->assertSame('2026-11-11T11:00:00Z', array_key_last($hours));
        // Five chunks of at most seven days each, plus the token.
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'metricnames=Egress')
            && $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_an_hour_azure_has_no_value_for_is_left_out_not_counted_as_zero(): void
    {
        $from = CarbonImmutable::parse('2026-11-11T00:00:00Z');
        Http::fake(self::token() + [
            'management.azure.com/*' => Http::response(['value' => [['timeseries' => [['data' => [
                ['timeStamp' => '2026-11-11T00:00:00Z', 'total' => 5.0],
                ['timeStamp' => '2026-11-11T01:00:00Z'],
                ['timeStamp' => '2026-11-11T02:00:00Z', 'total' => null],
            ]]]]]]),
        ]);

        $hours = (new AzureClient(self::settings()))->egressByHour($from, $from->addHours(3));

        $this->assertSame(['2026-11-11T00:00:00Z' => 5.0], $hours);
    }

    public function test_a_refused_metrics_query_is_unavailable_not_zero(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/*' => Http::response(['error' => ['message' => 'The client does not have authorization']], 403),
        ]);

        $this->expectException(AzureUnavailable::class);
        $this->expectExceptionMessage('HTTP 403: The client does not have authorization');

        $now = CarbonImmutable::now();
        (new AzureClient(self::settings()))->egressByHour($now->subDay(), $now);
    }

    public function test_a_refused_token_is_unavailable(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error_description' => 'AADSTS7000215: Invalid client secret'], 401)]);

        $this->expectException(AzureUnavailable::class);
        $this->expectExceptionMessage('Invalid client secret');

        (new AzureClient(self::settings()))->costs();
    }

    public function test_costs_are_read_by_meter_and_bandwidth_is_picked_out(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/*/Microsoft.CostManagement/query*' => Http::response(self::costAnswer([
                [0.0, 42.5, 'Bandwidth', 'Rtn Preference: MGN', 'Standard Data Transfer Out', 'GBP'],
                [0.0, 0.2, 'Bandwidth', '', 'Inter-Region Data Transfer Out', 'GBP'],
                [16.03, 1010.0, 'Storage', 'General Block Blob v2', 'Hot LRS Data Stored', 'GBP'],
                [0.12, 400.0, 'Storage', 'General Block Blob v2', 'Hot Read Operations', 'GBP'],
            ])),
        ]);

        $costs = (new AzureClient(self::settings()))->costs();

        $this->assertSame('GBP', $costs->currency);
        $this->assertSame(0.0, $costs->bandwidthCost());
        $this->assertEqualsWithDelta(42.7, $costs->bandwidthGb(), 0.0001);
        $this->assertEqualsWithDelta(16.15, $costs->total(), 0.0001);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && ($r->data()['timeframe'] ?? null) === 'BillingMonthToDate'
            && ($r->data()['type'] ?? null) === 'ActualCost');
    }

    public function test_an_offer_that_names_the_cost_column_pretaxcost_is_asked_again(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/*' => function (Request $request) {
                $column = $request->data()['dataset']['aggregation']['totalCost']['name'] ?? '';

                return $column === 'Cost'
                    ? Http::response(['error' => ['message' => 'Invalid dataset aggregation column Cost']], 400)
                    : Http::response(self::costAnswer([[1.5, 120.0, 'Bandwidth', '', 'Standard Data Transfer Out', 'GBP']], 'PreTaxCost'));
            },
        ]);

        $costs = (new AzureClient(self::settings()))->costs('TheLastBillingMonth');

        $this->assertSame(1.5, $costs->bandwidthCost());
        $this->assertSame('TheLastBillingMonth', $costs->timeframe);
    }

    public function test_a_cost_answer_without_the_columns_is_unavailable(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/*' => Http::response(['properties' => ['columns' => [['name' => 'Something']], 'rows' => [[1]]]]),
        ]);

        $this->expectException(AzureUnavailable::class);

        (new AzureClient(self::settings()))->costs();
    }

    public function test_cost_pages_are_followed(): void
    {
        $first = self::costAnswer([[0.0, 10.0, 'Bandwidth', '', 'Standard Data Transfer Out', 'GBP']]);
        $first['properties']['nextLink'] = 'https://management.azure.com/next-page';
        Http::fake(self::token() + [
            'management.azure.com/next-page' => Http::response(self::costAnswer([[0.5, 7.0, 'Bandwidth', '', 'Standard Data Transfer Out', 'GBP']])),
            'management.azure.com/*' => Http::response($first),
        ]);

        $costs = (new AzureClient(self::settings()))->costs();

        $this->assertSame(17.0, $costs->bandwidthGb());
        $this->assertSame(0.5, $costs->bandwidthCost());
    }

    public function test_the_storage_account_is_found_by_name_when_its_id_is_not_set(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/subscriptions/sub-1/providers/Microsoft.Storage/storageAccounts*' => Http::response(['value' => [
                ['name' => 'other', 'id' => '/x/other'],
                ['name' => 'freegle', 'id' => self::ACCOUNT],
            ]]),
            'management.azure.com/*' => Http::response([
                'name' => 'freegle', 'location' => 'northeurope', 'sku' => ['name' => 'Standard_RAGRS'],
                'kind' => 'StorageV2', 'properties' => ['accessTier' => 'Hot'],
            ]),
        ]);

        $account = (new AzureClient(self::settings(['storage_account_id' => ''])))->storageAccount();

        $this->assertSame(['name' => 'freegle', 'location' => 'northeurope', 'sku' => 'Standard_RAGRS', 'kind' => 'StorageV2', 'accessTier' => 'Hot'], $account);
    }

    public function test_a_storage_account_not_in_the_subscription_is_unavailable(): void
    {
        Http::fake(self::token() + ['management.azure.com/*' => Http::response(['value' => []])]);

        $this->expectException(AzureUnavailable::class);
        $this->expectExceptionMessage('AZURE_ARCHIVE_STORAGE_ACCOUNT_ID');

        (new AzureClient(self::settings(['storage_account_id' => ''])))->storageAccountId();
    }

    public function test_blobs_are_reported_by_tier(): void
    {
        Http::fake(self::token() + [
            'management.azure.com/*' => function (Request $request) {
                $metric = $request->data()['metricnames'];
                $value = $metric === 'BlobCapacity' ? 1.2e12 : 1.5e7;

                return Http::response(['value' => [['timeseries' => [
                    ['metadatavalues' => [['name' => ['value' => 'tier'], 'value' => 'Hot']], 'data' => [
                        ['timeStamp' => '2026-11-10T00:00:00Z', 'average' => $value / 2],
                        ['timeStamp' => '2026-11-10T01:00:00Z', 'average' => $value],
                    ]],
                ]]]]);
            },
        ]);

        $tiers = (new AzureClient(self::settings()))->blobsByTier();

        $this->assertSame(['Hot' => ['bytes' => 1.2e12, 'count' => 1.5e7]], $tiers);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), "Tier eq '*'")
            && str_contains($r->url(), '/blobServices/default/'));
    }
}
