<?php

namespace Tests\Feature\Monitor;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tests for monitor:deprecated-endpoints.
 *
 * The command reads apiv2's deprecated-endpoint registry (GET /deprecated) for
 * endpoints past their sunset date, checks Loki for hits since sunset, and emails
 * geeks@ a retire/chase report — but only when at least one endpoint is past sunset.
 */
class DeprecatedEndpointsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.apiv2_deprecated_url', 'http://apiv2:8192/deprecated');
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        config()->set('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Carbon::setTestNow('2026-07-09T06:20:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The shape apiv2 GET /deprecated returns (already the Fiber route-pattern form). */
    private function fakeRegistry(): array
    {
        return [
            // Past sunset, still called by an app straggler -> still in use.
            ['endpoint' => 'GET /message/:id', 'sunset' => '2026-07-01'],
            // Past sunset, silent -> safe to retire.
            ['endpoint' => 'GET /activity', 'sunset' => '2026-07-01'],
            // Not yet armed.
            ['endpoint' => 'GET /future', 'sunset' => '2099-01-01'],
        ];
    }

    public function test_emails_retire_and_still_in_use_split(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2:8192/*' => Http::response($this->fakeRegistry(), 200),
            // GET /message/:id — still used by an app straggler.
            'http://loki:3100/*message*' => Http::response([
                'data' => ['result' => [[
                    'stream' => [],
                    'values' => [
                        ['1', '{"endpoint":"GET /message/:id","user_agent":"FreegleApp/1.0.0"}'],
                    ],
                ]]],
            ], 200),
            // GET /activity — silent -> safe to retire.
            'http://loki:3100/*activity*' => Http::response(['data' => ['result' => []]], 200),
        ]);

        $this->artisan('monitor:deprecated-endpoints')
            ->expectsOutput('Report emailed: 1 retire, 1 still in use, 0 could not check.')
            ->assertExitCode(0);

        // Regression guard for a bug no response-mock can catch: `endpoint` is NOT a
        // promoted Loki label (Alloy keeps it in the JSON body), so the query MUST parse
        // the message with `| json` and match the field — a `{endpoint="..."}` stream
        // selector matches nothing and would mark every endpoint falsely retirable.
        Http::assertSent(function ($request) {
            $url = urldecode($request->url());
            if (! str_contains($url, 'loki')) {
                return false;
            }

            return str_contains($url, '| json | endpoint="GET /message/:id"')
                && ! str_contains($url, 'source="deprecated_endpoint", endpoint=');
        });
    }

    public function test_loki_query_failure_is_not_treated_as_retirable(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2:8192/*' => Http::response([
                ['endpoint' => 'GET /message/:id', 'sunset' => '2026-07-01'],
            ], 200),
            // Loki errors on the query -> must NOT count as "safe to retire".
            'http://loki:3100/*' => Http::response('range too long', 400),
        ]);

        $this->artisan('monitor:deprecated-endpoints')
            ->expectsOutput('Report emailed: 0 retire, 0 still in use, 1 could not check.')
            ->assertExitCode(0);
    }

    public function test_no_email_when_nothing_past_sunset(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2:8192/*' => Http::response([
                ['endpoint' => 'GET /future', 'sunset' => '2099-01-01'],
            ], 200),
        ]);

        $this->artisan('monitor:deprecated-endpoints')
            ->expectsOutput('No deprecated endpoints past their sunset date.')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_fails_loudly_when_registry_unreachable(): void
    {
        Mail::fake();
        // apiv2 registry unreachable/misconfigured -> the command must NOT behave like
        // "nothing deprecated"; it exits non-zero (red cron badge) and sends nothing.
        Http::fake(['http://apiv2:8192/*' => Http::response('down', 503)]);

        $this->artisan('monitor:deprecated-endpoints')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }
}
