package recommendations

// Tests for the pure (no-DB) helpers behind the Q6/Q12 per-day chunking in
// stats.go: dayWindows (chunk window computation) and mergeFunnelRows /
// mergeReplyRows (result merging).

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const layout = "2006-01-02 15:04:05"

func mustParse(t *testing.T, s string) time.Time {
	t.Helper()
	tm, err := time.ParseInLocation(layout, s, time.UTC)
	require.NoError(t, err)
	return tm
}

// TestDayWindows_SingleDay covers a since/until pair that both fall within
// the same calendar day: exactly one window, clipped at both ends.
func TestDayWindows_SingleDay(t *testing.T) {
	since := mustParse(t, "2026-08-02 09:15:00")
	until := mustParse(t, "2026-08-02 14:00:00")

	windows := dayWindows(since, until)

	require.Len(t, windows, 1)
	assert.Equal(t, "2026-08-02 09:15:00", windows[0].Start)
	assert.Equal(t, "2026-08-02 14:00:00", windows[0].End)
}

// TestDayWindows_SpansMultipleDays checks that every interior boundary sits on
// a midnight, the first window starts at `since` (not midnight), and the last
// window ends at `until` (not the following midnight).
func TestDayWindows_SpansMultipleDays(t *testing.T) {
	since := mustParse(t, "2026-07-30 18:30:00")
	until := mustParse(t, "2026-08-02 06:00:00")

	windows := dayWindows(since, until)

	require.Len(t, windows, 4)

	assert.Equal(t, "2026-07-30 18:30:00", windows[0].Start, "first window starts at since, not midnight")
	assert.Equal(t, "2026-07-31 00:00:00", windows[0].End)

	assert.Equal(t, "2026-07-31 00:00:00", windows[1].Start)
	assert.Equal(t, "2026-08-01 00:00:00", windows[1].End)

	assert.Equal(t, "2026-08-01 00:00:00", windows[2].Start)
	assert.Equal(t, "2026-08-02 00:00:00", windows[2].End)

	assert.Equal(t, "2026-08-02 00:00:00", windows[3].Start)
	assert.Equal(t, "2026-08-02 06:00:00", windows[3].End, "last window ends at until, not the next midnight")

	// No gaps and no overlaps: each window's End is the next window's Start.
	for i := 1; i < len(windows); i++ {
		assert.Equal(t, windows[i-1].End, windows[i].Start, "window %d must abut window %d with no gap/overlap", i-1, i)
	}
}

// TestDayWindows_ExactMidnightBoundaries covers since/until that already sit
// on midnight: every window should be a clean, full calendar day.
func TestDayWindows_ExactMidnightBoundaries(t *testing.T) {
	since := mustParse(t, "2026-08-01 00:00:00")
	until := mustParse(t, "2026-08-04 00:00:00")

	windows := dayWindows(since, until)

	require.Len(t, windows, 3)
	assert.Equal(t, "2026-08-01 00:00:00", windows[0].Start)
	assert.Equal(t, "2026-08-02 00:00:00", windows[0].End)
	assert.Equal(t, "2026-08-02 00:00:00", windows[1].Start)
	assert.Equal(t, "2026-08-03 00:00:00", windows[1].End)
	assert.Equal(t, "2026-08-03 00:00:00", windows[2].Start)
	assert.Equal(t, "2026-08-04 00:00:00", windows[2].End)
}

// TestDayWindows_EmptyWhenSinceNotBeforeUntil pins the degenerate-range guard:
// since == until, or since after until, both yield no windows rather than a
// negative-length or infinite range.
func TestDayWindows_EmptyWhenSinceNotBeforeUntil(t *testing.T) {
	same := mustParse(t, "2026-08-02 09:00:00")
	assert.Nil(t, dayWindows(same, same))

	later := mustParse(t, "2026-08-03 09:00:00")
	assert.Nil(t, dayWindows(later, same))
}

