package test

// DB-backed behaviour for Layer 4 of the ORM migration harness (plan
// section 7.2: write parity by replay), in iznik-server-go/ormharness's
// replay.go. Pure logic (plan normalisation, the diff formatter, the
// key/equality helpers) is covered without a database in
// ormharness/replay_test.go; these tests need a real MySQL instance for
// EXPLAIN, information_schema and actual upserts.
//
// DiffTables is designed to compare two INDEPENDENTLY PROVISIONED database
// copies (the "yesterday" restore replay - see
// docs/developers/reference/orm-migration-harness.md). This environment has
// only one test database, so the DiffTables test here only proves the
// plumbing (information_schema primary-key discovery, row fetch, row
// comparison) works end to end against real MySQL; genuine divergence
// detection is exercised directly, against in-memory rows, by the pure
// tests in ormharness/replay_test.go.

import (
	"fmt"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

func TestExplainTree_ReturnsPlanText(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormharness_explain"), "User")

	plan, err := ormharness.ExplainTree(db, "SELECT id FROM users WHERE id = ?", userID)
	require.NoError(t, err)
	assert.NotEmpty(t, plan)
}

func TestAssertPlanParity_IdenticalPrimaryKeyLookupsMatch(t *testing.T) {
	var planDest []map[string]interface{}
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormharness_planparity_match"), "User")

	report, err := ormharness.AssertPlanParity(db,
		"SELECT id FROM users WHERE id = ?", []any{userID},
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("users").Select("id").Where("id = ?", userID).Find(&planDest)
		},
	)
	require.NoError(t, err)
	assert.True(t, report.Equal, "two primary-key lookups on the same column should produce the same plan shape:\n%s", report.Diff())
}

func TestAssertPlanParity_DifferentAccessPathsMismatch(t *testing.T) {
	var planDest []map[string]interface{}
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("ormharness_planparity_mismatch"), "User")

	_, err := ormharness.AssertPlanParity(db,
		"SELECT id FROM users WHERE id = ?", []any{userID},
		func(tx *gorm.DB) *gorm.DB {
			// "id + 0" defeats MySQL's use of the primary key index for this
			// predicate, forcing a different access path from the old
			// statement's index lookup - a genuine plan-shape difference the
			// helper must catch, proving it is not just always passing.
			return tx.Table("users").Select("id").Where("id + 0 = ?", userID).Find(&planDest)
		},
	)
	assert.Error(t, err, "expected a genuinely different access path to be reported as a plan mismatch")
}

