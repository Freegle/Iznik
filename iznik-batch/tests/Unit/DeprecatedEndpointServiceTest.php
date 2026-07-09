<?php

namespace Tests\Unit;

use App\Services\DeprecatedEndpointService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeprecatedEndpointServiceTest extends TestCase
{
    /** The shape apiv2 GET /deprecated returns. */
    private function fakeRegistry(): array
    {
        return [
            ['endpoint' => 'GET /activity', 'sunset' => '2026-06-15'],       // past
            ['endpoint' => 'DELETE /message/:id', 'sunset' => '2026-07-01'], // past
            ['endpoint' => 'POST /team', 'sunset' => '2099-01-01'],          // future -> excluded
        ];
    }

    public function test_returns_only_past_sunset_entries(): void
    {
        config()->set('freegle.apiv2_deprecated_url', 'http://apiv2:8192/deprecated');
        Http::fake(['http://apiv2:8192/*' => Http::response($this->fakeRegistry(), 200)]);

        $svc = new DeprecatedEndpointService();
        $out = $svc->pastSunset(Carbon::parse('2026-07-09'));

        $keys = array_map(fn ($e) => $e['endpoint'], $out);
        sort($keys);
        $this->assertSame(['DELETE /message/:id', 'GET /activity'], $keys);

        $msg = collect($out)->firstWhere('endpoint', 'DELETE /message/:id');
        $this->assertSame('2026-07-01', $msg['sunset']);
        // apiv2 already emits the Fiber form, so logged_endpoint == endpoint.
        $this->assertSame('DELETE /message/:id', $msg['logged_endpoint']);
    }

    public function test_returns_null_when_registry_unreachable(): void
    {
        config()->set('freegle.apiv2_deprecated_url', 'http://apiv2:8192/deprecated');
        Http::fake(['*' => Http::response('nope', 503)]);

        $svc = new DeprecatedEndpointService();
        // null (not []) so the command can surface "apiv2 unreachable" loudly.
        $this->assertNull($svc->pastSunset(Carbon::parse('2026-07-09')));
    }
}
