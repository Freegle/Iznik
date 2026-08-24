package dashboard

// Tests for the pure (no-DB) helpers behind the chunked dashboard rewrites (getUsersReplying,
// getUsersPosting, the newmessages counts): chunkUint64s (the IN (...) batching), mergeReplyCounts
// (the crosspost-multiplicity weighting that replaces the old single INNER JOIN), topUserCounts
// (the Go-side ORDER BY ... LIMIT), and arrivalWindows (the bounded date-range walk).

import (
	"reflect"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestChunkUint64s_Empty(t *testing.T) {
	got := chunkUint64s(nil, 1500)
	assert.Nil(t, got)

	got = chunkUint64s([]uint64{}, 1500)
	assert.Nil(t, got)
}

func TestChunkUint64s_SingleBatch(t *testing.T) {
	ids := []uint64{1, 2, 3}
	got := chunkUint64s(ids, 1500)
	assert.Equal(t, [][]uint64{{1, 2, 3}}, got)
}

// Exactly one batch's worth must not spill into an empty second batch.
func TestChunkUint64s_ExactMultiple(t *testing.T) {
	ids := []uint64{1, 2, 3, 4}
	got := chunkUint64s(ids, 2)
	assert.Equal(t, [][]uint64{{1, 2}, {3, 4}}, got)
}

// A remainder short of a full batch must still get its own (smaller) batch.
func TestChunkUint64s_UnevenRemainder(t *testing.T) {
	ids := []uint64{1, 2, 3, 4, 5}
	got := chunkUint64s(ids, 2)
	assert.Equal(t, [][]uint64{{1, 2}, {3, 4}, {5}}, got)
}

// Order is preserved and every id appears in exactly one batch (the dashboardBatch-sized
// IN (...) batches must reconstruct the full input set with none dropped or duplicated).
func TestChunkUint64s_CoversAllIDsInOrder(t *testing.T) {
	ids := make([]uint64, 3200)
	for i := range ids {
		ids[i] = uint64(i + 1)
	}
	batches := chunkUint64s(ids, 1500)
	if assert.Len(t, batches, 3) {
		assert.Len(t, batches[0], 1500)
		assert.Len(t, batches[1], 1500)
		assert.Len(t, batches[2], 200)
	}

	var rebuilt []uint64
	for _, b := range batches {
		rebuilt = append(rebuilt, b...)
	}
	assert.Equal(t, ids, rebuilt)
}

// size <= 0 must not loop forever; it degrades to a single batch rather than an infinite loop.
func TestChunkUint64s_NonPositiveSizeIsOneBatch(t *testing.T) {
	ids := []uint64{1, 2, 3}
	assert.Equal(t, [][]uint64{{1, 2, 3}}, chunkUint64s(ids, 0))
	assert.Equal(t, [][]uint64{{1, 2, 3}}, chunkUint64s(ids, -5))
}

func TestChunkUint64s_DoesNotMutateInput(t *testing.T) {
	ids := []uint64{1, 2, 3, 4, 5}
	orig := make([]uint64, len(ids))
	copy(orig, ids)
	_ = chunkUint64s(ids, 2)
	if !reflect.DeepEqual(ids, orig) {
		t.Fatalf("input was mutated: %v", ids)
	}
}

// A message posted to a single selected group (multiplicity 1) contributes its reply count once,
// same as a plain SUM(count) would.
func TestMergeReplyCounts_SingleGroupMessage(t *testing.T) {
	rows := []refUserCount{
		{Refmsgid: 100, Userid: 1, Count: 3},
	}
	multiplicity := map[uint64]int{100: 1}

	totals := mergeReplyCounts(rows, multiplicity)

	assert.Equal(t, map[uint64]int{1: 3}, totals)
}

// The behaviour this rewrite MUST preserve: the old query joined chat_messages to messages_groups,
// so a message crossposted into k of the selected groups produced k INNER JOIN rows per reply and
// so counted every reply on it k times. Here msgid 200 matched 3 of the selected groups
// (multiplicity 3), so its replies count 3x rather than being deduplicated to 1x.
func TestMergeReplyCounts_CrosspostedMessageMultipliesByGroupCount(t *testing.T) {
	rows := []refUserCount{
		{Refmsgid: 200, Userid: 1, Count: 2}, // user 1 left 2 replies on msgid 200
	}
	multiplicity := map[uint64]int{200: 3} // msgid 200 matched 3 of the selected groups

	totals := mergeReplyCounts(rows, multiplicity)

	assert.Equal(t, map[uint64]int{1: 6}, totals, "2 replies * 3 crossposted groups = 6, matching the old JOIN's per-group-row duplication")
}

// Totals from a crossposted message and a single-group message accumulate together per user, and
// different users on the same message are kept separate.
func TestMergeReplyCounts_MixedMessagesAccumulatePerUser(t *testing.T) {
	rows := []refUserCount{
		{Refmsgid: 200, Userid: 1, Count: 2}, // crossposted to 3 groups -> 6
		{Refmsgid: 200, Userid: 2, Count: 1}, // crossposted to 3 groups -> 3
		{Refmsgid: 300, Userid: 1, Count: 4}, // single group -> 4
	}
	multiplicity := map[uint64]int{200: 3, 300: 1}

	totals := mergeReplyCounts(rows, multiplicity)

	assert.Equal(t, map[uint64]int{1: 10, 2: 3}, totals)
}

// A row referencing a msgid absent from the multiplicity map (should not happen in practice, since
// rows are only ever fetched for msgids taken from the map) must not panic or contribute - it is
// silently dropped rather than treated as multiplicity 1.
func TestMergeReplyCounts_UnknownMsgidIsIgnored(t *testing.T) {
	rows := []refUserCount{
		{Refmsgid: 999, Userid: 1, Count: 5},
	}
	multiplicity := map[uint64]int{100: 1}

	totals := mergeReplyCounts(rows, multiplicity)

	assert.Empty(t, totals)
}

func TestMergeReplyCounts_EmptyRows(t *testing.T) {
	totals := mergeReplyCounts(nil, map[uint64]int{100: 1})
	assert.Empty(t, totals)
}

func TestTopUserCounts_SortsDescendingByCount(t *testing.T) {
	totals := map[uint64]int{1: 5, 2: 10, 3: 1}

	got := topUserCounts(totals, 5)

	assert.Equal(t, []userCount{
		{Userid: 2, Count: 10},
		{Userid: 1, Count: 5},
		{Userid: 3, Count: 1},
	}, got)
}

// Ties break on userid ascending - deterministic, since MySQL's original ORDER BY count DESC had
// no secondary key and never guaranteed any particular tie order either.
func TestTopUserCounts_TiesBreakByUseridAscending(t *testing.T) {
	totals := map[uint64]int{30: 5, 10: 5, 20: 5}

	got := topUserCounts(totals, 5)

	assert.Equal(t, []userCount{
		{Userid: 10, Count: 5},
		{Userid: 20, Count: 5},
		{Userid: 30, Count: 5},
	}, got)
}

func TestTopUserCounts_LimitsResults(t *testing.T) {
	totals := map[uint64]int{1: 1, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6}

	got := topUserCounts(totals, 5)

	assert.Len(t, got, 5)
	assert.Equal(t, uint64(6), got[0].Userid)
	assert.Equal(t, uint64(2), got[4].Userid)
}

func TestTopUserCounts_FewerThanLimitReturnsAll(t *testing.T) {
	totals := map[uint64]int{1: 1, 2: 2}

	got := topUserCounts(totals, 5)

	assert.Len(t, got, 2)
}

func TestTopUserCounts_EmptyTotals(t *testing.T) {
	got := topUserCounts(map[uint64]int{}, 5)
	assert.Empty(t, got)
}

// A range shorter than one window is a single inclusive window covering exactly [startQ, endQ] -
// the common 30-day-dashboard case collapses to one statement, same as before the rewrite.
func TestArrivalWindows_ShortRangeIsOneInclusiveWindow(t *testing.T) {
	got := arrivalWindows("2026-08-01", "2026-08-05")

	assert.Equal(t, []arrivalWindow{
		{Start: "2026-08-01", End: "2026-08-05", LastInclusive: true},
	}, got)
}

// A multi-window range: interior windows are half-open [Start, End) with each End equal to the
// next window's Start (no gap, no overlap), and only the final window is inclusive, preserving
// the replaced statements' "arrival <= endQ" semantics on the last day.
func TestArrivalWindows_MultiWindowChainsWithoutGapsOrOverlap(t *testing.T) {
	got := arrivalWindows("2026-01-01", "2026-01-20")

	assert.Equal(t, []arrivalWindow{
		{Start: "2026-01-01", End: "2026-01-08", LastInclusive: false},
		{Start: "2026-01-08", End: "2026-01-15", LastInclusive: false},
		{Start: "2026-01-15", End: "2026-01-20", LastInclusive: true},
	}, got)
}

// A range that lands exactly on a window boundary must not emit a zero-length trailing window;
// the final full window simply becomes the inclusive one.
func TestArrivalWindows_ExactMultipleOfWindow(t *testing.T) {
	got := arrivalWindows("2026-01-01", "2026-01-15")

	assert.Equal(t, []arrivalWindow{
		{Start: "2026-01-01", End: "2026-01-08", LastInclusive: false},
		{Start: "2026-01-08", End: "2026-01-15", LastInclusive: true},
	}, got)
}

// The year-plus admin range that motivated the rewrite: every window spans at most
// dashboardWindowDays days, they chain start-to-end across the whole range, and exactly the last
// one is inclusive. This is the shape that bounds each statement's messages_groups scan.
func TestArrivalWindows_YearRangeIsFullyCoveredByBoundedWindows(t *testing.T) {
	got := arrivalWindows("2025-08-11", "2026-08-12")

	assert.Greater(t, len(got), 50)
	assert.Equal(t, "2025-08-11", got[0].Start)
	assert.Equal(t, "2026-08-12", got[len(got)-1].End)
	for i, w := range got {
		start, err1 := time.Parse("2006-01-02", w.Start)
		end, err2 := time.Parse("2006-01-02", w.End)
		assert.NoError(t, err1)
		assert.NoError(t, err2)
		days := end.Sub(start).Hours() / 24
		assert.LessOrEqual(t, days, float64(dashboardWindowDays), "window %d spans more than the bound", i)
		assert.Greater(t, days, float64(0), "window %d is empty", i)
		if i < len(got)-1 {
			assert.Equal(t, got[i+1].Start, w.End, "window %d does not chain to the next", i)
			assert.False(t, w.LastInclusive)
		} else {
			assert.True(t, w.LastInclusive)
		}
	}
}

// Unparseable dates yield no windows - the walkers then return empty/zero, mirroring how the
// replaced single statements failed on a malformed date rather than scanning anything.
func TestArrivalWindows_BadDatesYieldNoWindows(t *testing.T) {
	assert.Nil(t, arrivalWindows("not-a-date", "2026-01-01"))
	assert.Nil(t, arrivalWindows("2026-01-01", "nope"))
}

// An empty range (start not before end) yields no windows: GetDashboard's endQ is always the end
// date plus one day, so a well-formed request can only hit this if end precedes start.
func TestArrivalWindows_EmptyRangeYieldsNoWindows(t *testing.T) {
	assert.Nil(t, arrivalWindows("2026-01-02", "2026-01-02"))
	assert.Nil(t, arrivalWindows("2026-01-05", "2026-01-02"))
}