// setupUpsertFixture creates (if missing) the scratch table used to exercise
// the GORM clause.OnConflict WHERE-on-conflict-target trap this helper
// exists for (go-gorm/gorm#4355, go-gorm/mysql#39).
func setupUpsertFixture(t *testing.T) *gorm.DB {
	t.Helper()
	db := database.DBConn
	res := db.Exec(`CREATE TABLE IF NOT EXISTS ormharness_upsert_fixture (
		id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		counter INT NOT NULL,
		updated_at DATETIME NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	require.NoError(t, res.Error)
	return db
}

// TestRunUpsertParity_ConditionalCounterUpdate demonstrates the portable
// pattern a real converted upsert site should follow: the condition lives
// inside the SET expression (IF(...)), not in clause.OnConflict.Where, which
// is the part that is mishandled on some MySQL driver versions. Both cases
// - condition holds, condition fails - are needed, per UpsertCase's doc
// comment: a bug here would only show up on the branch where the update is
// supposed to be skipped.
//
// oldRowID/newRowID give the old and new paths independent rows to act on so
// that running both against a single physical test database still proves
// something: in the real replay runbook (two separate "yesterday" restores)
// old and new act on the same row in genuinely separate databases, but
// nothing here depends on that - RunUpsertParity only cares that Seed/Old/
// New/Snapshot behave consistently for whichever db handle they are given.
func TestRunUpsertParity_ConditionalCounterUpdate(t *testing.T) {
	db := setupUpsertFixture(t)

	cases := []struct {
		name        string
		seedCounter int
		newValue    int
		wantCounter int
	}{
		{"condition-holds", 5, 10, 10}, // 10 > 5: the update applies.
		{"condition-fails", 5, 3, 5},   // 3 > 5 is false: the row stays as seeded.
	}

	for i, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			base := uint64(time.Now().UnixNano()) + uint64(i)*10
			oldRowID := base + 1
			newRowID := base + 2

			t.Cleanup(func() {
				db.Exec("DELETE FROM ormharness_upsert_fixture WHERE id IN (?, ?)", oldRowID, newRowID)
			})

			// oldDB/newDB are the same physical connection, but each carries
			// its own row ID via Set/Get so the single shared Snapshot
			// closure below knows which row it is being asked about -
			// exactly the extra bookkeeping a real two-database replay
			// would not need, since there old and new naturally act on the
			// same row ID in different databases.
			oldDB := db.Set("ormharness_upsert_row_id", oldRowID)
			newDB := db.Set("ormharness_upsert_row_id", newRowID)

			tc := ormharness.UpsertCase{
				Name: c.name,
				Seed: func(db *gorm.DB) error {
					// Called once per side; idempotently seeds both rows so
					// whichever row Old/New is about to touch already has
					// the right starting state.
					for _, id := range []uint64{oldRowID, newRowID} {
						if err := db.Exec(
							"INSERT INTO ormharness_upsert_fixture (id, counter, updated_at) VALUES (?, ?, NOW()) "+
								"ON DUPLICATE KEY UPDATE counter = VALUES(counter), updated_at = VALUES(updated_at)",
							id, c.seedCounter).Error; err != nil {
							return err
						}
					}
					return nil
				},
				Old: func(db *gorm.DB) error {
					// MySQL has no native WHERE on ON DUPLICATE KEY UPDATE,
					// hence the IF() - this is the raw SQL a real site would
					// already have.
					return db.Exec(
						"INSERT INTO ormharness_upsert_fixture (id, counter, updated_at) VALUES (?, ?, NOW()) "+
							"ON DUPLICATE KEY UPDATE counter = IF(VALUES(counter) > counter, VALUES(counter), counter)",
						oldRowID, c.newValue).Error
				},
				New: func(db *gorm.DB) error {
					// The portable GORM idiom for the same thing: fold the
					// condition into DoUpdates' SET expression rather than
					// clause.OnConflict.Where.
					return db.Clauses(clause.OnConflict{
						Columns: []clause.Column{{Name: "id"}},
						DoUpdates: clause.Assignments(map[string]interface{}{
							"counter": gorm.Expr("IF(VALUES(counter) > counter, VALUES(counter), counter)"),
						}),
					}).Table("ormharness_upsert_fixture").Create(map[string]interface{}{
						"id":         newRowID,
						"counter":    c.newValue,
						"updated_at": gorm.Expr("NOW()"),
					}).Error
				},
				Snapshot: func(sideDB *gorm.DB) (any, error) {
					idVal, ok := sideDB.Get("ormharness_upsert_row_id")
					if !ok {
						return nil, fmt.Errorf("ormharness_upsert_row_id not set on the db handle passed to Snapshot")
					}
					var counter int
					err := sideDB.Raw("SELECT counter FROM ormharness_upsert_fixture WHERE id = ?", idVal).Scan(&counter).Error
					return counter, err
				},
			}

			err := ormharness.RunUpsertParity(oldDB, newDB, tc)
			require.NoError(t, err)

			var gotOld, gotNew int
			db.Raw("SELECT counter FROM ormharness_upsert_fixture WHERE id = ?", oldRowID).Scan(&gotOld)
			db.Raw("SELECT counter FROM ormharness_upsert_fixture WHERE id = ?", newRowID).Scan(&gotNew)
			assert.Equal(t, c.wantCounter, gotOld, "old raw-SQL path")
			assert.Equal(t, c.wantCounter, gotNew, "new GORM path")
		})
	}
}

func TestDiffTables_NoDifferencesWhenBothSidesReadTheSameData(t *testing.T) {
	db := database.DBConn
	res := db.Exec(`CREATE TABLE IF NOT EXISTS ormharness_diff_fixture (
		id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		note VARCHAR(64) NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	require.NoError(t, res.Error)

	base := uint64(time.Now().UnixNano())
	t.Cleanup(func() {
		db.Exec("DELETE FROM ormharness_diff_fixture WHERE id IN (?, ?)", base, base+1)
	})
	require.NoError(t, db.Exec(
		"INSERT INTO ormharness_diff_fixture (id, note) VALUES (?, 'a'), (?, 'b')", base, base+1).Error)

	diffs, err := ormharness.DiffTables(db, db, []string{"ormharness_diff_fixture"}, 10)
	require.NoError(t, err)
	assert.Empty(t, diffs, "the same live data read through two handles must never be reported as diverging:\n%s", ormharness.FormatTableDiffs(diffs))
}
