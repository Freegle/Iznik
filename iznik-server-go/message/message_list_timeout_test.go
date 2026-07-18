package message

// TestSearchTimeout_* are the only tests in this package that need a live
// MySQL connection (the rest of message_list_*_test.go is deliberately
// DB-free). They reproduce, against a real connection, the exact abort
// mechanism behind buildMTUnionAllMsgIDQuery's production
// MAX_EXECUTION_TIME(20000) cap (Discourse 9938) - using a small cap + SLEEP
// so the same abort fires deterministically in about a second, instead of
// waiting on the real 20s production cap.

import (
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// ensureTestDB opens database.DBConn if this test binary hasn't already
// connected. Reuses the same MYSQL_* env vars the go-api test container
// already provides for the test/ package's integration suite; skips (rather
// than panicking) when they're absent, e.g. a bare local `go test ./message/...`.
func ensureTestDB(t *testing.T) {
	t.Helper()
	if database.DBConn != nil {
		return
	}
	if os.Getenv("MYSQL_HOST") == "" {
		t.Skip("MYSQL_HOST not set - skipping live-DB test")
	}
	database.InitDatabase()
}

// TestSearchTimeout_BuggyPluckPatternCannotDistinguishAbortFromEmpty
// reproduces 9938's root defect using only APIs that existed before this fix
// (a plain db.Raw(...).Pluck(...) call, exactly as every buildMTUnionAllMsgIDQuery
// branch in ListMessagesMT used pre-fix). It asserts the BUGGY behaviour:
// Pluck leaves ids empty on an abort, indistinguishable from a real empty
// search, because nothing inspects the returned error. This test compiles
// and passes on the pre-fix code (Pluck's own behaviour is unchanged) - it
// documents the defect this fix works around.
func TestSearchTimeout_BuggyPluckPatternCannotDistinguishAbortFromEmpty(t *testing.T) {
	ensureTestDB(t)
	db := database.DBConn

	var ids []uint64
	db.Raw("SELECT /*+ MAX_EXECUTION_TIME(100) */ 1 AS msgid FROM (SELECT SLEEP(1)) x").Pluck("msgid", &ids)

	assert.Empty(t, ids, "an aborted query silently looks like zero rows when only Pluck's ids are read")
}

// TestSearchTimeout_RealAbortIsDetected is the inverted assertion: it proves
// the fix (isQueryTimeoutErr) actually distinguishes the same abort from a
// genuine empty result, instead of the client being told "nothing found".
// This does not compile against the pre-fix code (isQueryTimeoutErr did not
// exist) and must pass once the fix is present.
func TestSearchTimeout_RealAbortIsDetected(t *testing.T) {
	ensureTestDB(t)
	db := database.DBConn

	var ids []uint64
	result := db.Raw("SELECT /*+ MAX_EXECUTION_TIME(100) */ 1 AS msgid FROM (SELECT SLEEP(1)) x")
	result.Pluck("msgid", &ids)

	require.Error(t, result.Error, "the deliberately slow query must actually be aborted by MySQL")
	assert.Empty(t, ids)
	assert.True(t, isQueryTimeoutErr(result.Error), "a MAX_EXECUTION_TIME abort must be classified as a timeout, err=%v", result.Error)
}
