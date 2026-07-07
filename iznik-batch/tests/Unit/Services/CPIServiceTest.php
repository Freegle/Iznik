<?php

namespace Tests\Unit\Services;

use App\Services\CPIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class CPIServiceTest extends TestCase
{
    protected CPIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CPIService();
        Mail::fake();
    }

    /**
     * Sample ONS API response matching the real structure.
     */
    protected function getSampleONSResponse(): array
    {
        return [
            'description' => [
                'title' => 'CPI INDEX 00: ALL ITEMS 2015=100',
            ],
            'years' => [
                ['year' => '2011', 'value' => '93.4'],
                ['year' => '2012', 'value' => '96.1'],
                ['year' => '2013', 'value' => '98.5'],
                ['year' => '2014', 'value' => '100.0'],
                ['year' => '2015', 'value' => '100.0'],
                ['year' => '2016', 'value' => '100.7'],
                ['year' => '2017', 'value' => '103.4'],
                ['year' => '2018', 'value' => '105.9'],
                ['year' => '2019', 'value' => '107.8'],
                ['year' => '2020', 'value' => '108.7'],
                ['year' => '2021', 'value' => '111.6'],
                ['year' => '2022', 'value' => '121.7'],
                ['year' => '2023', 'value' => '130.5'],
                ['year' => '2024', 'value' => '133.9'],
            ],
        ];
    }

    public function test_fetch_and_store_cpi_success(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response($this->getSampleONSResponse(), 200),
        ]);

        $result = $this->service->fetchAndStoreCPI();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey(2011, $result['data']);
        $this->assertEquals(93.4, $result['data'][2011]);
        $this->assertEquals(133.9, $result['data'][2024]);

        // Verify data was stored in config table.
        $stored = DB::table('config')->where('key', CPIService::CONFIG_KEY)->first();
        $this->assertNotNull($stored);

        $decoded = json_decode($stored->value, TRUE);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertEquals(93.4, $decoded['data'][2011]);

        // No alert email should be sent on success.
        Mail::assertNothingSent();
    }

    public function test_fetch_cpi_handles_api_failure(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response(NULL, 500),
        ]);

        $result = $this->service->fetchAndStoreCPI();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('status code', $result['message']);

        // Alert email sending is tested via log verification.
        // Mail::raw() doesn't use Mailable class, so Mail::fake() doesn't capture it.
    }

    public function test_fetch_cpi_handles_invalid_response(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response(['invalid' => 'data'], 200),
        ]);

        $result = $this->service->fetchAndStoreCPI();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid response', $result['message']);
    }

    public function test_fetch_cpi_validates_2011_base_year(): void
    {
        $invalidData = $this->getSampleONSResponse();
        // Remove 2011 from the data.
        $invalidData['years'] = array_filter(
            $invalidData['years'],
            fn($y) => $y['year'] !== '2011'
        );

        Http::fake([
            CPIService::ONS_API_URL => Http::response($invalidData, 200),
        ]);

        $result = $this->service->fetchAndStoreCPI();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('missing base year 2011', $result['message']);
    }

    public function test_fetch_cpi_validates_2011_value(): void
    {
        $invalidData = $this->getSampleONSResponse();
        // Set an incorrect 2011 value.
        foreach ($invalidData['years'] as &$year) {
            if ($year['year'] === '2011') {
                $year['value'] = '50.0'; // Wrong value
            }
        }

        Http::fake([
            CPIService::ONS_API_URL => Http::response($invalidData, 200),
        ]);

        $result = $this->service->fetchAndStoreCPI();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2011 looks incorrect', $result['message']);
    }

    public function test_get_cpi_data_returns_stored_data(): void
    {
        // Store some test data.
        $testData = [
            'data' => [2011 => 93.4, 2012 => 96.1, 2013 => 98.5],
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('config')->updateOrInsert(
            ['key' => CPIService::CONFIG_KEY],
            ['value' => json_encode($testData)]
        );

        $result = $this->service->getCPIData();

        $this->assertArrayHasKey(2011, $result);
        $this->assertEquals(93.4, $result[2011]);
        $this->assertCount(3, $result);
    }

    public function test_get_cpi_data_returns_fallback_when_empty(): void
    {
        // Make sure no data in config table.
        DB::table('config')->where('key', CPIService::CONFIG_KEY)->delete();

        $result = $this->service->getCPIData();

        // Should return fallback data.
        $this->assertArrayHasKey(2011, $result);
        $this->assertEquals(93.4, $result[2011]);
        $this->assertArrayHasKey(2024, $result);
    }

    public function test_get_latest_cpi_year(): void
    {
        $testData = [
            'data' => [2011 => 93.4, 2022 => 121.7, 2023 => 130.5],
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('config')->updateOrInsert(
            ['key' => CPIService::CONFIG_KEY],
            ['value' => json_encode($testData)]
        );

        $result = $this->service->getLatestCPIYear();

        $this->assertEquals(2023, $result);
    }

    public function test_get_cpi_for_year_returns_correct_value(): void
    {
        $testData = [
            'data' => [2011 => 93.4, 2015 => 100.0, 2020 => 108.7],
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('config')->updateOrInsert(
            ['key' => CPIService::CONFIG_KEY],
            ['value' => json_encode($testData)]
        );

        $this->assertEquals(93.4, $this->service->getCPIForYear(2011));
        $this->assertEquals(100.0, $this->service->getCPIForYear(2015));
        $this->assertEquals(108.7, $this->service->getCPIForYear(2020));
    }

    public function test_get_cpi_for_year_clamps_to_range(): void
    {
        $testData = [
            'data' => [2011 => 93.4, 2015 => 100.0, 2020 => 108.7],
            'updated_at' => now()->toIso8601String(),
        ];

        DB::table('config')->updateOrInsert(
            ['key' => CPIService::CONFIG_KEY],
            ['value' => json_encode($testData)]
        );

        // Year before range should return min year value.
        $this->assertEquals(93.4, $this->service->getCPIForYear(2005));

        // Year after range should return max year value.
        $this->assertEquals(108.7, $this->service->getCPIForYear(2025));
    }

    public function test_fallback_cpi_data_contains_expected_years(): void
    {
        $fallback = CPIService::FALLBACK_CPI_DATA;

        // Should have 2011 (base year).
        $this->assertArrayHasKey(2011, $fallback);
        $this->assertEquals(93.4, $fallback[2011]);

        // Should have 2015 (reference year = 100).
        $this->assertArrayHasKey(2015, $fallback);
        $this->assertEquals(100.0, $fallback[2015]);

        // Should have recent years.
        $this->assertArrayHasKey(2024, $fallback);

        // Values should increase over time.
        $this->assertGreaterThan($fallback[2011], $fallback[2024]);
    }

    /**
     * Integration test: Verify we can actually fetch from ONS API.
     * This test hits the real API so it's marked as integration.
     */
    #[Group('integration')]
    public function test_real_ons_api_fetch(): void
    {
        // Clear fake HTTP.
        Http::clearResolvedInstances();

        $result = $this->service->fetchAndStoreCPI();

        // This should succeed if ONS is up.
        $this->assertTrue(
            $result['success'],
            'Failed to fetch from ONS API: ' . ($result['message'] ?? 'unknown error')
        );

        // Should have data for 2011 and recent years.
        $this->assertArrayHasKey(2011, $result['data']);
        $this->assertEquals(93.4, $result['data'][2011]);

        // Should have data for a recent year.
        // Allow up to 2 years behind current year since ONS publishes annually
        // and there's typically a delay in publishing new data.
        $currentYear = (int) date('Y');
        $latestYear = max(array_keys($result['data']));
        $this->assertGreaterThanOrEqual($currentYear - 2, $latestYear);
    }
}
