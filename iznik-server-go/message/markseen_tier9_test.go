package message

// Tier 9 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): insertViewBatch
// (keep-raw site 40368b5c844a) builds a multi-row INSERT ... VALUES
// (?,?,?,0,?),(?,?,?,0,?),... ON DUPLICATE KEY UPDATE whose tuple count is
// len(chunk), 1..markSeenChunk (100) - a runtime value, not a shapes.json
// enumerable shape. buildInsertViewBatchQuery (markseen.go) is the pure
// builder extracted from insertViewBatch for this proof (a behaviour-
// preserving refactor only - insertViewBatch's actual SQL and its
// database.RetryExec call are unchanged).
//
// wantInsertViewBatchQuery is a second, independent implementation of the
// same structure, not a call into the function under test - see
// message_list_tier9_test.go's file comment for why that independence is
// what makes the comparison worth something.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
)

// wantInsertViewBatchQuery independently reconstructs
// buildInsertViewBatchQuery's output for n synthetic message ids.
func wantInsertViewBatchQuery(n int, myid uint64, source interface{}) (string, []interface{}) {
	tuples := make([]string, n)
	args := make([]interface{}, 0, n*4)
	for i := 0; i < n; i++ {
		tuples[i] = "(?, ?, ?, 0, ?)"
		args = append(args, uint64(i+1), myid, utils.MESSAGE_LIKES_VIEW, source)
	}
	sql := "INSERT INTO messages_likes (msgid, userid, type, pageview, source) VALUES " +
		strings.Join(tuples, ",") +
		" ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1"
	return sql, args
}

func syntheticChunk(n int) []uint64 {
	chunk := make([]uint64, n)
	for i := range chunk {
		chunk[i] = uint64(i + 1)
	}
	return chunk
}

// TestTier9_40368b5c844a proves buildInsertViewBatchQuery's multi-row VALUES
// list is correct at n = 0 (degenerate - MarkSeen never actually calls this
// with an empty chunk, but insertViewBatch places no guard on it, so an
// empty VALUES list must not silently mis-render), 1 (the single-tuple
// case, no comma), 2 (proves the tuple-joining behaviour, not just
// single-tuple rendering) and 100 (markSeenChunk, the real application
// cap MarkSeen enforces via its chunking loop).
func TestTier9_40368b5c844a(t *testing.T) {
	const myid = uint64(999)
	var source interface{} = "similar_posts"

	wantSQL := func(n int) string {
		sql, _ := wantInsertViewBatchQuery(n, myid, source)
		return sql
	}
	wantArgCount := func(n int) int {
		_, args := wantInsertViewBatchQuery(n, myid, source)
		return len(args)
	}

	ns := []int{0, 1, 2, markSeenChunk}
	cases := make([]ormharness.ParametrizedShapeCase, len(ns))
	for i, n := range ns {
		sql, args := buildInsertViewBatchQuery(syntheticChunk(n), myid, source)
		cases[i] = ormharness.ParametrizedShapeCase{N: n, SQL: sql, Args: args}
	}

	ormharness.AssertGoldenParametrizedShape(t, "40368b5c844a", wantSQL, wantArgCount, cases)
}