// TestMergeFunnelRows_SumsDuplicateKeys pins the arithmetic a careless rewrite
// breaks: rows sharing a (date, source) key must be summed into one row, not
// left as duplicates or overwritten - Stats() assigns the last-seen row's
// Impressions to the daily point but += the total to the source total, so any
// duplicate key that survives merging desyncs those two figures.
func TestMergeFunnelRows_SumsDuplicateKeys(t *testing.T) {
	rows := []funnelRow{
		{D: "2026-08-01", Source: "similar_posts", Impressions: 3, Clicks: 1},
		{D: "2026-08-01", Source: "similar_posts", Impressions: 5, Clicks: 2},
		{D: "2026-08-01", Source: "wanted_match", Impressions: 7, Clicks: 0},
		{D: "2026-08-02", Source: "similar_posts", Impressions: 2, Clicks: 2},
	}

	merged := mergeFunnelRows(rows)

	require.Len(t, merged, 3, "duplicate (date, source) key collapsed into one row")

	byKey := make(map[[2]string]funnelRow)
	for _, r := range merged {
		byKey[[2]string{r.D, r.Source}] = r
	}

	got := byKey[[2]string{"2026-08-01", "similar_posts"}]
	assert.Equal(t, int64(8), got.Impressions, "3+5 impressions summed")
	assert.Equal(t, int64(3), got.Clicks, "1+2 clicks summed")

	got2 := byKey[[2]string{"2026-08-01", "wanted_match"}]
	assert.Equal(t, int64(7), got2.Impressions)

	got3 := byKey[[2]string{"2026-08-02", "similar_posts"}]
	assert.Equal(t, int64(2), got3.Impressions)
}

// TestMergeFunnelRows_NoDuplicates verifies the common case - one row per key,
// as every real chunk window produces - passes through unchanged.
func TestMergeFunnelRows_NoDuplicates(t *testing.T) {
	rows := []funnelRow{
		{D: "2026-08-01", Source: "similar_posts", Impressions: 3, Clicks: 1},
		{D: "2026-08-01", Source: "wanted_match", Impressions: 7, Clicks: 0},
		{D: "2026-08-02", Source: "similar_posts", Impressions: 2, Clicks: 2},
	}

	merged := mergeFunnelRows(rows)

	require.Len(t, merged, 3)
	assert.ElementsMatch(t, rows, merged)
}

// TestMergeFunnelRows_Empty covers the zero-window case (e.g. since==until).
func TestMergeFunnelRows_Empty(t *testing.T) {
	assert.Empty(t, mergeFunnelRows(nil))
	assert.Empty(t, mergeFunnelRows([]funnelRow{}))
}

// TestMergeReplyRows_SumsDuplicateKeys mirrors TestMergeFunnelRows_SumsDuplicateKeys
// for the single-field reply rows.
func TestMergeReplyRows_SumsDuplicateKeys(t *testing.T) {
	rows := []replyRow{
		{D: "2026-08-01", Source: "similar_posts", Replies: 4},
		{D: "2026-08-01", Source: "similar_posts", Replies: 1},
		{D: "2026-08-01", Source: "wanted_match", Replies: 2},
	}

	merged := mergeReplyRows(rows)

	require.Len(t, merged, 2)

	byKey := make(map[[2]string]replyRow)
	for _, r := range merged {
		byKey[[2]string{r.D, r.Source}] = r
	}
	assert.Equal(t, int64(5), byKey[[2]string{"2026-08-01", "similar_posts"}].Replies, "4+1 replies summed")
	assert.Equal(t, int64(2), byKey[[2]string{"2026-08-01", "wanted_match"}].Replies)
}

// TestMergeReplyRows_Empty covers the zero-window case.
func TestMergeReplyRows_Empty(t *testing.T) {
	assert.Empty(t, mergeReplyRows(nil))
	assert.Empty(t, mergeReplyRows([]replyRow{}))
}

// sortDaily's swap was the Coveralls flap. Nothing tested it directly: it was
// reached only incidentally through a DB-backed test, whose rows carry no ORDER BY,
// so the swap ran only when MySQL happened to return the days out of order. Under
// CI's concurrent load that varied build to build, and a single statement moving in
// and out of cover is 0.004-0.005% of the Go suite - enough to turn the delta gate
// red on commits containing no Go at all (traced by diffing the retained profiles of
// builds 32736 and 32751, which differ by exactly this block).
func TestSortDaily_ReversedInput(t *testing.T) {
	points := []DailyPoint{
		{Date: "2026-08-03"},
		{Date: "2026-08-02"},
		{Date: "2026-08-01"},
	}

	sortDaily(points)

	assert.Equal(t, "2026-08-01", points[0].Date)
	assert.Equal(t, "2026-08-02", points[1].Date)
	assert.Equal(t, "2026-08-03", points[2].Date)
}

func TestSortDaily_AlreadyOrderedIsUnchanged(t *testing.T) {
	points := []DailyPoint{
		{Date: "2026-08-01"},
		{Date: "2026-08-02"},
	}

	sortDaily(points)

	assert.Equal(t, "2026-08-01", points[0].Date)
	assert.Equal(t, "2026-08-02", points[1].Date)
}

func TestSortDaily_EmptyAndSingle(t *testing.T) {
	sortDaily(nil)

	single := []DailyPoint{{Date: "2026-08-01"}}
	sortDaily(single)
	assert.Equal(t, "2026-08-01", single[0].Date)
}
