<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ReachService::driveMetrics - the one-call road-miles lookup the digest and
 * matched-posts emails use so their distances match the site's road miles.
 */
class ReachDriveMetricsTest extends TestCase
{
    public function testMapsResultsBackToCallerKeys(): void
    {
        Http::fake([
            '*/v1/drive-metrics*' => Http::response([
                'results' => [
                    ['id' => 0, 'mins' => 10.0, 'miles' => 3.2],
                    ['id' => 1, 'mins' => null, 'miles' => null], // unreachable
                ],
            ]),
        ]);

        $out = app(ReachService::class)->driveMetrics(53.8, -2.6, [
            12345 => [53.79, -2.54],
            67890 => [58.0, -6.0],
        ]);

        $this->assertSame([12345 => 3.2], $out);
    }

    public function testFailureIsQuietAndEmpty(): void
    {
        Http::fake(['*/v1/drive-metrics*' => Http::response('not configured', 503)]);

        $this->assertSame([], app(ReachService::class)->driveMetrics(53.8, -2.6, [1 => [53.79, -2.54]]));
        $this->assertSame([], app(ReachService::class)->driveMetrics(53.8, -2.6, []));
    }
}
