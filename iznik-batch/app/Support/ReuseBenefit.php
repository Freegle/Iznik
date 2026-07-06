<?php

namespace App\Support;

/**
 * Reuse benefit calculation with inflation adjustment.
 *
 * The base benefit of reuse value (GBP 711 per tonne) comes from the WRAP
 * "Benefits of Reuse" tool, originally published in 2011, up-rated by UK CPI
 * inflation to current prices. The CO2 impact (0.51 tCO2eq per tonne) is a
 * physical quantity and is not inflation-adjusted.
 *
 * By default the hardcoded FALLBACK_CPI_DATA and the current year are used. A
 * CPI data array may optionally be injected (e.g. from CPIService's config row)
 * for callers that want the latest published figures.
 */
class ReuseBenefit
{
    /** Config table key for CPI data (matches iznik-batch CPIService). */
    public const CONFIG_KEY = 'cpi_annual_data';

    /**
     * Hardcoded fallback UK CPI Annual Averages (2015=100).
     * Source: ONS MM23 dataset, series D7BT. Last updated: January 2025.
     */
    public const FALLBACK_CPI_DATA = [
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

    /** Base year for the WRAP benefits of reuse value. */
    public const BASE_YEAR = 2011;

    /** Base benefit of reuse value in GBP per tonne (2011 prices). */
    public const BASE_BENEFIT_PER_TONNE = 711;

    /** CO2 impact per tonne (tCO2eq) - not inflation adjusted. */
    public const CO2_PER_TONNE = 0.51;

    /**
     * Get the CPI value for a given year, clamped to the available range.
     * Returns the latest available year if the requested year is in the future,
     * and the earliest available year if before the first year we have.
     */
    public static function getCPI(int $year, ?array $cpiData = null): float
    {
        $cpiData = $cpiData ?: self::FALLBACK_CPI_DATA;
        $years = array_keys($cpiData);
        $minYear = min($years);
        $maxYear = max($years);

        if ($year < $minYear) {
            return $cpiData[$minYear];
        }
        if ($year > $maxYear) {
            return $cpiData[$maxYear];
        }
        return $cpiData[$year];
    }

    /**
     * Calculate the inflation multiplier from the base year to the target year.
     */
    public static function getInflationMultiplier(?int $targetYear = null, ?array $cpiData = null): float
    {
        $year = $targetYear ?? (int) date('Y');
        $baseCPI = self::getCPI(self::BASE_YEAR, $cpiData);
        $targetCPI = self::getCPI($year, $cpiData);
        return $targetCPI / $baseCPI;
    }

    /**
     * Get the inflation-adjusted benefit per tonne in GBP (rounded to nearest int).
     * By default, adjusts to current year prices using the fallback CPI table.
     */
    public static function getBenefitPerTonne(?int $targetYear = null, ?array $cpiData = null): float
    {
        $multiplier = self::getInflationMultiplier($targetYear, $cpiData);
        return round(self::BASE_BENEFIT_PER_TONNE * $multiplier);
    }
}
