package test

// DB-backed behaviour for iznik-server-go/ormshadow (Layer 3 of the ORM
// migration harness, plan section 7.2). ormshadow's own package carries pure
// unit tests for hashing and state parsing that need no database; these
// tests cover what only makes sense against a real config table and a real
// query: CurrentBatchState's read-through cache, and ShadowRead's behaviour
// in each of its three states.
//
// Divergence and error/timeout/panic handling are proven the same way
// TestSimilarImpressionLoggingDoesNotAffectResults (similar_test.go) proves
// Loki instrumentation doesn't affect results: by asserting what actually
// gets served to the caller, not by inspecting Loki output. misc.GetLoki()
// is a process-wide sync.Once singleton, so whichever test runs first in
// this binary fixes its enabled/disabled state for the rest of the run - a
// file-content assertion here would pass or fail on test ordering, not on
// whether ShadowRead behaves correctly.

import (
	"context"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormshadow"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/gorm"
)

// ormshadowBatchConfigKey mirrors ormshadow's own unexported config-key
// scheme ("ormharness.shadow.<batchID>"). Duplicated here deliberately
// rather than exported from the package: these tests set the flag exactly
// the way an operator would, by writing the config row, not via a back door
// into the package's internals.
func ormshadowBatchConfigKey(batchID string) string {
	return "ormharness.shadow." + batchID
}

// setShadowBatchState writes batchID's flag to the config table, registers
// cleanup, and invalidates ormshadow's cache so the write takes effect for
// the very next CurrentBatchState/ShadowRead call rather than up to 30
// seconds later.
func setShadowBatchState(t *testing.T, db *gorm.DB, batchID, state string) {
	t.Helper()
	key := ormshadowBatchConfigKey(batchID)
	db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", key, state)
	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` = ?", key)
	})
	ormshadow.InvalidateBatchStateCache(batchID)
}

type ormshadowUserRow struct {
	ID       uint64
	Fullname string
}

func TestCurrentBatchState_DefaultsOffAndReadsConfigTableThroughCache(t *testing.T) {
	db := database.DBConn
	batchID := "unittest_state_" + uniquePrefix("s")

	ormshadow.InvalidateBatchStateCache(batchID)
	assert.Equal(t, ormshadow.BatchOff, ormshadow.CurrentBatchState(db, batchID), "no config row yet: must default to off")

	key := ormshadowBatchConfigKey(batchID)
	db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", key, "shadow")
	t.Cleanup(func() { db.Exec("DELETE FROM config WHERE `key` = ?", key) })

	assert.Equal(t, ormshadow.BatchOff, ormshadow.CurrentBatchState(db, batchID),
		"expected the cached value to still apply immediately after the row changed underneath it")

	ormshadow.InvalidateBatchStateCache(batchID)
	assert.Equal(t, ormshadow.BatchShadow, ormshadow.CurrentBatchState(db, batchID),
		"expected a fresh read to pick up the new value once the cache is invalidated")
}

func TestShadowRead_Off_NeverRunsNewPath(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormshadow_off"), "User")
	batchID := "unittest_off_" + uniquePrefix("b")
	ormshadow.InvalidateBatchStateCache(batchID) // No config row written -> off.

	called := false
	rows, err := ormshadow.ShadowRead[ormshadowUserRow](
		context.Background(), db, batchID, "unittest-site-off",
		"SELECT id, fullname FROM users WHERE id = ?", []any{userID},
		func(tx *gorm.DB) *gorm.DB {
			called = true
			return tx.Table("users").Select("id, fullname").Where("id = ?", userID)
		},
		ormshadow.ShadowOptions{},
	)

	require.NoError(t, err)
	require.Len(t, rows, 1)
	assert.Equal(t, userID, rows[0].ID)
	assert.False(t, called, "the new path must never run while the batch is off")
}

func TestShadowRead_Shadow_ServesOldAndRunsNewAsync(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormshadow_shadow"), "User")
	batchID := "unittest_shadow_" + uniquePrefix("b")
	setShadowBatchState(t, db, batchID, "shadow")

	newPathCalled := make(chan struct{}, 1)

	rows, err := ormshadow.ShadowRead[ormshadowUserRow](
		context.Background(), db, batchID, "unittest-site-shadow",
		"SELECT id, fullname FROM users WHERE id = ?", []any{userID},
		func(tx *gorm.DB) *gorm.DB {
			select {
			case newPathCalled <- struct{}{}:
			default:
			}
			// Deliberately wrong, to prove a divergence in the async new path
			// can never affect what gets served.
			return tx.Table("users").Select("id, 'WRONG-NAME' AS fullname").Where("id = ?", userID)
		},
		ormshadow.ShadowOptions{},
	)

	require.NoError(t, err)
	require.Len(t, rows, 1)
	assert.Equal(t, userID, rows[0].ID)
	assert.NotEqual(t, "WRONG-NAME", rows[0].Fullname, "shadow mode must serve the OLD result even though the new path diverges")

	select {
	case <-newPathCalled:
		// Good - the async comparison did run.
	case <-time.After(2 * time.Second):
		t.Error("expected the new path to run asynchronously while the batch is in shadow mode")
	}
}

func TestShadowRead_Live_ServesNewAndNeverRunsOld(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormshadow_live"), "User")
	batchID := "unittest_live_" + uniquePrefix("b")
	setShadowBatchState(t, db, batchID, "live")

	rows, err := ormshadow.ShadowRead[ormshadowUserRow](
		context.Background(), db, batchID, "unittest-site-live",
		// Deliberately invalid: if ShadowRead's BatchLive branch is wrong and
		// still runs the old path, this SQL failing would surface as an error.
		"SELECT * FROM this_table_does_not_exist_ormshadow_probe", nil,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("users").Select("id, fullname").Where("id = ?", userID)
		},
		ormshadow.ShadowOptions{},
	)

	require.NoError(t, err)
	require.Len(t, rows, 1)
	assert.Equal(t, userID, rows[0].ID)
}

func TestShadowRead_Shadow_AsyncTimeoutNeverBlocksTheCaller(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormshadow_timeout"), "User")
	batchID := "unittest_timeout_" + uniquePrefix("b")
	setShadowBatchState(t, db, batchID, "shadow")

	started := time.Now()
	rows, err := ormshadow.ShadowRead[ormshadowUserRow](
		context.Background(), db, batchID, "unittest-site-timeout",
		"SELECT id, fullname FROM users WHERE id = ?", []any{userID},
		func(tx *gorm.DB) *gorm.DB {
			// A deliberately slow new path: the caller must not wait for it,
			// and it must be cancelled rather than left to run to completion.
			return tx.Raw("SELECT id, fullname FROM users WHERE id = ? AND SLEEP(2) = 0", userID)
		},
		ormshadow.ShadowOptions{Timeout: 200 * time.Millisecond},
	)
	elapsed := time.Since(started)

	require.NoError(t, err)
	require.Len(t, rows, 1)
	assert.Equal(t, userID, rows[0].ID)
	assert.Less(t, elapsed, 1*time.Second, "the caller must be served the old result immediately, never waiting on the async new path")
}
