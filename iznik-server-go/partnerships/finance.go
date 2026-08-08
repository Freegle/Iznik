package partnerships

import (
	"fmt"
	"math"
	"time"
)

// The UK financial year runs 1 April to 31 March and is named after the calendar year
// it starts in, so financial year 2026 is 2026/27.
const financialYearStartMonth = time.April

// FinancialYear returns the financial year a date falls in.
func FinancialYear(t time.Time) int {
	if t.Month() < financialYearStartMonth {
		return t.Year() - 1
	}

	return t.Year()
}

// FinancialYearLabel renders a financial year the way finance people write it: "2026/27".
func FinancialYearLabel(fy int) string {
	return fmt.Sprintf("%d/%02d", fy, (fy+1)%100)
}

// FinancialYearBounds returns the first and last day of a financial year, inclusive.
func FinancialYearBounds(fy int) (time.Time, time.Time) {
	start := time.Date(fy, financialYearStartMonth, 1, 0, 0, 0, 0, time.UTC)
	end := start.AddDate(1, 0, -1)

	return start, end
}

// YearAmount is one financial year's share of a deal.
//
// The column tag is load-bearing: GORM would otherwise look for `financial_year`, and scan
// a zero into every year read back from partnerships_years.
type YearAmount struct {
	FinancialYear int     `json:"financialyear" gorm:"column:financialyear"`
	Label         string  `json:"label" gorm:"-"`
	Amount        float64 `json:"amount"`
}

// SplitAcrossFinancialYears apportions a deal's value across the financial years its term
// covers, by the number of days of the term falling in each year. A deal running 1 January
// to 31 December therefore shows roughly a quarter of its value in the first financial year
// and three quarters in the second, which is what "how does our income split over years"
// needs to mean for the multi-year council deals.
//
// Amounts are rounded to pence, with any rounding difference absorbed by the final year so
// the parts always add back up to the whole.
func SplitAcrossFinancialYears(start, end time.Time, amount float64) []YearAmount {
	start = truncateDay(start)
	end = truncateDay(end)

	if end.Before(start) {
		return []YearAmount{}
	}

	// Inclusive of both ends: a one-day deal is one day, not zero.
	totalDays := int(end.Sub(start).Hours()/24) + 1

	years := []YearAmount{}
	allocated := 0.0

	for fy := FinancialYear(start); fy <= FinancialYear(end); fy++ {
		fyStart, fyEnd := FinancialYearBounds(fy)

		from := fyStart
		if start.After(from) {
			from = start
		}

		to := fyEnd
		if end.Before(to) {
			to = end
		}

		days := int(to.Sub(from).Hours()/24) + 1
		if days <= 0 {
			continue
		}

		share := round2(amount * float64(days) / float64(totalDays))
		years = append(years, YearAmount{
			FinancialYear: fy,
			Label:         FinancialYearLabel(fy),
			Amount:        share,
		})
		allocated += share
	}

	// Push the rounding difference into the last year so the split is exact.
	if len(years) > 0 {
		years[len(years)-1].Amount = round2(years[len(years)-1].Amount + (amount - allocated))
	}

	return years
}

func truncateDay(t time.Time) time.Time {
	return time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
}

func round2(v float64) float64 {
	return math.Round(v*100) / 100
}
