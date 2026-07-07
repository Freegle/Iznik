<?php

namespace Tests\Feature\Data;

use App\Services\CPIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UpdateCPICommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_command_succeeds_with_valid_response(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response($this->getSampleONSResponse(), 200),
        ]);

        $this->artisan('data:update-cpi')
            ->expectsOutput('Fetching CPI data from ONS...')
            ->expectsOutput('CPI data updated successfully.')
            ->assertExitCode(0);
    }

    public function test_command_fails_with_api_error(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response(NULL, 500),
        ]);

        $this->artisan('data:update-cpi')
            ->expectsOutput('Fetching CPI data from ONS...')
            ->assertExitCode(1);

        // Alert email is sent but Mail::fake() doesn't capture Mail::raw().
    }

    public function test_command_shows_latest_year_and_value(): void
    {
        Http::fake([
            CPIService::ONS_API_URL => Http::response($this->getSampleONSResponse(), 200),
        ]);

        // Use expectsOutput for exact line match.
        $this->artisan('data:update-cpi')
            ->expectsOutput('Latest year: 2024, CPI value: 133.9')
            ->assertExitCode(0);
    }
}
