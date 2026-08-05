package test

// Two keep-raw sites whose recorded reasons went stale once harness
// capability the reason cited as missing was actually built:
//
//   - 990cc13deb7e (address/address.go: Create) - REPLACE INTO. The reason
//     predates database.RegisterCustomClauseBuilders (database/clausebuilders.go),
//     which now makes clause.Insert{Modifier: "REPLACE"} render a clean
//     "REPLACE INTO ..." instead of the invalid "INSERT REPLACE INTO ..." the
//     reason described. Same shape as the 11 REPLACE INTO sites converted in
//     Tier 4 (test/orm_tier4_test.go).
//
//   - 74620d093074 (isochrone/isochrone.go: EnsureIsochroneExists, the
//     both-providers-unavailable fallback) - INSERT ... SELECT. The reason
//     predates database.InsertSelect (database/clausebuilders.go), which now
//     makes this shape reachable through GORM's ordinary Create() pipeline.
//     Same mechanism as the 7 INSERT ... SELECT sites converted in Tier 4;
//     this one layers INSERT IGNORE on top via clause.Insert{Modifier:
//     "IGNORE"} (never clause.OnConflict{DoNothing:true}, which renders a
//     dangling ON DUPLICATE KEY UPDATE when Statement.Schema is nil - see the
//     sibling INSERT a few lines above it in isochrone.go, site d91a1a5d6b27,
//     which already proved plain "INSERT IGNORE INTO ..." renders correctly).

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// address/address.go: Create.
func TestStaleKeepRaw_990cc13deb7e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "990cc13deb7e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_addresses").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"userid": 1, "pafid": 2, "instructions": "leave by the door", "lat": 51.5, "lng": -0.1,
			})
	})
}

// isochrone/isochrone.go: EnsureIsochroneExists, location-geometry fallback
// used when both isochrone providers (routing server and Mapbox) fail.
// gorm.WithResult() reads the generated id back from the same sql.Result the
// INSERT returned, matching the original ExecInsertGetID's res.LastInsertId()
// exactly - including staying 0 when INSERT IGNORE skips a pre-existing row,
// which the caller's isoID == 0 fallback lookup still depends on.
func TestStaleKeepRaw_74620d093074(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "74620d093074", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return database.InsertSelect(tx.Clauses(res, clause.Insert{Modifier: "IGNORE"}), "isochrones",
			"(locationid, transport, minutes, polygon) "+
				"SELECT ?, ?, ?, COALESCE(geometry, ST_GeomFromText(CONCAT('POINT(', lng, ' ', lat, ')'), ?)) FROM locations WHERE id = ?",
			42, "Walk", 15, 3857, 42)
	})
}

// message/message.go: GetMessagesByIds' rippling probe. A bare
// SELECT EXISTS(...) with no top-level FROM. GORM's query callback always
// registers a FROM clause, but Statement.Build renders only the clause NAMES
// it is handed, so setting BuildClauses to {"SELECT"} emits the SELECT alone
// and leaves the registered-but-unwalked FROM out - see
// ormharness/bareexists_test.go, which established this for the whole
// bare-EXISTS category.
//
// This site was briefly converted a different way, by selecting from a one-row
// derived table and recording the extra FROM as an approved diff. That passed,
// but it changed the executed SQL to work around a limitation that does not
// actually exist, and an approved diff is meant for a divergence that could not
// be avoided rather than one that was chosen. The form below renders
// byte-identically to the original, so the approved-diff entry was removed.
//
// Production keeps `.Scan(&anyReach)` (a single scalar); this test uses
// `.Find(&dest)` purely to trigger the render, because Scan goes through
// GORM's Row/Rows path, which returns ErrDryRunModeUnsupported and so renders
// nothing to compare. Same accommodation, for the same reason, as the
// noticeboard.go and message.go sites documented in orm_wave1_admin_test.go.
func TestStaleKeepRaw_c9ff161437b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c9ff161437b9", func(tx *gorm.DB) *gorm.DB {
		var dest []int
		q := tx.Table("rippling_reach").
			Select("EXISTS(SELECT 1 FROM rippling_reach WHERE msgid IN ?)", []uint64{1, 2, 3})
		q.Statement.BuildClauses = []string{"SELECT"}
		return q.Find(&dest)
	})
}
