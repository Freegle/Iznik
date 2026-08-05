package test

// The 11 sites the adversarial review's §5 found invisible to the extractor
// entirely: database.RetryExec/RetryExecResult/RetryQuery/ExecInsertGetID
// take SQL as an argument and run it internally, so the extractor (which only
// recognised calls by method name on db.Raw/db.Exec) never inventoried
// anything routed through them. The extractor now recognises these four
// wrappers too, which is what surfaced these 11 production statements.
//
// This batch, converted the same "INSERT then read the id back" way as
// test/orm_insertid_convert_test.go, PLUS the ON DUPLICATE KEY UPDATE ...
// id = LAST_INSERT_ID(id) idiom for the two message.go items sites and
// isochrone.go's isochrones_users link, using Clauses(gorm.WithResult()) -
// NOT row["@id"] - per the RowsAffected==0 trap proven in
// test/orm_insertid_test.go's WithResultBeatsTheRowsAffectedZeroTrap: GORM's
// own map writeback is skipped when MySQL reports 0 rows affected, which it
// does on every no-op duplicate-key hit, exactly the common case for these
// two ODKU-with-id-forcing sites.
//
// Three sites in the same 11 do NOT fit any pattern this worktree has a
// proven, testable mechanism for yet, and were left raw with reasons in
// keep-raw.json instead: message/markseen.go's insertViewBatch (a
// runtime-determined, effectively-unbounded multi-row INSERT - no fixed
// golden and no parametrized-shape assertion type exists), isochrone.go's
// EnsureIsochroneExists location-geometry fallback (a genuine INSERT ...
// SELECT), and address.go's Create (REPLACE INTO). All three need either a
// new harness capability or new, currently-unverified GORM clause-override
// infrastructure this worktree has no working/tested example of.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- user/user.go: handleAddEmail (via database.ExecInsertGetID) --------------

func TestInsertIDConv_1c763aa6ec12(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1c763aa6ec12", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":    1,
			"email":     "a@example.org",
			"preferred": 1,
			"validated": gorm.Expr("NOW()"),
			"canon":     "a@example.org",
			"backwards": "gro.elpmaxe@a",
		}
		return tx.Table("users_emails").Create(row)
	})
}

// --- donations/stripeipn.go: handleChargeSucceeded (via ExecInsertGetID) ------

func TestInsertIDConv_1d13aa15278e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1d13aa15278e", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":           1,
			"Payer":            "a@example.org",
			"PayerDisplayName": "A Donor",
			"timestamp":        "2026-01-01 12:00:00",
			"TransactionID":    "ch_123",
			"GrossAmount":      10.0,
			"source":           "Stripe",
			"TransactionType":  "subscr_payment",
			"type":             "Stripe",
		}
		return tx.Table("users_donations").Create(row)
	})
}

// --- newsfeed/newsfeed.go: Post (mod create-story action, via ExecInsertGetID) -

func TestInsertIDConv_64439d15a9ad(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "64439d15a9ad", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":       1,
			"headline":     gorm.Expr("''"),
			"story":        "story text",
			"date":         gorm.Expr("NOW()"),
			"fromnewsfeed": gorm.Expr("1"),
		}
		return tx.Table("users_stories").Create(row)
	})
}

// --- message/message.go: PutMessageAs (items, ODKU id=LAST_INSERT_ID(id)) -----

func TestInsertIDConv_3cbad581b884(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3cbad581b884", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").Clauses(gorm.WithResult(), clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"name": "Chair"})
	})
}

// --- message/message.go: applyPatchMessageCore (items, same shape) -------------

func TestInsertIDConv_7c79dc685e02(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7c79dc685e02", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("items").Clauses(gorm.WithResult(), clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"name": "Chair"})
	})
}

// --- message/markseen.go: MarkSeen (per-row fallback after a chunk deadlock) --

func TestInsertIDConv_61e26594c74d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "61e26594c74d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_likes").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"count":     gorm.Expr("count + 1"),
			}),
		}).Create(map[string]interface{}{
			"msgid":    1,
			"userid":   2,
			"type":     "View",
			"pageview": gorm.Expr("0"),
			"source":   "similar_posts",
		})
	})
}

// --- isochrone/isochrone.go: EnsureIsochroneExists (provider-fetched polygon) --

func TestInsertIDConv_d91a1a5d6b27(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d91a1a5d6b27", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"locationid": 1,
			"transport":  "car",
			"minutes":    15,
			"source":     "RoutingServer",
			"polygon": gorm.Expr("CASE WHEN ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) IS NULL THEN ST_GeomFromText(?, ?) ELSE ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) END",
				"POLYGON(...)", 3857, "POLYGON(...)", 3857, "POLYGON(...)", 3857),
		}
		return tx.Table("isochrones").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(row)
	})
}

// --- isochrone/isochrone.go: CreateIsochrone (link user, ODKU with id-forcing) -

func TestInsertIDConv_79e591242114(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "79e591242114", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Clauses(gorm.WithResult(), clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "nickname"}, Value: clause.Column{Table: "excluded", Name: "nickname"}},
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{
			"userid":      1,
			"isochroneid": 2,
			"nickname":    "Home",
		})
	})
}
