package dashboard

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestPopularSpans_NothingStoredIsAllLive(t *testing.T) {
	spans := popularSpans("2026-08-03", "2026-11-02", "", "")
	assert.Equal(t, []popularSpan{{From: "2026-08-03", To: "2026-11-02", Inclusive: true}}, spans)
}

func TestPopularSpans_StoredMiddleLiveTail(t *testing.T) {
	// Counted from long ago, last run on 1 November: everything before that day is stored, the
	// day of the run onwards is live.
	spans := popularSpans("2026-08-03", "2026-11-02", "2026-01-01", "2026-11-01")
	assert.Equal(t, []popularSpan{
		{From: "2026-08-03", To: "2026-11-01", Stored: true},
		{From: "2026-11-01", To: "2026-11-02", Inclusive: true},
	}, spans)
}

func TestPopularSpans_LiveBeforeTheOldestStoredDay(t *testing.T) {
	spans := popularSpans("2026-08-03", "2026-11-02", "2026-09-01", "2026-11-01")
	assert.Equal(t, []popularSpan{
		{From: "2026-08-03", To: "2026-09-01"},
		{From: "2026-09-01", To: "2026-11-01", Stored: true},
		{From: "2026-11-01", To: "2026-11-02", Inclusive: true},
	}, spans)
}

func TestPopularSpans_PastRangeEntirelyStoredKeepsItsInclusiveEnd(t *testing.T) {
	spans := popularSpans("2026-06-01", "2026-07-01", "2026-01-01", "2026-11-01")
	assert.Equal(t, []popularSpan{{From: "2026-06-01", To: "2026-07-01", Inclusive: true, Stored: true}}, spans)
}

func TestPopularSpans_RangeBeforeAnythingStoredIsLive(t *testing.T) {
	spans := popularSpans("2025-01-01", "2025-02-01", "2026-09-01", "2026-11-01")
	assert.Equal(t, []popularSpan{{From: "2025-01-01", To: "2025-02-01", Inclusive: true}}, spans)
}

func TestPopularSpans_RangeAfterTheLastRunIsLive(t *testing.T) {
	spans := popularSpans("2026-11-01", "2026-11-02", "2026-01-01", "2026-11-01")
	assert.Equal(t, []popularSpan{{From: "2026-11-01", To: "2026-11-02", Inclusive: true}}, spans)
}

func TestSpanWindows_ExclusiveSpanEndsExclusive(t *testing.T) {
	wins := spanWindows(popularSpan{From: "2026-08-03", To: "2026-09-01"})
	assert.NotEmpty(t, wins)
	assert.False(t, wins[len(wins)-1].LastInclusive, "a live span that stops where stored counts start must not count that day")
	assert.Equal(t, "2026-09-01", wins[len(wins)-1].End)

	wins = spanWindows(popularSpan{From: "2026-11-01", To: "2026-11-02", Inclusive: true})
	assert.True(t, wins[len(wins)-1].LastInclusive)
}

func TestTopPopular_DedupsKeepingTheHigherCount(t *testing.T) {
	got := topPopular([]popularPost{
		{ID: 1, Views: 10, Subject: "stored"},
		{ID: 2, Views: 7},
		{ID: 1, Views: 12, Subject: "live"},
	}, 5)
	assert.Equal(t, []popularPost{{ID: 1, Views: 12, Subject: "live"}, {ID: 2, Views: 7}}, got)
}

func TestTopPopular_SortsAndLimitsWithIdTieBreak(t *testing.T) {
	got := topPopular([]popularPost{
		{ID: 5, Views: 3}, {ID: 4, Views: 3}, {ID: 3, Views: 9}, {ID: 2, Views: 1}, {ID: 1, Views: 8}, {ID: 6, Views: 2},
	}, 5)
	ids := []uint64{}
	for _, p := range got {
		ids = append(ids, p.ID)
	}
	assert.Equal(t, []uint64{3, 1, 4, 5, 6}, ids)
}

func TestSystemwideStart_CapsAtNinetyDays(t *testing.T) {
	assert.Equal(t, "2026-08-04", systemwideStart("2025-11-02", "2026-11-02"))
	assert.Equal(t, "2026-10-03", systemwideStart("2026-10-03", "2026-11-02"))
	assert.Equal(t, "junk", systemwideStart("junk", "2026-11-02"))
}
