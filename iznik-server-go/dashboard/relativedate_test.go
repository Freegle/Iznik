package dashboard

import (
	"testing"
	"time"
)

// parseRelativeDate turns the dashboard's date filters into a time. Unparseable
// input silently becomes "30 days ago" rather than an error, so these tests pin
// that fallback too — a caller that sends a bad date gets a month of data, not a
// failure, and that should be a deliberate choice rather than a surprise.

const dayTolerance = 12 * time.Hour

func assertDaysAgo(t *testing.T, got time.Time, wantDays int) {
	t.Helper()
	want := time.Now().AddDate(0, 0, -wantDays)
	if diff := got.Sub(want); diff > dayTolerance || diff < -dayTolerance {
		t.Errorf("got %v, want ~%d days ago (%v), difference %v", got, wantDays, want, diff)
	}
}

func TestParseRelativeDate_NamedOffsets(t *testing.T) {
	for _, tc := range []struct {
		in   string
		days int
	}{
		{"today", 0},
		{"7 days ago", 7},
		{"30 days ago", 30},
		{"90 days ago", 90},
	} {
		assertDaysAgo(t, parseRelativeDate(tc.in), tc.days)
	}
}

func TestParseRelativeDate_OneYearAgo(t *testing.T) {
	got := parseRelativeDate("1 year ago")
	want := time.Now().AddDate(-1, 0, 0)
	if diff := got.Sub(want); diff > dayTolerance || diff < -dayTolerance {
		t.Errorf("got %v, want ~1 year ago (%v)", got, want)
	}
}

func TestParseRelativeDate_AbsoluteDates(t *testing.T) {
	got := parseRelativeDate("2026-03-04")
	if got.Year() != 2026 || got.Month() != time.March || got.Day() != 4 {
		t.Errorf("plain date: got %v, want 2026-03-04", got)
	}

	got = parseRelativeDate("2026-03-04T10:11:12Z")
	if got.Year() != 2026 || got.Month() != time.March || got.Day() != 4 || got.Hour() != 10 {
		t.Errorf("RFC3339: got %v, want 2026-03-04T10:11:12Z", got)
	}
}

func TestParseRelativeDate_UnparseableFallsBackToThirtyDays(t *testing.T) {
	// Not an error — the caller gets a month of data.
	for _, in := range []string{"", "not a date", "yesterday", "04/03/2026", "3 days ago"} {
		assertDaysAgo(t, parseRelativeDate(in), 30)
	}
}
