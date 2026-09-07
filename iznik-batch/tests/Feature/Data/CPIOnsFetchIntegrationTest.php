<?php

namespace Tests\Feature\Data;

use App\Services\CPIService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Live check against the ONS API.
 *
 * This is the one test that leaves the machine: it fetches the real CPI series and
 * checks the shape we parse has not drifted. It lives in the Integration suite, which
 * the status API and CI only run when asked for (testsuite=Integration), so a DNS
 * timeout or an ONS outage cannot turn an unrelated PR red. Everything else about
 * CPIService is covered with Http::fake in tests/Unit/Services/CPIServiceTest.php.
 */
class CPIOnsFetchIntegrationTest extends TestCase
{
    protected CPIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CPIService();
        Mail::fake();
    }

    public function test_real_ons_api_fetch(): void
    {
        $result = $this->service->fetchAndStoreCPI();

        $this->assertTrue(
            $result['success'],
            'Failed to fetch from ONS API: ' . ($result['message'] ?? 'unknown error')
        );

        // Should have data for 2011 and recent years.
        $this->assertArrayHasKey(2011, $result['data']);
        $this->assertEquals(93.4, $result['data'][2011]);

        // Allow up to 2 years behind the current year: ONS publishes annually and late.
        $currentYear = (int) date('Y');
        $latestYear = max(array_keys($result['data']));
        $this->assertGreaterThanOrEqual($currentYear - 2, $latestYear);
    }
}
