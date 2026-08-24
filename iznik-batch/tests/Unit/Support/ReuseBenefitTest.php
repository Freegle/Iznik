<?php

namespace Tests\Unit\Support;

use App\Support\ReuseBenefit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReuseBenefitTest extends TestCase
{
    public static function cpiLookupProvider(): array
    {
        return [
            'exact year in range' => [2020, null, 108.7],
            'exact base year' => [2011, null, 93.4],
            'exact max year' => [2024, null, 133.9],
            'below min year clamps to min' => [2000, null, 93.4],
            'above max year clamps to max' => [2030, null, 133.9],
            'far below min year clamps to min' => [1900, null, 93.4],
            'far above max year clamps to max' => [3000, null, 133.9],
            'custom data exact year' => [2001, ['2000' => 50.0, '2001' => 100.0, '2002' => 200.0], 100.0],
            'custom data below min clamps' => [1990, ['2000' => 50.0, '2001' => 100.0, '2002' => 200.0], 50.0],
            'custom data above max clamps' => [2050, ['2000' => 50.0, '2001' => 100.0, '2002' => 200.0], 200.0],
        ];
    }

    #[DataProvider('cpiLookupProvider')]
    public function test_get_cpi(int $year, ?array $cpiData, float $expected): void
    {
        // Data providers can't reference int-keyed arrays cleanly via string
        // literals above, so re-key numerically here.
        $data = $cpiData === null ? null : array_combine(array_map('intval', array_keys($cpiData)), $cpiData);

        $this->assertSame($expected, ReuseBenefit::getCPI($year, $data));
    }

    public function test_get_cpi_uses_fallback_data_by_default(): void
    {
        $this->assertSame(ReuseBenefit::FALLBACK_CPI_DATA[2015], ReuseBenefit::getCPI(2015));
    }

    public function test_get_inflation_multiplier_with_explicit_year(): void
    {
        $multiplier = ReuseBenefit::getInflationMultiplier(2020);

        $this->assertEqualsWithDelta(108.7 / 93.4, $multiplier, 0.0000001);
    }

    public function test_get_inflation_multiplier_at_base_year_is_one(): void
    {
        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(ReuseBenefit::BASE_YEAR));
    }

    public function test_get_inflation_multiplier_defaults_to_current_year(): void
    {
        $currentYear = (int) date('Y');

        $expected = ReuseBenefit::getCPI($currentYear) / ReuseBenefit::getCPI(ReuseBenefit::BASE_YEAR);

        $this->assertEqualsWithDelta($expected, ReuseBenefit::getInflationMultiplier(), 0.0000001);
        $this->assertEqualsWithDelta($expected, ReuseBenefit::getInflationMultiplier(null), 0.0000001);
    }

    public function test_get_inflation_multiplier_with_custom_data_including_base_year(): void
    {
        $cpiData = [2011 => 100.0, 2020 => 200.0];

        $this->assertSame(2.0, ReuseBenefit::getInflationMultiplier(2020, $cpiData));
    }

    public function test_get_inflation_multiplier_with_custom_data_excluding_base_year(): void
    {
        // BASE_YEAR (2011) is not configurable and is outside this custom
        // table's range, so the base CPI clamps to the table's max year.
        $cpiData = [2000 => 50.0, 2001 => 100.0, 2002 => 200.0];

        $this->assertSame(1.0, ReuseBenefit::getInflationMultiplier(2002, $cpiData));
    }

    public function test_get_benefit_per_tonne_with_explicit_year(): void
    {
        // round(711 * (108.7 / 93.4)) computed independently of the source.
        $this->assertSame(827.0, ReuseBenefit::getBenefitPerTonne(2020));
    }

    public function test_get_benefit_per_tonne_at_base_year_equals_base_value(): void
    {
        $this->assertSame((float) ReuseBenefit::BASE_BENEFIT_PER_TONNE, ReuseBenefit::getBenefitPerTonne(ReuseBenefit::BASE_YEAR));
    }

    public function test_get_benefit_per_tonne_with_custom_data(): void
    {
        $cpiData = [2000 => 50.0, 2001 => 100.0, 2002 => 200.0];

        // multiplier clamps to 1.0 (see test above), so benefit == base value.
        $this->assertSame(711.0, ReuseBenefit::getBenefitPerTonne(2002, $cpiData));
    }

    public function test_get_benefit_per_tonne_returns_rounded_value(): void
    {
        $cpiData = [2011 => 100.0, 2020 => 200.0];

        // round(711 * 2.0) = 1422.0, an exact integer-valued float.
        $result = ReuseBenefit::getBenefitPerTonne(2020, $cpiData);

        $this->assertSame(1422.0, $result);
        $this->assertEquals($result, round($result), 'Result must already be rounded to the nearest integer.');
    }
}
