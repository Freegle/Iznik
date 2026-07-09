<?php

namespace Tests\Unit;

use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LokiServiceQueryTest extends TestCase
{
    public function test_query_range_returns_flattened_stream_values(): void
    {
        config()->set('freegle.loki.query_url', 'http://loki:3100');

        Http::fake([
            'http://loki:3100/loki/api/v1/query_range*' => Http::response([
                'status' => 'success',
                'data' => [
                    'result' => [
                        [
                            'stream' => ['source' => 'deprecated_endpoint'],
                            'values' => [
                                ['1700000000000000000', '{"endpoint":"GET /foo","user_agent":"UA1"}'],
                                ['1700000001000000000', '{"endpoint":"GET /foo","user_agent":"UA2"}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $svc = new LokiService();
        $rows = $svc->queryRange(
            '{source="deprecated_endpoint"} |= "GET /foo"',
            Carbon::parse('2026-07-01T00:00:00Z'),
            Carbon::parse('2026-07-15T00:00:00Z')
        );

        $this->assertCount(2, $rows);
        $this->assertSame('UA1', $rows[0]['user_agent']);
        $this->assertSame('GET /foo', $rows[1]['endpoint']);
    }

    public function test_query_range_returns_empty_on_non_200(): void
    {
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        Http::fake(['*' => Http::response('boom', 500)]);

        $svc = new LokiService();
        $rows = $svc->queryRange('{source="deprecated_endpoint"}', now()->subDay(), now());

        $this->assertSame([], $rows);
    }

    public function test_query_range_returns_empty_when_no_url_configured(): void
    {
        config()->set('freegle.loki.query_url', null);

        $svc = new LokiService();
        $rows = $svc->queryRange('{source="deprecated_endpoint"}', now()->subDay(), now());

        $this->assertSame([], $rows);
    }
}
