package rippling

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ReplySourceSplitSQL builds the per-day attribution query. The `wide` flag
// switches whether the bucket expression reads the captured attribution
// column (falling back to the live derivation per row) or always derives it
// live (the legacy/unmigrated-DB path). srcGroup is spliced in verbatim as an
// optional origin-group scoping JOIN.
func TestReplySourceSplitSQL_LegacyNarrow(t *testing.T) {
	sql := ReplySourceSplitSQL(false, "")

	// Legacy path derives the bucket live - no COALESCE(rra.attribution, ...) wrapper.
	assert.NotContains(t, sql, "COALESCE(rra.attribution")
	assert.Contains(t, sql, "WHEN rra.was_home_member = 1 THEN 'home'")
	assert.Contains(t, sql, "WHEN EXISTS(SELECT 1 FROM rippling_reach_notified rrn")
	assert.Contains(t, sql, "THEN 'ripple_notified'")
	assert.Contains(t, sql, "WHEN EXISTS(SELECT 1 FROM messages_groups mgr")
	assert.Contains(t, sql, "THEN 'ripple_group'")
	assert.Contains(t, sql, "ELSE 'unknown'")

	// Output shape: one row per day with every channel summed off the bucket.
	assert.Contains(t, sql, "SELECT day,")
	assert.Contains(t, sql, "COUNT(*) AS replies")
	assert.Contains(t, sql, "SUM(bucket = 'home') AS home")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_notified') AS ripple_notified")
	assert.Contains(t, sql, "SUM(bucket = 'ripple_group') AS ripple_group")
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

func TestReplySourceSplitSQL_WideWrapsWithCoalesce(t *testing.T) {
	sql := ReplySourceSplitSQL(true, "")

	// Wide path prefers the captured column, falling back to the same live
	// derivation used by the legacy path for rows the backfill hasn't reached.
	assert.Contains(t, sql, "COALESCE(rra.attribution, CASE")
	assert.Contains(t, sql, "WHEN rra.was_home_member = 1 THEN 'home'")
	assert.Contains(t, sql, "END) AS bucket")
}

func TestReplySourceSplitSQL_SrcGroupSplicedIntoFROM(t *testing.T) {
	srcGroup := " JOIN messages_groups mg ON mg.msgid = rra.msgid AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0"
	sql := ReplySourceSplitSQL(false, srcGroup)

	assert.Contains(t, sql, "FROM rippling_reply_attribution rra"+srcGroup)
	// The join must land before the WHERE window, not after.
	joinIdx := strings.Index(sql, "JOIN messages_groups mg")
	whereIdx := strings.Index(sql, "WHERE rra.replied_at")
	if assert.True(t, joinIdx >= 0 && whereIdx >= 0, "both the JOIN and WHERE clause must be present") {
		assert.Less(t, joinIdx, whereIdx, "srcGroup JOIN must precede the replied_at WHERE window")
	}
}

func TestReplySourceSplitSQL_SrcGroupEmptyStringLeavesNoJoin(t *testing.T) {
	sql := ReplySourceSplitSQL(true, "")
	assert.NotContains(t, sql, "JOIN messages_groups mg")
}

// A srcGroup value containing SQL-significant characters (quotes, extra
// clauses) is spliced verbatim - this documents that ReplySourceSplitSQL
// trusts its caller and performs no quoting/escaping of srcGroup itself.
func TestReplySourceSplitSQL_SrcGroupEdgeCaseSplicedVerbatim(t *testing.T) {
	weird := ` JOIN "quoted" q ON q.msgid = rra.msgid`
	sql := ReplySourceSplitSQL(false, weird)
	assert.Contains(t, sql, "FROM rippling_reply_attribution rra"+weird)
}

func TestReplySourceSplitSQL_BothWideValuesProduceDifferentSQL(t *testing.T) {
	narrow := ReplySourceSplitSQL(false, "")
	wide := ReplySourceSplitSQL(true, "")
	assert.NotEqual(t, narrow, wide)
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
