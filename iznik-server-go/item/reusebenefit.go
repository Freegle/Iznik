package item

import (
	"encoding/json"
	"math"
	"strconv"

	"gorm.io/gorm"
)

// Reuse benefit calculation with inflation adjustment.
//
// Ported from iznik-batch/app/Support/ReuseBenefit.php (and the CPI-fetch
// half of iznik-batch/app/Services/CPIService.php). The base benefit of
// reuse value (GBP 711 per tonne) comes from the WRAP "Benefits of Reuse"
// tool, originally published in 2011, up-rated by UK CPI inflation to
// current prices. The CO2 impact (0.51 tCO2eq per tonne) is a physical
// quantity and is not inflation-adjusted.
//
// By default the hardcoded FallbackCPIData table is used. A CPI data map may
// optionally be supplied (e.g. loaded from the `config` table's
// 'cpi_annual_data' row via LoadCPIData) for callers that want the latest
// published figures.

// CPIConfigKey is the config table key for CPI data (matches iznik-batch
// CPIService::CONFIG_KEY / ReuseBenefit::CONFIG_KEY).
const CPIConfigKey = "cpi_annual_data"

// BaseYear is the base year for the WRAP benefits-of-reuse value.
const BaseYear = 2011

// BaseBenefitPerTonne is the base benefit of reuse value in GBP per tonne
// (2011 prices).
const BaseBenefitPerTonne = 711

// CO2PerTonne is the CO2 impact per tonne (tCO2eq) - not inflation adjusted.
const CO2PerTonne = 0.51

// FallbackCPIData is the hardcoded fallback UK CPI Annual Averages
// (2015=100). Source: ONS MM23 dataset, series D7BT. Last updated: January
// 2025. Copied verbatim from ReuseBenefit::FALLBACK_CPI_DATA /
// CPIService::FALLBACK_CPI_DATA.
var FallbackCPIData = map[int]float64{
	2011: 93.4,
	2012: 96.1,
	2013: 98.5,
	2014: 100.0,
	2015: 100.0,
	2016: 100.7,
	2017: 103.4,
	2018: 105.9,
	2019: 107.8,
	2020: 108.7,
	2021: 111.6,
	2022: 121.7,
	2023: 130.5,
	2024: 133.9,
}

// cpiYearRange returns the min and max years present in cpiData.
func cpiYearRange(cpiData map[int]float64) (minYear int, maxYear int) {
	first := true
	for year := range cpiData {
		if first {
			minYear, maxYear = year, year
			first = false
			continue
		}
		if year < minYear {
			minYear = year
		}
		if year > maxYear {
			maxYear = year
		}
	}
	return minYear, maxYear
}

// GetCPI returns the CPI value for a given year, clamped to the available
// range: it returns the latest available year if the requested year is in
// the future, and the earliest available year if before the first year we
// have. Pass nil for cpiData to use FallbackCPIData.
func GetCPI(year int, cpiData map[int]float64) float64 {
	if len(cpiData) == 0 {
		cpiData = FallbackCPIData
	}

	minYear, maxYear := cpiYearRange(cpiData)

	if year < minYear {
		return cpiData[minYear]
	}
	if year > maxYear {
		return cpiData[maxYear]
	}
	return cpiData[year]
}

// GetInflationMultiplier calculates the inflation multiplier from BaseYear
// to targetYear. Pass nil for cpiData to use FallbackCPIData.
func GetInflationMultiplier(targetYear int, cpiData map[int]float64) float64 {
	baseCPI := GetCPI(BaseYear, cpiData)
	targetCPI := GetCPI(targetYear, cpiData)
	return targetCPI / baseCPI
}

// GetBenefitPerTonne returns the inflation-adjusted benefit per tonne in GBP
// (rounded to the nearest int, as a float64 to match the PHP round()
// return type). Pass nil for cpiData to use FallbackCPIData.
func GetBenefitPerTonne(targetYear int, cpiData map[int]float64) float64 {
	multiplier := GetInflationMultiplier(targetYear, cpiData)
	return math.Round(BaseBenefitPerTonne * multiplier)
}

// cpiConfigRow mirrors the JSON shape CPIService::storeCPIData() writes into
// the `config` table: {"data": {"2011": 93.4, ...}, "updated_at": ..., "source": ...}.
type cpiConfigRow struct {
	Data map[string]float64 `json:"data"`
}

// LoadCPIData reads the 'cpi_annual_data' row from the `config` table
// (written by iznik-batch's CPIService::fetchAndStoreCPI) and returns it as
// a year->CPI map. Mirrors CPIService::getCPIData(): any failure (missing
// row, malformed JSON, empty data) is tolerated and FallbackCPIData is
// returned instead - callers never need to nil-check.
func LoadCPIData(db *gorm.DB) map[int]float64 {
	var row struct {
		Value string `gorm:"column:value"`
	}

	result := db.Raw("SELECT value FROM config WHERE `key` = ? LIMIT 1", CPIConfigKey).Scan(&row)
	if result.Error != nil || row.Value == "" {
		return FallbackCPIData
	}

	var decoded cpiConfigRow
	if err := json.Unmarshal([]byte(row.Value), &decoded); err != nil || len(decoded.Data) == 0 {
		return FallbackCPIData
	}

	cpiData := make(map[int]float64, len(decoded.Data))
	for yearStr, value := range decoded.Data {
		year, err := strconv.Atoi(yearStr)
		if err != nil {
			continue
		}
		cpiData[year] = value
	}

	if len(cpiData) == 0 {
		return FallbackCPIData
	}

	return cpiData
}
