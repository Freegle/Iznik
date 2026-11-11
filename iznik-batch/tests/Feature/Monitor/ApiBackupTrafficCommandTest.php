<?php

namespace Tests\Feature\Monitor;

use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for monitor:api-backup-traffic.
 *
 * The command asks Loki how many member API requests each apiv2 node served in
 * each hour of the last day, takes the busiest node as the active one, and raises
 * a Sentry error for any hour in which another node served more than the allowed
 * share. A failed query or an empty sample is an error too, never a pass.
 */
class ApiBackupTrafficCommandTest extends TestCase
{
    private const END = '2026-10-11T05:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        config()->set('freegle.api_backup_traffic.window_hours', 24);
        config()->set('freegle.api_backup_traffic.max_hourly_share', 0.10);
        config()->set('freegle.api_backup_traffic.min_hourly_requests', 1000);
        Carbon::setTestNow('2026-10-11T05:40:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A Loki matrix response: one series per host, one point per hour of the
     * window, the oldest first. $perHour maps host => fn(int $hoursAgo): int.
     */
    private function lokiMatrix(array $perHour): array
    {
        $end = Carbon::parse(self::END)->getTimestamp();
        $result = [];
        foreach ($perHour as $host => $count) {
            $values = [];
            for ($ago = 23; $ago >= 0; $ago--) {
                $n = $count($ago);
                if ($n > 0) {
                    // Loki leaves out the points of an hour with nothing in it.
                    $values[] = [$end - $ago * 3600, (string) $n];
                }
            }
            $result[] = ['metric' => ['hostname' => $host], 'values' => $values];
        }

        return ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]];
    }

    private function fakeLoki(array $body, int $status = 200): void
    {
        Http::fake(['http://loki:3100/*' => Http::response($body, $status)]);
    }

    /** Records what reaches Sentry; checked after the test in assertPostConditions(). */
    private ?object $sentry = null;

    private array $sentryExpected = [0, ''];

    private function expectSentry(int $times, string $contains = ''): void
    {
        // A recorder rather than a mock: a mock whose arguments do not match
        // throws inside the command, the console turns that into exit code 1,
        // and a test expecting 1 then passes for the wrong reason.
        $this->sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = [(string) $message, (string) $severity];
            }
        };
        $this->sentryExpected = [$times, $contains];
        $this->app->instance('sentry', $this->sentry);
    }

    protected function assertPostConditions(): void
    {
        if ($this->sentry !== null) {
            [$times, $contains] = $this->sentryExpected;
            $this->assertCount($times, $this->sentry->calls, 'Sentry received: '.json_encode($this->sentry->calls));
            foreach ($this->sentry->calls as [$message, $severity]) {
                $this->assertStringContainsString($contains, $message);
                $this->assertSame((string) \Sentry\Severity::error(), $severity);
            }
        }

        parent::assertPostConditions();
    }

    public function test_all_requests_on_one_node_passes(): void
    {
        $this->fakeLoki($this->lokiMatrix([
            'db-3' => fn ($ago) => 100000,
        ]));
        $this->expectSentry(0);

        $this->artisan('monitor:api-backup-traffic')
            ->expectsOutputToContain('API traffic OK: db-3 served the members\' requests in all 24 judged hours')
            ->assertExitCode(0);
    }

    public function test_backup_serving_for_hours_raises_sentry_error(): void
    {
        // The shape seen in production: the backup kept a fifth to a third of the
        // traffic for several hours after the active node restarted.
        $this->fakeLoki($this->lokiMatrix([
            'db-3' => fn ($ago) => 100000,
            'db-2' => fn ($ago) => ($ago >= 5 && $ago <= 9) ? 30000 : 0,
        ]));
        $this->expectSentry(1, 'db-2 served over 10% of member API requests in 5 of the last 24 hours (peak 23%');

        $this->artisan('monitor:api-backup-traffic')
            ->expectsOutputToContain('db-3 is the active node')
            ->assertExitCode(1);
    }

    public function test_a_deploy_failover_minute_does_not_alert(): void
    {
        // A minute or two on the backup while the active node restarts.
        $this->fakeLoki($this->lokiMatrix([
            'db-3' => fn ($ago) => 100000,
            'db-2' => fn ($ago) => $ago === 3 ? 3000 : 0,
        ]));
        $this->expectSentry(0);

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(0);
    }

    public function test_the_busiest_node_is_taken_as_active(): void
    {
        // If the active node changes, the check follows it without an edit: here
        // db-2 is the busy one and db-3 is the node that should not be serving.
        $this->fakeLoki($this->lokiMatrix([
            'db-2' => fn ($ago) => 100000,
            'db-3' => fn ($ago) => $ago < 2 ? 50000 : 0,
        ]));
        $this->expectSentry(1, 'db-3 served over 10%');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }

    public function test_quiet_hours_are_not_judged(): void
    {
        // Half of 600 requests is not a share worth an alert.
        $this->fakeLoki($this->lokiMatrix([
            'db-3' => fn ($ago) => $ago === 0 ? 300 : 100000,
            'db-2' => fn ($ago) => $ago === 0 ? 300 : 0,
        ]));
        $this->expectSentry(0);

        $this->artisan('monitor:api-backup-traffic')
            ->expectsOutputToContain('all 23 judged hours')
            ->assertExitCode(0);
    }

    public function test_no_traffic_is_an_error_not_a_pass(): void
    {
        $this->fakeLoki(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]]);
        $this->expectSentry(1, 'so the check could not run');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }

    public function test_loki_failure_is_an_error_not_a_pass(): void
    {
        $this->fakeLoki(['message' => 'too many outstanding requests'], 429);
        $this->expectSentry(1, 'Could not query Loki');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }

    public function test_a_non_metric_response_is_an_error_not_a_pass(): void
    {
        // A log-line answer has no counts in it; reading it as none would pass.
        $this->fakeLoki(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => []]]);
        $this->expectSentry(1, 'Could not query Loki');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }

    public function test_asks_for_hourly_member_requests_over_the_day(): void
    {
        $this->fakeLoki($this->lokiMatrix(['db-3' => fn ($ago) => 100000]));
        $this->expectSentry(0);

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            $query = $request->data();

            // Health checks carry no member address and every node gets them;
            // they must be left out or each node always has a baseline share.
            return str_contains($query['query'], '!= `"ip":""`')
                && str_contains($query['query'], 'sum by (hostname)')
                && (int) $query['step'] === 3600
                && (int) $query['end'] === Carbon::parse(self::END)->getTimestamp() * 1_000_000_000
                && (int) $query['start'] === Carbon::parse(self::END)->subHours(23)->getTimestamp() * 1_000_000_000;
        });
    }

    public function test_a_network_error_is_an_error_not_a_pass(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $this->expectSentry(1, 'Could not query Loki');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }

    public function test_no_loki_url_is_an_error_not_a_pass(): void
    {
        config()->set('freegle.loki.query_url', '');
        $this->expectSentry(1, 'Could not query Loki');

        $this->artisan('monitor:api-backup-traffic')->assertExitCode(1);
    }
}
