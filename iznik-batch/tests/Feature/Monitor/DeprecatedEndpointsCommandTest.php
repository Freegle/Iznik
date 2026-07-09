<?php

namespace Tests\Feature\Monitor;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Tests for monitor:deprecated-endpoints.
 *
 * The command reads the apiv2 spec for deprecated endpoints past their x-sunset
 * date, checks Loki for hits since sunset, and emails geeks@ a retire/chase
 * report — but only when at least one endpoint is past sunset.
 */
class DeprecatedEndpointsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/swagger.json');
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        config()->set('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Carbon::setTestNow('2026-07-09T06:20:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeSpec(): array
    {
        return [
            'paths' => [
                // Past sunset, still called by an app straggler -> still in use.
                '/message/{id}' => ['get' => ['deprecated' => true, 'x-sunset' => '2026-07-01']],
                // Past sunset, silent -> safe to retire.
                '/activity' => ['get' => ['deprecated' => true, 'x-sunset' => '2026-07-01']],
                // Not yet armed.
                '/future' => ['get' => ['deprecated' => true, 'x-sunset' => '2099-01-01']],
            ],
        ];
    }

    public function test_emails_retire_and_still_in_use_split(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2/*' => Http::response($this->fakeSpec(), 200),
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
    }

    public function test_loki_query_failure_is_not_treated_as_retirable(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2/*' => Http::response([
                'paths' => ['/message/{id}' => ['get' => ['deprecated' => true, 'x-sunset' => '2026-07-01']]],
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
            'http://apiv2/*' => Http::response([
                'paths' => ['/future' => ['get' => ['deprecated' => true, 'x-sunset' => '2099-01-01']]],
            ], 200),
        ]);

        $this->artisan('monitor:deprecated-endpoints')
            ->expectsOutput('No deprecated endpoints past their sunset date.')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_fails_loudly_when_spec_unreachable(): void
    {
        Mail::fake();
        // apiv2 spec unreachable/misconfigured -> the command must NOT behave like
        // "nothing deprecated"; it exits non-zero (red cron badge) and sends nothing.
        Http::fake(['http://apiv2/*' => Http::response('down', 503)]);

        $this->artisan('monitor:deprecated-endpoints')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }
}
