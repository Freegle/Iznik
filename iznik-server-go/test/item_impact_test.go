package test

// DB-backed tests for GET /item/impact (item.Impact), covering the
// three-step lookup (exact items match -> fuzzy weights match -> popularity-
// weighted average fallback), qty multiplication, and the 400 on a missing
// or empty name. Pure-logic coverage (canonWord/wordsInCommon/CPI math)
// lives alongside the implementation in item/impact_test.go and
// item/reusebenefit_test.go, with no DB required there.

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/item"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func fetchImpact(t *testing.T, name string, qty int) (int, item.ImpactResponse) {
	t.Helper()

	q := url.Values{}
	q.Set("name", name)
	if qty > 0 {
		q.Set("qty", fmt.Sprintf("%d", qty))
	}

	req := httptest.NewRequest("GET", "/apiv2/item/impact?"+q.Encode(), nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)

	body, _ := io.ReadAll(resp.Body)

	var result item.ImpactResponse
	if resp.StatusCode == 200 {
		require.NoError(t, json.Unmarshal(body, &result), "response body: %s", string(body))
	}

	return resp.StatusCode, result
}

func TestItemImpactMissingNameReturns400(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/item/impact", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestItemImpactEmptyNameReturns400(t *testing.T) {
	status, _ := fetchImpact(t, "   ", 0)
	assert.Equal(t, 400, status)
}

func TestItemImpactMissingNameReturns400V1(t *testing.T) {
	// /api alias must behave the same as /apiv2.
	req := httptest.NewRequest("GET", "/api/item/impact", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

// TestItemImpactExactItemsMatch covers lookup step (a): an exact,
// case-insensitive items-table match with a known weight wins over
// everything else, and is found regardless of query casing.
func TestItemImpactExactItemsMatch(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ItemImpactExact")
	itemName := fmt.Sprintf("TestImpactWidget %s", prefix)
	const knownWeight = 12.5

	itemID := CreateTestItem(t, itemName)
	db.Exec("UPDATE items SET weight = ? WHERE id = ?", knownWeight, itemID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM items WHERE id = ?", itemID)
	})

	// Query with different casing to prove the match is case-insensitive.
	status, result := fetchImpact(t, "testimpactwidget "+prefix, 1)
	require.Equal(t, 200, status)

	assert.Equal(t, "items", result.Source)
	require.NotNil(t, result.Matched)
	assert.Equal(t, itemName, *result.Matched)
	assert.Equal(t, knownWeight, result.PerUnitWeightKg)
	assert.Equal(t, knownWeight, result.TotalWeightKg)
	assert.Equal(t, 1, result.Qty)
}

// TestItemImpactFuzzyWeightsMatch covers lookup step (b): no items-table
// match, but the query name shares the word "sofa" with a weights reference
// row ('2 seater sofa' / simplename 'sofa' / 37.00 — the same row
// scripts/test-fixtures.sql ships for the dev DB). iznik_go_test is a
// SCHEMA-ONLY clone (setup-test-database.sh: "Go tests create their own
// fixture data at runtime"), so the test seeds the row itself.
func TestItemImpactFuzzyWeightsMatch(t *testing.T) {
	database.DBConn.Exec("INSERT IGNORE INTO weights (name, simplename, weight, source) VALUES ('2 seater sofa', 'sofa', 37.00, 'FRN 2009')")

	prefix := uniquePrefix("ItemImpactFuzzy")
	// A name that shares exactly the word "sofa" with the seeded weights
	// row, and is otherwise guaranteed not to exist in the items table.
	name := prefix + " sofa"

	status, result := fetchImpact(t, name, 1)
	require.Equal(t, 200, status)

	assert.Equal(t, "weights", result.Source)
	require.NotNil(t, result.Matched)
	assert.Equal(t, "sofa", *result.Matched)
	assert.Equal(t, 37.0, result.PerUnitWeightKg)
}

// TestItemImpactPopulationAverageFallback covers lookup step (c): a name
// with no items-table match and no usable word-overlap with any weights row
// falls back to the popularity-weighted average across the items catalog.
// The expected average is computed with the same SQL the endpoint uses,
// rather than hardcoded, since it's a genuine global aggregate over
// whatever items rows exist at test time.
func TestItemImpactPopulationAverageFallback(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ItemImpactAvg")
	// Multiple unrelated words so this can't accidentally trip the
	// wordsInCommon "single word list" edge case in a way that matters -
	// the assertion is against the true average either way.
	name := prefix + " zzqxvbnk unmatchable probe item"

	var avgResult struct {
		Average *float64
	}
	db.Raw("SELECT SUM(popularity*weight)/SUM(popularity) AS average FROM items WHERE weight IS NOT NULL AND weight != 0").Scan(&avgResult)
	expectedAvg := 0.0
	if avgResult.Average != nil {
		expectedAvg = *avgResult.Average
	}
	expectedAvg = roundForTest(expectedAvg)

	status, result := fetchImpact(t, name, 1)
	require.Equal(t, 200, status)

	assert.Equal(t, "average", result.Source)
	assert.Nil(t, result.Matched)
	assert.Equal(t, expectedAvg, result.PerUnitWeightKg)
}

// TestItemImpactQtyMultiplication covers total-weight/CO2e/benefit scaling:
// totalWeightKg = qty * perUnitWeightKg, and co2e/benefit are computed on
// that total (not the per-unit weight).
func TestItemImpactQtyMultiplication(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ItemImpactQty")
	itemName := fmt.Sprintf("TestImpactQtyWidget %s", prefix)
	const knownWeight = 4.0
	const qty = 3

	itemID := CreateTestItem(t, itemName)
	db.Exec("UPDATE items SET weight = ? WHERE id = ?", knownWeight, itemID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM items WHERE id = ?", itemID)
	})

	status, result := fetchImpact(t, itemName, qty)
	require.Equal(t, 200, status)

	assert.Equal(t, qty, result.Qty)
	assert.Equal(t, knownWeight, result.PerUnitWeightKg)

	expectedTotal := roundForTest(knownWeight * qty)
	assert.Equal(t, expectedTotal, result.TotalWeightKg)

	expectedCo2e := roundForTest(expectedTotal * 0.51)
	assert.Equal(t, expectedCo2e, result.Co2eKg)

	expectedBenefit := roundForTest(expectedTotal * result.BenefitPerTonne / 1000)
	assert.Equal(t, expectedBenefit, result.BenefitGbp)
}

