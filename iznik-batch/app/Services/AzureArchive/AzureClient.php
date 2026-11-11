<?php

namespace App\Services\AzureArchive;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Read-only access to the Azure figures the copy is paced by, through the management API with a
 * client-credentials app registration (Monitoring Reader on the storage account, Cost Management
 * Reader and Billing Reader on the subscription). It can read; it can change nothing.
 *
 * Every call here is free: metric queries are free up to a million a month and Cost Management
 * queries have no charge. Every failure is AzureUnavailable, never a zero.
 */
class AzureClient
{
    private const ARM = 'https://management.azure.com';

    private const LOGIN = 'https://login.microsoftonline.com';

    private const METRICS_API = '2023-10-01';

    private const STORAGE_API = '2023-05-01';

    private const COST_API = '2023-11-01';

    /** The metrics API is asked for at most this many days at a time. */
    private const METRIC_CHUNK_DAYS = 7;

    private ?string $token = null;

    private ?string $accountId = null;

    /** @param  array<string, mixed>  $settings  config('freegle.azure_archive') */
    public function __construct(private readonly array $settings)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('freegle.azure_archive', []));
    }

    /** @return list<string> the settings still blank, by environment variable */
    public function missingSettings(): array
    {
        $names = [
            'tenant_id' => 'AZURE_ARCHIVE_TENANT_ID',
            'client_id' => 'AZURE_ARCHIVE_CLIENT_ID',
            'client_secret' => 'AZURE_ARCHIVE_CLIENT_SECRET',
            'subscription_id' => 'AZURE_ARCHIVE_SUBSCRIPTION_ID',
        ];

        return array_values(array_filter(
            $names,
            fn (string $env, string $key) => trim((string) ($this->settings[$key] ?? '')) === '',
            ARRAY_FILTER_USE_BOTH
        ));
    }

    public function configured(): bool
    {
        return $this->missingSettings() === [];
    }

    /**
     * The storage account's resource id: the configured one, or the one in the subscription with
     * the configured name.
     */
    public function storageAccountId(): string
    {
        if ($this->accountId !== null) {
            return $this->accountId;
        }

        $configured = trim((string) ($this->settings['storage_account_id'] ?? ''));

        if ($configured !== '') {
            return $this->accountId = $configured;
        }

        $name = (string) ($this->settings['storage_account_name'] ?? 'freegle');
        $list = $this->get('/subscriptions/' . $this->subscription() . '/providers/Microsoft.Storage/storageAccounts', [
            'api-version' => self::STORAGE_API,
        ]);

        foreach ((array) ($list['value'] ?? []) as $account) {
            if (strcasecmp((string) ($account['name'] ?? ''), $name) === 0 && ! empty($account['id'])) {
                return $this->accountId = (string) $account['id'];
            }
        }

        throw new AzureUnavailable("No storage account called {$name} is visible in the subscription; set AZURE_ARCHIVE_STORAGE_ACCOUNT_ID");
    }

    /**
     * @return array{name: string, location: string, sku: string, kind: string, accessTier: string}
     */
    public function storageAccount(): array
    {
        $a = $this->get($this->storageAccountId(), ['api-version' => self::STORAGE_API]);

        return [
            'name' => (string) ($a['name'] ?? ''),
            'location' => (string) ($a['location'] ?? ''),
            'sku' => (string) ($a['sku']['name'] ?? ''),
            'kind' => (string) ($a['kind'] ?? ''),
            'accessTier' => (string) ($a['properties']['accessTier'] ?? ''),
        ];
    }

    /**
     * The storage account's egress, hour by hour, from Azure Monitor: every byte it sent, members'
     * views and the copy alike.
     *
     * @return array<string, float> ISO hour => bytes, oldest first
     */
    public function egressByHour(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];

        for ($start = $from; $start < $to; $start = $start->addDays(self::METRIC_CHUNK_DAYS)) {
            $end = $start->addDays(self::METRIC_CHUNK_DAYS);

            if ($end > $to) {
                $end = $to;
            }

            $series = $this->metric($this->storageAccountId(), 'Egress', 'Total', 'PT1H', $start, $end);

            foreach ($series as $points) {
                foreach ($points as $hour => $value) {
                    $out[$hour] = ($out[$hour] ?? 0.0) + $value;
                }
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Bytes and blob count by access tier, the latest value Azure Monitor has (it reports these
     * about once an hour).
     *
     * @return array<string, array{bytes: float, count: float}>
     */
    public function blobsByTier(): array
    {
        $now = CarbonImmutable::now('UTC');
        $resource = $this->storageAccountId() . '/blobServices/default';
        $out = [];

        foreach (['BlobCapacity' => 'bytes', 'BlobCount' => 'count'] as $metric => $field) {
            foreach ($this->metric($resource, $metric, 'Average', 'PT1H', $now->subDays(2), $now, "Tier eq '*'") as $tier => $points) {
                if ($points === []) {
                    continue;
                }

                $out[$tier === '' ? 'all' : $tier][$field] = (float) end($points);
            }
        }

        foreach ($out as $tier => $values) {
            $out[$tier] = ['bytes' => $values['bytes'] ?? 0.0, 'count' => $values['count'] ?? 0.0];
        }

        return $out;
    }

    /**
     * Actual cost by meter for a Cost Management timeframe: BillingMonthToDate or
     * TheLastBillingMonth. Cost Management's figures lag usage by up to a day.
     */
    public function costs(string $timeframe = 'BillingMonthToDate'): CostSummary
    {
        $path = '/subscriptions/' . $this->subscription() . '/providers/Microsoft.CostManagement/query';
        $grouping = [
            ['type' => 'Dimension', 'name' => 'MeterCategory'],
            ['type' => 'Dimension', 'name' => 'MeterSubCategory'],
            ['type' => 'Dimension', 'name' => 'Meter'],
        ];

        // Offers name the cost column differently: Cost on most, PreTaxCost on older pay-as-you-go.
        $lastError = null;

        foreach (['Cost', 'PreTaxCost'] as $costColumn) {
            $body = [
                'type' => 'ActualCost',
                'timeframe' => $timeframe,
                'dataset' => [
                    'granularity' => 'None',
                    'aggregation' => [
                        'totalCost' => ['name' => $costColumn, 'function' => 'Sum'],
                        'totalQuantity' => ['name' => 'UsageQuantity', 'function' => 'Sum'],
                    ],
                    'grouping' => $grouping,
                ],
            ];

            try {
                return $this->parseCosts($timeframe, $this->postAll($path, ['api-version' => self::COST_API], $body));
            } catch (AzureUnavailable $e) {
                $lastError = $e;

                if ($e->getCode() !== 400) {
                    break;
                }
            }
        }

        throw $lastError ?? new AzureUnavailable('Cost Management query failed');
    }

    /**
     * @param  array{columns: list<array{name: string}>, rows: list<list<mixed>>}  $result
     */
    private function parseCosts(string $timeframe, array $result): CostSummary
    {
        $index = [];

        foreach ($result['columns'] as $i => $column) {
            $index[strtolower((string) ($column['name'] ?? ''))] = $i;
        }

        $find = function (array $names) use ($index): ?int {
            foreach ($names as $name) {
                if (isset($index[strtolower($name)])) {
                    return $index[strtolower($name)];
                }
            }

            return null;
        };

        $cost = $find(['totalCost', 'Cost', 'PreTaxCost', 'CostUSD']);
        $quantity = $find(['totalQuantity', 'UsageQuantity']);
        $category = $find(['MeterCategory']);
        $subcategory = $find(['MeterSubCategory']);
        $meter = $find(['Meter']);
        $currency = $find(['Currency', 'BillingCurrency']);

        if ($cost === null || $category === null || $meter === null) {
            throw new AzureUnavailable('Cost Management answered without the expected columns: ' . implode(', ', array_keys($index)));
        }

        $rows = [];
        $currencyName = '';

        foreach ($result['rows'] as $row) {
            $rows[] = [
                'category' => (string) ($row[$category] ?? ''),
                'subcategory' => $subcategory === null ? '' : (string) ($row[$subcategory] ?? ''),
                'meter' => (string) ($row[$meter] ?? ''),
                'cost' => (float) ($row[$cost] ?? 0),
                'quantity' => $quantity === null ? 0.0 : (float) ($row[$quantity] ?? 0),
            ];

            if ($currency !== null && ! empty($row[$currency])) {
                $currencyName = (string) $row[$currency];
            }
        }

        return new CostSummary($timeframe, $currencyName, $rows);
    }

    /**
     * One metric's points, by dimension value ('' when not split).
     *
     * @return array<string, array<string, float>> dimension => [ISO time => value]
     */
    private function metric(string $resource, string $name, string $aggregation, string $interval, CarbonImmutable $from, CarbonImmutable $to, ?string $filter = null): array
    {
        $query = [
            'api-version' => self::METRICS_API,
            'metricnames' => $name,
            'aggregation' => $aggregation,
            'interval' => $interval,
            'timespan' => $from->utc()->format('Y-m-d\TH:i:s\Z') . '/' . $to->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($filter !== null) {
            $query['$filter'] = $filter;
        }

        $answer = $this->get($resource . '/providers/Microsoft.Insights/metrics', $query);
        $metric = $answer['value'][0] ?? null;

        if (! is_array($metric) || ! isset($metric['timeseries']) || ! is_array($metric['timeseries'])) {
            throw new AzureUnavailable("Azure Monitor answered {$name} without a time series");
        }

        $field = strtolower($aggregation);
        $out = [];

        foreach ($metric['timeseries'] as $series) {
            $dimension = '';

            foreach ((array) ($series['metadatavalues'] ?? []) as $meta) {
                $dimension = (string) ($meta['value'] ?? '');
            }

            $points = [];

            foreach ((array) ($series['data'] ?? []) as $point) {
                // An hour with nothing recorded has no value at all: it is not a zero we were told.
                if (! array_key_exists($field, $point) || $point[$field] === null) {
                    continue;
                }

                $points[CarbonImmutable::parse((string) $point['timeStamp'])->utc()->format('Y-m-d\TH:i:s\Z')] = (float) $point[$field];
            }

            $out[$dimension] = $points;
        }

        return $out;
    }

    private function subscription(): string
    {
        $id = trim((string) ($this->settings['subscription_id'] ?? ''));

        if ($id === '') {
            throw new AzureUnavailable('AZURE_ARCHIVE_SUBSCRIPTION_ID is not set');
        }

        return $id;
    }

    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if (! $this->configured()) {
            throw new AzureUnavailable('Azure credentials are not set: ' . implode(', ', $this->missingSettings()));
        }

        $response = $this->send(fn () => Http::asForm()->timeout(30)->post(
            self::LOGIN . '/' . rawurlencode((string) $this->settings['tenant_id']) . '/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $this->settings['client_id'],
                'client_secret' => (string) $this->settings['client_secret'],
                'scope' => self::ARM . '/.default',
            ]
        ), 'token');

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new AzureUnavailable('Azure gave no access token');
        }

        return $this->token = $token;
    }

    /** @param  array<string, string>  $query */
    private function get(string $path, array $query): array
    {
        $token = $this->token();
        $response = $this->send(fn () => Http::withToken($token)->acceptJson()->timeout(60)->get(self::ARM . $path, $query), $path);

        return (array) $response->json();
    }

    /**
     * POST a Cost Management query and follow its nextLink pages.
     *
     * @param  array<string, string>  $query
     * @return array{columns: list<array{name: string}>, rows: list<list<mixed>>}
     */
    private function postAll(string $path, array $query, array $body): array
    {
        $token = $this->token();
        $url = self::ARM . $path . '?' . http_build_query($query);
        $columns = null;
        $rows = [];

        for ($page = 0; $url !== null && $page < 20; $page++) {
            $target = $url;
            $response = $this->send(fn () => Http::withToken($token)->acceptJson()->timeout(60)->post($target, $body), $path);
            $properties = (array) $response->json('properties', []);

            if (! isset($properties['columns'], $properties['rows']) || ! is_array($properties['rows'])) {
                throw new AzureUnavailable('Cost Management answered without columns and rows');
            }

            $columns ??= (array) $properties['columns'];
            $rows = array_merge($rows, $properties['rows']);
            $url = empty($properties['nextLink']) ? null : (string) $properties['nextLink'];
        }

        return ['columns' => $columns ?? [], 'rows' => $rows];
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call, string $what): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new AzureUnavailable("Azure did not answer {$what}: " . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('error.message') ?? $response->json('error_description') ?? '');

            throw new AzureUnavailable(
                "Azure answered {$what} with HTTP {$response->status()}" . ($message === '' ? '' : ": {$message}"),
                $response->status()
            );
        }

        return $response;
    }
}
