package item

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// Pure-logic tests for the CPI/benefit-of-reuse math ported from
// iznik-batch/app/Support/ReuseBenefit.php. These pin the same values the
// Laravel test suite pins (AuthorityStatsServiceTest::test_reuse_benefit_inflation_and_clamping),
// so the Go and PHP implementations can't silently drift apart.

func TestGetCPIExactYears(t *testing.T) {
	assert.Equal(t, 93.4, GetCPI(2011, nil))
	assert.Equal(t, 133.9, GetCPI(2024, nil))
}

func TestGetCPIClampsOutOfRangeYears(t *testing.T) {
	assert.Equal(t, 93.4, GetCPI(2000, nil), "clamps below the range")
	assert.Equal(t, 133.9, GetCPI(2100, nil), "clamps above the range")
}

func TestGetBenefitPerTonneMatchesLaravelPinnedValues(t *testing.T) {
	// Exact values pinned in AuthorityStatsServiceTest.php - must stay in
	// sync with the PHP port.
	assert.Equal(t, 711.0, GetBenefitPerTonne(2011, nil))
	assert.Equal(t, 1019.0, GetBenefitPerTonne(2024, nil))
}

func TestCO2PerTonneConstant(t *testing.T) {
	assert.Equal(t, 0.51, CO2PerTonne)
}

func TestGetBenefitPerTonneClampsToLatestKnownYear(t *testing.T) {
	// 2026 (a year beyond FallbackCPIData's last entry, 2024) must clamp to
	// the 2024 CPI value, giving the same benefit as GetBenefitPerTonne(2024).
	assert.Equal(t, GetBenefitPerTonne(2024, nil), GetBenefitPerTonne(2026, nil))
}

func TestGetBenefitPerTonneHonoursCustomCPIData(t *testing.T) {
	custom := map[int]float64{
		2011: 100.0,
		2020: 200.0,
	}

	// Inflation multiplier = 200/100 = 2; benefit = round(711*2) = 1422.
	assert.Equal(t, 1422.0, GetBenefitPerTonne(2020, custom))
}

func TestGetCPIEmptyMapFallsBackToDefault(t *testing.T) {
	// An empty (non-nil) map should behave exactly like nil - matches PHP's
	// `$cpiData ?: self::FALLBACK_CPI_DATA` (empty array is falsy in PHP).
	assert.Equal(t, 93.4, GetCPI(2011, map[int]float64{}))
}

func TestGetInflationMultiplierAtBaseYearIsOne(t *testing.T) {
	assert.Equal(t, 1.0, GetInflationMultiplier(BaseYear, nil))
}
