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

        $this->assertSame(2011, min($years));
        $this->assertSame(2024, max($years));
        $this->assertCount(14, $years);
        $this->assertSame(ReuseBenefit::BASE_YEAR, min($years));
    }

    // -------------------------------------------------------------------------
    // getCPI
    // -------------------------------------------------------------------------

    public static function cpiProvider(): array
    {
        return [
            'below min clamps to earliest' => [1990, 93.4],
            'just below min clamps' => [2010, 93.4],
            'exact min year' => [2011, 93.4],
            'mid-range year' => [2020, 108.7],
            'repeated value year' => [2015, 100.0],
            'exact max year' => [2024, 133.9],
            'just above max clamps to latest' => [2025, 133.9],
            'far future clamps to latest' => [2100, 133.9],
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
            ReuseBenefit::getInflationMultiplier(2024),
            ReuseBenefit::getInflationMultiplier(2060)
        );
        $this->assertEqualsWithDelta(133.9 / 93.4, ReuseBenefit::getInflationMultiplier(2060), 1e-12);
    }

    public function test_multiplier_year_before_base_is_one(): void
    {
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(1980));
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
            'latest year' => [2024, 1019.0],
            'future clamped' => [2035, 1019.0],
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
}