// TestItemImpactDefaultQtyIsOne covers the qty default when omitted.
func TestItemImpactDefaultQtyIsOne(t *testing.T) {
	prefix := uniquePrefix("ItemImpactDefaultQty")
	status, result := fetchImpact(t, prefix+" sofa", 0)
	require.Equal(t, 200, status)
	assert.Equal(t, 1, result.Qty)
}

// TestItemImpactQtyIsCapped covers the qty upper bound: a caller-supplied
// qty far beyond any sane real-world value is clamped down to the server's
// maximum, rather than being multiplied through unbounded into the
// response's weight/CO2e/benefit fields.
func TestItemImpactQtyIsCapped(t *testing.T) {
	prefix := uniquePrefix("ItemImpactQtyCap")
	status, result := fetchImpact(t, prefix+" sofa", 999999999)
	require.Equal(t, 200, status)
	assert.Equal(t, 100000, result.Qty)
}

// roundForTest mirrors item.round2dp() (unexported, so duplicated here) -
// using math.Round directly, not a hand-rolled tie-break, so it can't
// diverge from the implementation's own rounding.
func roundForTest(v float64) float64 {
	return math.Round(v*100) / 100
}

// TestLoadCPIDataFallsBackWhenNoConfigRow covers CPIService's tolerate-
// absence behaviour: with no 'cpi_annual_data' row in `config` (the default
// state - no fixture seeds it), LoadCPIData must return data equivalent to
// FallbackCPIData, not error or panic.
func TestLoadCPIDataFallsBackWhenNoConfigRow(t *testing.T) {
	db := database.DBConn

	var exists int64
	db.Raw("SELECT COUNT(*) FROM config WHERE `key` = ?", item.CPIConfigKey).Scan(&exists)
	if exists > 0 {
		t.Skip("a 'cpi_annual_data' config row already exists - not the default-state scenario this test covers")
	}

	cpiData := item.LoadCPIData(db)
	assert.Equal(t, item.GetBenefitPerTonne(2011, nil), item.GetBenefitPerTonne(2011, cpiData))
	assert.Equal(t, item.GetBenefitPerTonne(2024, nil), item.GetBenefitPerTonne(2024, cpiData))
}

// TestLoadCPIDataHonoursConfigTableOverride covers the override path: a
// 'cpi_annual_data' row written in CPIService::storeCPIData()'s JSON shape
// must be read back and change the computed benefit.
func TestLoadCPIDataHonoursConfigTableOverride(t *testing.T) {
	db := database.DBConn

	configJSON := `{"data":{"2011":100.0,"2020":200.0},"updated_at":"2026-01-01T00:00:00+00:00","source":"test"}`
	db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
		item.CPIConfigKey, configJSON, configJSON)
	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` = ?", item.CPIConfigKey)
	})

	cpiData := item.LoadCPIData(db)
	// Inflation multiplier = 200/100 = 2; benefit = round(711*2) = 1422.
	assert.Equal(t, 1422.0, item.GetBenefitPerTonne(2020, cpiData))
}
