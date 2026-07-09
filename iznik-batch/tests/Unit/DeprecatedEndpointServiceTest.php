<?php

namespace Tests\Unit;

use App\Services\DeprecatedEndpointService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeprecatedEndpointServiceTest extends TestCase
{
    private function fakeSpec(): array
    {
        return [
            'paths' => [
                '/message/{id}' => [
                    'get' => ['deprecated' => true, 'x-sunset' => '2026-07-01'],
                    'delete' => ['deprecated' => true, 'x-sunset' => '2099-01-01'], // future
                ],
                '/activity' => [
                    'get' => ['deprecated' => true, 'x-sunset' => '2026-06-15'],
                ],
                '/deprecated-no-sunset' => [
                    'get' => ['deprecated' => true], // no x-sunset: not armed
                ],
                '/live' => [
                    'get' => ['summary' => 'not deprecated'],
                ],
            ],
        ];
    }

    public function test_returns_only_deprecated_past_sunset_operations(): void
    {
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/swagger.json');
        Http::fake(['http://apiv2/*' => Http::response($this->fakeSpec(), 200)]);

        $svc = new DeprecatedEndpointService();
        $out = $svc->pastSunset(Carbon::parse('2026-07-09'));

        // GET /message/{id} (sunset 07-01, passed) and GET /activity (06-15,
        // passed). NOT DELETE /message/{id} (future), GET /deprecated-no-sunset
        // (not armed), or GET /live (not deprecated).
        $keys = array_map(fn ($e) => $e['method'].' '.$e['path'], $out);
        sort($keys);
        $this->assertSame(['GET /activity', 'GET /message/{id}'], $keys);

        $msg = collect($out)->firstWhere('path', '/message/{id}');
        $this->assertSame('2026-07-01', $msg['sunset']);
        // The endpoint label matches the Go middleware's route-pattern form,
        // with Fiber ":id" params (see loggedEndpoint()).
        $this->assertSame('GET /message/:id', $msg['logged_endpoint']);
    }

    public function test_returns_empty_when_spec_unreachable(): void
    {
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/swagger.json');
        Http::fake(['*' => Http::response('nope', 503)]);

        $svc = new DeprecatedEndpointService();
        $this->assertSame([], $svc->pastSunset(Carbon::parse('2026-07-09')));
    }
}
