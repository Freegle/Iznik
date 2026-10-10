package rippling

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ReplySourceSplitSQL builds the per-day attribution query: the bucket reads the captured
// attribution column, falling back per row to the live derivation for rows the backfill has
// not reached. srcGroup is spliced in verbatim as an optional origin-group scoping JOIN.
func TestReplySourceSplitSQL_Shape(t *testing.T) {
	sql := ReplySourceSplitSQL("")

	// The captured column is preferred, with the live derivation as the per-row fallback.
	assert.Contains(t, sql, "COALESCE(rra.attribution, CASE")
	assert.Contains(t, sql, "END) AS bucket")
	assert.Contains(t, sql, "THEN 'home'")
	assert.Contains(t, sql, "WHEN EXISTS(SELECT 1 FROM rippling_reach_notified rrn")
	assert.Contains(t, sql, "THEN 'ripple_notified'")
	assert.Contains(t, sql, "THEN 'ripple_join'")
	assert.Contains(t, sql, "ELSE 'unknown'")

	// Output shape: one row per day with every channel summed off the bucket.
	assert.Contains(t, sql, "SELECT day,")
	assert.Contains(t, sql, "COUNT(*) AS replies")
	assert.Contains(t, sql, "SUM(bucket = 'home') AS home")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_notified') AS ripple_notified")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_group') AS ripple_group")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_join') AS ripple_join")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_reach') AS ripple_reach")
	assert.Contains(t, sql, "SUM(bucket = 'organic_local') AS organic_local")
	assert.Contains(t, sql, "SUM(bucket = 'unknown') AS unknown")
	assert.Contains(t, sql, "FROM rippling_reply_attribution rra")
	assert.Contains(t, sql, "WHERE rra.replied_at >= ? AND rra.replied_at < ?")
	assert.Contains(t, sql, "GROUP BY day")
	assert.Contains(t, sql, "ORDER BY day DESC")

	// No srcGroup passed - nothing but whitespace sits between the bare table
	// reference and the WHERE window (i.e. no stray JOIN got spliced in).
	fromIdx := strings.Index(sql, "FROM rippling_reply_attribution rra")
	whereIdx := strings.Index(sql, "WHERE rra.replied_at")
	if assert.True(t, fromIdx >= 0 && whereIdx > fromIdx) {
		between := sql[fromIdx+len("FROM rippling_reply_attribution rra") : whereIdx]
		assert.Empty(t, strings.TrimSpace(between))
	}
}

func TestReplySourceSplitSQL_SrcGroupEmptyStringLeavesNoJoin(t *testing.T) {
	sql := ReplySourceSplitSQL("")
	assert.NotContains(t, sql, "JOIN messages_groups mg")
}

// A srcGroup value containing SQL-significant characters (quotes, extra
// clauses) is spliced verbatim - this documents that ReplySourceSplitSQL
// trusts its caller and performs no quoting/escaping of srcGroup itself.
func TestReplySourceSplitSQL_SrcGroupEdgeCaseSplicedVerbatim(t *testing.T) {
	weird := ` JOIN "quoted" q ON q.msgid = rra.msgid`
	sql := ReplySourceSplitSQL(weird)
	assert.Contains(t, sql, "FROM rippling_reply_attribution rra"+weird)
}

// The live-capture boundary is cached for the life of the process because the query behind it
// full-scans rippling_reply_attribution (it ORs three unindexed nullable columns). Caching is
// only sound one way round: a found date is fixed for ever, but a blank means capture has not
// written anything YET - remembering that would leave the attribution chart unmarked until the
// next restart, long after the first captured reply arrived.
func TestCaptureFromCacheKeepsAFoundDate(t *testing.T) {
	captureFromCached = ""
	defer func() { captureFromCached = "" }()

	rememberCaptureFrom("2026-07-07")
	assert.Equal(t, "2026-07-07", cachedCaptureFrom(),
		"a found boundary is served from cache instead of re-running the scan")
}

func TestCaptureFromCacheDoesNotRememberBlank(t *testing.T) {
	captureFromCached = ""
	defer func() { captureFromCached = "" }()

	rememberCaptureFrom("")
	assert.Empty(t, cachedCaptureFrom(), "no boundary yet means keep looking, not 'there is none'")

	rememberCaptureFrom("2026-07-07")
	rememberCaptureFrom("")
	assert.Equal(t, "2026-07-07", cachedCaptureFrom(),
		"a blank must never wipe a boundary already found")
}
