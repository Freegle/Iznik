package partnerships

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func date(s string) time.Time {
	t, err := time.Parse("2006-01-02", s)
	if err != nil {
		panic(err)
	}

	return t
}

func TestFinancialYearBoundary(t *testing.T) {
	// 31 March is still the old financial year; 1 April starts the new one.
	assert.Equal(t, 2025, FinancialYear(date("2026-03-31")))
	assert.Equal(t, 2026, FinancialYear(date("2026-04-01")))
	assert.Equal(t, 2026, FinancialYear(date("2026-12-31")))
	assert.Equal(t, 2026, FinancialYear(date("2027-01-01")))
}

func TestFinancialYearLabel(t *testing.T) {
	assert.Equal(t, "2026/27", FinancialYearLabel(2026))
	// The century rollover must not print "2099/100".
	assert.Equal(t, "2099/00", FinancialYearLabel(2099))
	assert.Equal(t, "2009/10", FinancialYearLabel(2009))
}

func TestFinancialYearBounds(t *testing.T) {
	start, end := FinancialYearBounds(2026)
	assert.Equal(t, "2026-04-01", start.Format("2006-01-02"))
	assert.Equal(t, "2027-03-31", end.Format("2006-01-02"))
}

func TestSplitWithinOneFinancialYear(t *testing.T) {
	years := SplitAcrossFinancialYears(date("2026-04-01"), date("2027-03-31"), 1200)

	assert.Len(t, years, 1)
	assert.Equal(t, 2026, years[0].FinancialYear)
	assert.Equal(t, "2026/27", years[0].Label)
	assert.Equal(t, 1200.0, years[0].Amount)
}

func TestSplitAcrossTwoFinancialYears(t *testing.T) {
	// A calendar year deal: 90 days in 2025/26 (Jan-Mar) and 275 in 2026/27.
	years := SplitAcrossFinancialYears(date("2026-01-01"), date("2026-12-31"), 3650)

	assert.Len(t, years, 2)
	assert.Equal(t, 2025, years[0].FinancialYear)
	assert.Equal(t, 2026, years[1].FinancialYear)
	assert.InDelta(t, 900.0, years[0].Amount, 0.01)
	assert.InDelta(t, 2750.0, years[1].Amount, 0.01)
}

func TestSplitOfMultiYearDeal(t *testing.T) {
	// Three whole financial years, so close to an even three-way split. Not exactly even:
	// 2027/28 contains a leap day, so it earns a fractionally larger share.
	years := SplitAcrossFinancialYears(date("2026-04-01"), date("2029-03-31"), 9000)

	assert.Len(t, years, 3)
	for _, y := range years {
		assert.InDelta(t, 3000.0, y.Amount, 10.0, "each year gets roughly a third")
	}

	assert.Greater(t, years[1].Amount, years[0].Amount, "the leap-year financial year is a day longer")
}

func TestSplitAlwaysSumsToTheWhole(t *testing.T) {
	// A term whose day counts do not divide cleanly still adds back up exactly - the
	// rounding difference lands in the last year rather than vanishing.
	years := SplitAcrossFinancialYears(date("2026-02-15"), date("2029-07-14"), 10000)

	total := 0.0
	for _, y := range years {
		total += y.Amount
	}

	assert.InDelta(t, 10000.0, total, 0.001)
	assert.Equal(t, 2025, years[0].FinancialYear)
	assert.Equal(t, 2029, years[len(years)-1].FinancialYear)
}

func TestSplitOfSingleDay(t *testing.T) {
	years := SplitAcrossFinancialYears(date("2026-06-01"), date("2026-06-01"), 500)

	assert.Len(t, years, 1)
	assert.Equal(t, 500.0, years[0].Amount)
}

func TestSplitOfInvertedDatesIsEmpty(t *testing.T) {
	years := SplitAcrossFinancialYears(date("2026-06-01"), date("2026-05-01"), 500)

	assert.Empty(t, years)
}

func TestSplitOfZeroAmount(t *testing.T) {
	years := SplitAcrossFinancialYears(date("2026-01-01"), date("2026-12-31"), 0)

	assert.Len(t, years, 2)
	for _, y := range years {
		assert.Equal(t, 0.0, y.Amount)
	}
}
