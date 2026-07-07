<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for fetching UK CPI (Consumer Price Index) data from ONS.
 *
 * The CPI data is used to inflation-adjust the "benefit of reuse" value from
 * the 2011 WRAP report (£711 per tonne). This allows historical impact stats
 * to be displayed in current-year prices without recalculating stored data.
 *
 * Data source: ONS Consumer Price Index (2015=100), series D7BT
 * https://www.ons.gov.uk/economy/inflationandpriceindices/timeseries/d7bt/mm23
 */
class CPIService
{
    /**
     * ONS API URL for CPI time series data.
     * Series D7BT: CPI Annual Average (2015=100)
     */
    const ONS_API_URL = 'https://www.ons.gov.uk/economy/inflationandpriceindices/timeseries/d7bt/mm23/data';

    /**
     * Config table key for storing CPI data.
     */
    const CONFIG_KEY = 'cpi_annual_data';

    /**
     * Hardcoded fallback CPI data.
     * Used when config table is empty or API fetch fails.
     * Last updated: January 2025
     */
    const FALLBACK_CPI_DATA = [
        2011 => 93.4,
        2012 => 96.1,
        2013 => 98.5,
        2014 => 100.0,
        2015 => 100.0,
        2016 => 100.7,
        2017 => 103.4,
        2018 => 105.9,
        2019 => 107.8,
        2020 => 108.7,
        2021 => 111.6,
        2022 => 121.7,
        2023 => 130.5,
        2024 => 133.9,
    ];

    /**
     * GeekAlerts email address for failure notifications.
     */
    protected string $geekAlertsEmail;

    public function __construct()
    {
        $this->geekAlertsEmail = config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
    }

