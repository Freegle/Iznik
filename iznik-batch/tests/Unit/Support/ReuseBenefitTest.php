<?php

namespace Tests\Unit\Support;

use App\Support\ReuseBenefit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReuseBenefit. Pure maths: no DB, no filesystem, no external services.
 */
class ReuseBenefitTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public function test_public_constants_have_documented_values(): void
    {
        $this->assertSame(2011, ReuseBenefit::BASE_YEAR);
        $this->assertSame(711, ReuseBenefit::BASE_BENEFIT_PER_TONNE);
        $this->assertSame(0.51, ReuseBenefit::CO2_PER_TONNE);
        $this->assertSame('cpi_annual_data', ReuseBenefit::CONFIG_KEY);
    }

    public function test_fallback_data_range(): void
    {
        $years = array_keys(ReuseBenefit::FALLBACK_CPI_DATA);

        // From 2003, for the fly-tipping costs in 2003-06 prices, to the latest
        // full year ONS has published.
        $this->assertSame(2003, min($years));
        $this->assertSame(2025, max($years));
        $this->assertCount(23, $years);
        $this->assertContains(ReuseBenefit::BASE_YEAR, $years);
        foreach (ReuseBenefit::FLYTIP_COST_YEARS as $year) {
            $this->assertContains($year, $years);
        }
    }

    // -------------------------------------------------------------------------
    // getCPI
    // -------------------------------------------------------------------------

    public static function cpiProvider(): array
    {
        return [
            'below min clamps to earliest' => [1990, 75.5],
            'just below min clamps' => [2002, 75.5],
            'exact min year' => [2003, 75.5],
            'WRAP base year' => [2011, 93.4],
            'mid-range year' => [2020, 108.7],
            'repeated value year' => [2015, 100.0],
            'exact max year' => [2025, 138.4],
            'just above max clamps to latest' => [2026, 138.4],
            'far future clamps to latest' => [2100, 138.4],
        ];
    }

    #[DataProvider('cpiProvider')]
    public function test_get_cpi_default_data(int $year, float $expected): void
    {
        $this->assertSame($expected, ReuseBenefit::getCPI($year));
    }

    public function test_get_cpi_null_and_empty_fall_back_to_default(): void
    {
        $this->assertSame(108.7, ReuseBenefit::getCPI(2020, null));
        $this->assertSame(108.7, ReuseBenefit::getCPI(2020, []));
    }

    public static function injectedCpiProvider(): array
    {
        $data = [2000 => 50.0, 2001 => 60.0, 2005 => 90.0];

        return [
            'below injected min' => [1999, $data, 50.0],
            'injected min' => [2000, $data, 50.0],
            'injected mid' => [2001, $data, 60.0],
            'injected max' => [2005, $data, 90.0],
            'above injected max' => [2030, $data, 90.0],
            'single entry, below' => [1900, [2010 => 77.7], 77.7],
            'single entry, exact' => [2010, [2010 => 77.7], 77.7],
            'single entry, above' => [2999, [2010 => 77.7], 77.7],
        ];
    }

    #[DataProvider('injectedCpiProvider')]
    public function test_get_cpi_injected_data(int $year, array $data, float $expected): void
    {
        $this->assertSame($expected, ReuseBenefit::getCPI($year, $data));
    }

    // -------------------------------------------------------------------------
    // getInflationMultiplier
    // -------------------------------------------------------------------------

    public function test_multiplier_for_base_year_is_exactly_one(): void
    {
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(ReuseBenefit::BASE_YEAR));
    }

    public function test_multiplier_default_year_uses_current_year(): void
    {
        $this->assertSame(
            ReuseBenefit::getInflationMultiplier((int) date('Y')),
            ReuseBenefit::getInflationMultiplier()
        );
        $this->assertSame(
            ReuseBenefit::getInflationMultiplier((int) date('Y')),
            ReuseBenefit::getInflationMultiplier(null)
        );
    }

    public function test_multiplier_later_year_above_one(): void
    {
        $this->assertEqualsWithDelta(108.7 / 93.4, ReuseBenefit::getInflationMultiplier(2020), 1e-12);
        $this->assertGreaterThan(1.0, ReuseBenefit::getInflationMultiplier(2020));
    }

    public function test_multiplier_future_year_clamped_to_latest(): void
    {
        $this->assertSame(
            ReuseBenefit::getInflationMultiplier(2025),
            ReuseBenefit::getInflationMultiplier(2060)
        );
        $this->assertEqualsWithDelta(138.4 / 93.4, ReuseBenefit::getInflationMultiplier(2060), 1e-12);
    }

    public function test_multiplier_year_before_base_is_one(): void
    {
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(1980));
        // The table holds years before 2011, but the WRAP value is never
        // priced below its 2011 level.
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(2005));
    }

    public function test_multiplier_with_injected_data(): void
    {
        $data = [2011 => 50.0, 2020 => 100.0];

        $this->assertSame(2.0, ReuseBenefit::getInflationMultiplier(2020, $data));
        $this->assertSame(2.0, ReuseBenefit::getInflationMultiplier(2099, $data));
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(2011, $data));
    }

    public function test_multiplier_injected_data_without_base_year_clamps_base(): void
    {
        // Base year 2011 is below the table's minimum, so the earliest entry is the base.
        $data = [2015 => 100.0, 2020 => 150.0];

        $this->assertSame(1.5, ReuseBenefit::getInflationMultiplier(2020, $data));
    }

    // -------------------------------------------------------------------------
    // getBenefitPerTonne
    // -------------------------------------------------------------------------

    public function test_benefit_for_base_year_is_base_benefit(): void
    {
        $this->assertSame((float) ReuseBenefit::BASE_BENEFIT_PER_TONNE, ReuseBenefit::getBenefitPerTonne(2011));
    }

    public static function benefitProvider(): array
    {
        return [
            'base year' => [2011, 711.0],
            'before base year' => [1999, 711.0],
            '2020' => [2020, 827.0],
            '2024' => [2024, 1019.0],
            'latest year' => [2025, 1054.0],
            'future clamped' => [2035, 1054.0],
        ];
    }

    #[DataProvider('benefitProvider')]
    public function test_benefit_per_tonne_default_data(int $year, float $expected): void
    {
        $this->assertSame($expected, ReuseBenefit::getBenefitPerTonne($year));
        $this->assertSame(
            round(ReuseBenefit::BASE_BENEFIT_PER_TONNE * ReuseBenefit::getInflationMultiplier($year)),
            ReuseBenefit::getBenefitPerTonne($year)
        );
    }

    public function test_benefit_default_year_matches_current_year(): void
    {
        $this->assertSame(
            ReuseBenefit::getBenefitPerTonne((int) date('Y')),
            ReuseBenefit::getBenefitPerTonne()
        );
    }

    public static function roundingProvider(): array
    {
        // benefit = round(711 * target / base)
        return [
            'rounds up (711.711)' => [[2011 => 100.0, 2020 => 100.1], 712.0],
            'rounds up (711.711, other scale)' => [[2011 => 1000.0, 2020 => 1001.0], 712.0],
            'rounds down (711.2844)' => [[2011 => 1000.0, 2020 => 1000.4], 711.0],
            'exact half rounds away from zero (1066.5)' => [[2011 => 2.0, 2020 => 3.0], 1067.0],
            'doubling' => [[2011 => 50.0, 2020 => 100.0], 1422.0],
        ];
    }

    #[DataProvider('roundingProvider')]
    public function test_benefit_rounding_with_injected_data(array $data, float $expected): void
    {
        $this->assertSame($expected, ReuseBenefit::getBenefitPerTonne(2020, $data));
    }

    public function test_benefit_exact_values_with_injected_data(): void
    {
        $this->assertSame(1422.0, ReuseBenefit::getBenefitPerTonne(2020, [2011 => 50.0, 2020 => 100.0]));
        $this->assertSame(1067.0, ReuseBenefit::getBenefitPerTonne(2020, [2011 => 2.0, 2020 => 3.0]));
        $this->assertSame(711.0, ReuseBenefit::getBenefitPerTonne(2011, [2011 => 2.0, 2020 => 3.0]));
    }

    // -------------------------------------------------------------------------
    // Fly-tipping
    // -------------------------------------------------------------------------

    public function test_flytip_constants_are_from_the_2020_analysis(): void
    {
        // Keep Britain Tidy survey: 26, 21, 16 and 15 in 1,000. The mean is 19.5.
        $this->assertSame(0.0195, ReuseBenefit::FLYTIP_SHARE);
        // Defra standard cost for a single item and for a car boot load.
        $this->assertSame(29, ReuseBenefit::FLYTIP_COST);
        $this->assertSame([2003, 2004, 2005, 2006], ReuseBenefit::FLYTIP_COST_YEARS);
    }

    public function test_flytip_reproduces_the_2019_result_in_its_own_prices(): void
    {
        // The analysis ran over 289,944 items offered in 2019. Its random runs
        // gave a mean of £162,697 and a range of £112,444 to £234,571.
        $saving = ReuseBenefit::FLYTIP_SHARE * ReuseBenefit::FLYTIP_COST * 289944;

        $this->assertSame(163963.0, round($saving));
    }

    public function test_flytip_cost_is_uprated_from_the_2003_06_average(): void
    {
        $base = (75.5 + 76.5 + 78.1 + 79.9) / 4;

        $this->assertEqualsWithDelta(29 * 138.4 / $base, ReuseBenefit::getFlyTipCost(2025), 1e-9);
        $this->assertEqualsWithDelta(29 * 76.5 / $base, ReuseBenefit::getFlyTipCost(2004), 1e-9);
        $this->assertSame(52.0, round(ReuseBenefit::getFlyTipCost(2025)));
        $this->assertSame(ReuseBenefit::getFlyTipCost(2025), ReuseBenefit::getFlyTipCost(2100));
    }

    public function test_flytip_cost_fills_early_years_missing_from_injected_data(): void
    {
        // A config row may start at 2011. The base years come from the
        // fallback table, and the target year from the injected data.
        $config = [2011 => 93.4, 2020 => 108.7, 2025 => 138.4];

        $this->assertEqualsWithDelta(
            ReuseBenefit::getFlyTipCost(2025),
            ReuseBenefit::getFlyTipCost(2025, $config),
            1e-9
        );

        // An injected value wins over the fallback for the same year.
        $base = (75.5 + 76.5 + 78.1 + 79.9) / 4;
        $this->assertEqualsWithDelta(
            29 * 150.0 / $base,
            ReuseBenefit::getFlyTipCost(2026, [2011 => 93.4, 2026 => 150.0]),
            1e-9
        );
    }

    public function test_flytip_saving_per_item_is_about_a_pound_at_2025_prices(): void
    {
        $this->assertEqualsWithDelta(0.0195 * ReuseBenefit::getFlyTipCost(2025), ReuseBenefit::getFlyTipSavingPerItem(2025), 1e-12);
        $this->assertSame(1.01, round(ReuseBenefit::getFlyTipSavingPerItem(2025), 2));
    }

    public function test_describe_flytip_saving_matches_the_web_wording(): void
    {
        $this->assertSame(
            'Includes £8,034 saved on clearing up fly-tipping, based on a 2020 study of Freegle data by DEFRA volunteers.',
            ReuseBenefit::describeFlyTipSaving(8034.4)
        );
    }
}
