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
 *
 * The benefit shown to councils also includes the fly-tipping clear-up costs
 * avoided for each item given away. This matches the frontend's
 * useReuseBenefit.js; see docs/developers/reference/impact-figures.md.
 */
class ReuseBenefit
{
    /** Config table key for CPI data (matches iznik-batch CPIService). */
    public const CONFIG_KEY = 'cpi_annual_data';

    /**
     * Hardcoded fallback UK CPI Annual Averages (2015=100).
     * Source: ONS MM23 dataset, series D7BT. Last updated: November 2026.
     *
     * It starts in 2003 because the fly-tipping clearance costs are in 2003-06
     * prices.
     */
    public const FALLBACK_CPI_DATA = [
        2003 => 75.5,
        2004 => 76.5,
        2005 => 78.1,
        2006 => 79.9,
        2007 => 81.8,
        2008 => 84.7,
        2009 => 86.6,
        2010 => 89.4,
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
        2025 => 138.4,
    ];

    /** Base year for the WRAP benefits of reuse value. */
    public const BASE_YEAR = 2011;

    /** Base benefit of reuse value in GBP per tonne (2011 prices). */
    public const BASE_BENEFIT_PER_TONNE = 711;

    /** CO2 impact per tonne (tCO2eq) - not inflation adjusted. */
    public const CO2_PER_TONNE = 0.51;

    /**
     * Share of people who fly-tip: a Keep Britain Tidy survey of 1,000
     * Londoners (2018) found 26, 21, 16 and 15 had fly-tipped a TV, oven,
     * mattress or sofa. The mean is 19.5 in 1,000. From the 2020 analysis of
     * Freegle's 2019 data.
     */
    public const FLYTIP_SHARE = 0.0195;

    /**
     * Defra's standard clearance cost in GBP for "other single item" and for
     * "car boot load or less" (ENV24 notes), based on council data for 2003-06.
     */
    public const FLYTIP_COST = 29;

    /** The years the clearance cost was measured in. */
    public const FLYTIP_COST_YEARS = [2003, 2004, 2005, 2006];

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
        // The WRAP value is never priced below its 2011 level.
        $year = max($targetYear ?? (int) date('Y'), self::BASE_YEAR);
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

    /**
     * The cost to a council of clearing one fly-tip, in GBP, uprated from the
     * average CPI of 2003-06. By default, adjusts to current year prices.
     *
     * Injected CPI data that lacks the early years (the config row has held
     * 2011 onwards) is filled in from the fallback table.
     */
    public static function getFlyTipCost(?int $targetYear = null, ?array $cpiData = null): float
    {
        $data = ($cpiData ?: []) + self::FALLBACK_CPI_DATA;
        ksort($data);
        $year = $targetYear ?? (int) date('Y');

        $base = 0.0;
        foreach (self::FLYTIP_COST_YEARS as $y) {
            $base += self::getCPI($y, $data);
        }
        $base /= count(self::FLYTIP_COST_YEARS);

        return self::FLYTIP_COST * self::getCPI($year, $data) / $base;
    }

    /** The fly-tipping clear-up cost avoided for each item given away, in GBP. */
    public static function getFlyTipSavingPerItem(?int $targetYear = null, ?array $cpiData = null): float
    {
        return self::FLYTIP_SHARE * self::getFlyTipCost($targetYear, $cpiData);
    }

    /**
     * A short note saying how much of a benefit figure is the fly-tipping
     * saving, and where that comes from. The same wording as the web pages.
     */
    public static function describeFlyTipSaving(float $saving): string
    {
        $amount = number_format(round($saving));

        return "Includes £{$amount} saved on clearing up fly-tipping, based on a 2020 "
            . 'study of Freegle data by DEFRA volunteers.';
    }
}