    /**
     * Fetch CPI data from ONS and store in config table.
     *
     * @return array Result with 'success', 'message', and optionally 'data'
     */
    public function fetchAndStoreCPI(): array
    {
        try {
            Log::info('CPIService: Fetching CPI data from ONS');

            $response = Http::timeout(30)->get(self::ONS_API_URL);

            if (!$response->successful()) {
                throw new \Exception('ONS API returned status code: ' . $response->status());
            }

            $data = $response->json();

            if (empty($data) || !isset($data['years'])) {
                throw new \Exception('Invalid response structure from ONS API');
            }

            // Parse annual CPI values from the years array.
            $cpiData = $this->parseONSData($data);

            if (empty($cpiData)) {
                throw new \Exception('No valid CPI data extracted from ONS response');
            }

            // Validate the data looks reasonable.
            $this->validateCPIData($cpiData);

            // Store in config table.
            $this->storeCPIData($cpiData);

            Log::info('CPIService: Successfully stored CPI data', [
                'years' => count($cpiData),
                'latest_year' => max(array_keys($cpiData)),
                'latest_value' => $cpiData[max(array_keys($cpiData))],
            ]);

            return [
                'success' => TRUE,
                'message' => 'CPI data updated successfully',
                'data' => $cpiData,
            ];

        } catch (\Exception $e) {
            Log::error('CPIService: Failed to fetch CPI data', [
                'error' => $e->getMessage(),
            ]);

            $this->sendAlert($e->getMessage());

            return [
                'success' => FALSE,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse CPI annual values from ONS JSON response.
     *
     * The ONS data structure has a 'years' array containing objects like:
     * { "year": "2024", "value": "133.9" }
     *
     * @param array $data Raw JSON data from ONS
     * @return array Associative array of year => CPI value
     */
    protected function parseONSData(array $data): array
    {
        $cpiData = [];

        foreach ($data['years'] as $entry) {
            if (!isset($entry['year']) || !isset($entry['value'])) {
                continue;
            }

            $year = (int) $entry['year'];
            $value = (float) $entry['value'];

            // Only include years from 2011 onwards (when WRAP report was published).
            // Also exclude invalid or placeholder values.
            if ($year >= 2011 && $value > 0) {
                $cpiData[$year] = $value;
            }
        }

        // Sort by year.
        ksort($cpiData);

        return $cpiData;
    }

    /**
     * Validate that CPI data looks reasonable.
     *
     * @param array $cpiData
     * @throws \Exception if validation fails
     */
    protected function validateCPIData(array $cpiData): void
    {
        // Must have data for 2011 (the base year for WRAP report).
        if (!isset($cpiData[2011])) {
            throw new \Exception('CPI data missing base year 2011');
        }

        // 2011 value should be around 93-94 (known correct value is 93.4).
        if ($cpiData[2011] < 90 || $cpiData[2011] > 98) {
            throw new \Exception('CPI value for 2011 looks incorrect: ' . $cpiData[2011]);
        }

        // 2015 should be around 100 (it's the reference year).
        if (isset($cpiData[2015]) && ($cpiData[2015] < 98 || $cpiData[2015] > 102)) {
            throw new \Exception('CPI value for 2015 should be around 100: ' . $cpiData[2015]);
        }

        // Values should generally increase over time (with some tolerance for deflation years).
        $years = array_keys($cpiData);
        $firstYear = min($years);
        $lastYear = max($years);

        if ($cpiData[$lastYear] < $cpiData[$firstYear] * 0.9) {
            throw new \Exception('CPI values appear to decrease significantly over time - possible data error');
        }
    }

    /**
     * Store CPI data in the config table.
     *
     * @param array $cpiData
     */
    protected function storeCPIData(array $cpiData): void
    {
        $json = json_encode([
            'data' => $cpiData,
            'updated_at' => now()->toIso8601String(),
            'source' => 'ONS D7BT series',
        ]);

        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [self::CONFIG_KEY, $json, $json]
        );
    }

    /**
     * Get CPI data from config table or fallback.
     *
     * @return array Associative array of year => CPI value
     */
    public function getCPIData(): array
    {
        try {
            $row = DB::table('config')
                ->where('key', self::CONFIG_KEY)
                ->first();

            if ($row && !empty($row->value)) {
                $decoded = json_decode($row->value, TRUE);
                if (isset($decoded['data']) && is_array($decoded['data'])) {
                    return $decoded['data'];
                }
            }
        } catch (\Exception $e) {
            Log::warning('CPIService: Failed to read from config table', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fall back to hardcoded values.
        Log::info('CPIService: Using fallback CPI data');
        return self::FALLBACK_CPI_DATA;
    }

    /**
     * Send alert email on failure.
     *
     * @param string $error
     */
    protected function sendAlert(string $error): void
    {
        try {
            $subject = 'CPI Data Fetch Failed';
            $body = "Failed to fetch CPI data from ONS.\n\n"
                . "Error: {$error}\n\n"
                . "The system will continue using the previously stored CPI data "
                . "or the hardcoded fallback values.\n\n"
                . "Please investigate and manually update if necessary.\n\n"
                . "ONS Data URL: " . self::ONS_API_URL;

            Mail::raw($body, function ($message) use ($subject) {
                $message->to($this->geekAlertsEmail)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject);
            });

            Log::info('CPIService: Alert email sent to ' . $this->geekAlertsEmail);

        } catch (\Exception $e) {
            Log::error('CPIService: Failed to send alert email', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the latest year for which we have CPI data.
     *
     * @return int
     */
    public function getLatestCPIYear(): int
    {
        $data = $this->getCPIData();
        return max(array_keys($data));
    }

    /**
     * Get CPI value for a specific year.
     * Returns the nearest available year if the exact year is not available.
     *
     * @param int $year
     * @return float
     */
    public function getCPIForYear(int $year): float
    {
        $data = $this->getCPIData();
        $years = array_keys($data);
        $minYear = min($years);
        $maxYear = max($years);

        if ($year < $minYear) {
            return $data[$minYear];
        }
        if ($year > $maxYear) {
            return $data[$maxYear];
        }

        return $data[$year];
    }
}
