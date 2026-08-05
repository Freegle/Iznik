package test

// Wave 2 PILOT of the raw-SQL-to-ORM migration (plan section 7.3+): ten
// single-table WRITE sites, taken end to end, to prove writes survive the
// harness before the 561-site write backlog is attempted. Wave 0 and Wave 1
// proved SELECT; nothing before this pilot had exercised Update/Delete/Create
// against the harness at all.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Seven of the ten sites are converted below. The remaining three
// (698ab1090087, c1fca2fe89a0, da7e48606815) are INSERTs whose production
// code exists specifically to call sql.Result.LastInsertId() on the same
// connection that ran the INSERT - the read/write split makes a follow-up
// SELECT unsafe, and each site's own comment says so. GORM's map-valued
// Create CAN render matching SQL (see the notes at the bottom of this file),
// but writing back the new row's id relies on undocumented internal
// behaviour not covered by GORM's own test suite. That is exactly the kind
// of silent, expensive rewrite bug plan 7.5's keep-raw process exists to
// catch, so production was left untouched and the finding was reported
// instead of forced. See the message to the parent for the full writeup;
// tools/orm-migration/keep-raw.json already records the same reasoning for
// tryst.CreateTryst (an UPSERT needing LastInsertId) and for the
// database/insert.go helper these three sites structurally resemble.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- UPDATE -----------------------------------------------------------------

// team.go PatchTeam: sibling of site d0644aa6dbe0 (the team-name update
// converted in the Wave 0 pilot, orm_pilot_test.go) - same function, same
// Table()+Where()+Update(column, value) shape, one field over.
func TestWave2Pilot_0472cfcf52d2_TeamEmail(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0472cfcf52d2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams").Where("id = ?", 1).Update("email", "a@b.c")
	})
}

// admin.go PatchAdmin: text field.
func TestWave2Pilot_3a093ace39bb_AdminText(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3a093ace39bb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("text", "x")
	})
}

// user.go handleMerge: runs inside a *gorm.DB transaction (tx := db.Begin()),
// not on the plain connection. The dry-run build function takes whatever
// *gorm.DB it is handed and renders identically either way, so the site is
// safe to prove here even though production calls it on tx.
func TestWave2Pilot_4718b42d0c88_LogsUserRewrite(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4718b42d0c88", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Where("user = ?", 1).Update("user", 2)
	})
}

// group.go CreateGroup: `groups` is backtick-quoted in the golden because
// it's a MySQL reserved word. Table("groups") without backticks in the Go
// literal renders identically once through GORM's dialector, which quotes
// every identifier regardless, and Canonical() strips backticks from both
// sides before comparing - so the bare and quoted forms were never going to
// differ here. Proven the same way site 4eabda40530c (Wave 0) proved it for
// the reserved column name "key".
//
// Gate (h): group.go has a second, byte-identical "UPDATE `groups` SET
// lat = ? WHERE id = ?" in PatchGroup (line ~845, manifest site
// cf14153e46ac), left raw deliberately. tools/orm-migration/extract.go
// mints site IDs as sha256(file + "\x00" + goldenSQL + "\x00" + occurrence)
// where occurrence counts prior same-text matches scanned top-to-bottom in
// the file (extract.go ~line 365: "key := rel + sql; site.ID =
// stableID(key, seen[key])"). PatchGroup (845) comes first in the file, so
// it was occurrence 0 = cf14153e46ac; CreateGroup (970) was occurrence 1 =
// 194062f24f48. Converting the LATER occurrence and leaving the EARLIER one
// raw is the safe order: a re-scan still finds exactly one raw match, still
// at occurrence 0, so cf14153e46ac's identity survives. Converting them the
// other way round would have re-numbered cf14153e46ac out from under
// whatever test references it next.
func TestWave2Pilot_194062f24f48_GroupLat(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "194062f24f48", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("lat", 51.5)
	})
}

// --- DELETE -------------------------------------------------------------

// user.go handleMerge: hard-delete of the discarded account after commit.
// User.Deleted is a plain *time.Time (json:"deleted"), not gorm.DeletedAt,
// so GORM has no soft-delete field to find even via a model; Table()+
// Delete(nil) additionally never resolves a schema at all (Dest is nil, so
// processor.Execute's stmt.Parse step never runs), which is what keeps this
// a hard DELETE regardless of what the model looks like today or later.
func TestWave2Pilot_55a57788c875_DeleteUser(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "55a57788c875", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Delete(nil)
	})
}

// tryst.go DeleteTryst: Tryst carries no soft-delete field either.
func TestWave2Pilot_8d87c99d21c7_DeleteTryst(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d87c99d21c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("trysts").Where("id = ?", 1).Delete(nil)
	})
}

// admin.go DeleteAdmin: Admin carries no soft-delete field either.
func TestWave2Pilot_c6a830b48e43_DeleteAdmin(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c6a830b48e43", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Delete(nil)
	})
}

// --- INSERT: deliberately NOT converted --------------------------------

// Sites 698ab1090087 (message.go findOrCreateUserForDraft), c1fca2fe89a0
// (user/partner.go CreatePartnerUser) and da7e48606815 (export.go
// PostExport) are left on sqlDB.Exec in production. All three exist only to
// get a LastInsertId() that is guaranteed correct under the read/write
// split, and hand-tracing gorm@v1.31.0 shows the GORM path for that is not
// solid enough to trust here:
//
//   - .Table("users").Create(map[string]interface{}{"added": gorm.Expr("NOW()")})
//     WOULD render "INSERT INTO users (added) VALUES (NOW())", an exact
//     match for 698ab1090087's golden. Single key, so map key sort order
//     (callbacks/helper.go ConvertMapToValuesForCreate, sort.Strings) can't
//     move it.
//
//   - The two-column sites reorder under that same alphabetical sort:
//     c1fca2fe89a0's golden is "(fullname, added) VALUES (?, NOW())" but a
//     map create renders "(added, fullname) VALUES (NOW(), ?)" ("added" <
//     "fullname"); da7e48606815's golden is "(userid, tag) VALUES (?, ?)"
//     but a map create renders "(tag, userid) VALUES (?, ?)" ("tag" <
//     "userid"). Both are the known map-Create column-reorder case flagged
//     going in - semantically identical, needs an approvedDiff, not a
//     forced rewrite.
//
//   - The blocking issue is separate from SQL shape: retrieving the new
//     row's id from a map Create depends on gorm@v1.31.0
//     callbacks/create.go's Create() writing the primary key back into the
//     Dest map under the literal key "@id" (schema-less Table()+map path)
//     or under the real DBName if a real model is also chained in via
//     .Model(&user.User{}) (schema-parsed path, pkFieldName taken from
//     PrioritizedPrimaryField.DBName). Both are real behaviour in this
//     version - verified by reading the source, not assumed - but neither
//     is covered by GORM's own test suite, and GORM's public docs for
//     "Create From Map" say plainly that a map create does NOT back-fill
//     the primary key. Relying on undocumented behaviour that contradicts
//     the documented behaviour, for the exact value (a fresh user id) that
//     these three sites were written in raw SQL to protect, is the kind of
//     silent-and-expensive rewrite bug plan 7.5's keep-raw process exists
//     for. database/insert.go (ExecInsertGetID) is the project's own
//     already-reviewed answer to "INSERT + safe LastInsertId": deliberately
//     raw SQL on the source connection, not GORM. These three sites
//     predate that helper and duplicate its logic inline; the recommended
//     follow-up is pointing them at ExecInsertGetID (a dedup, not an ORM
//     conversion) and adding all three to keep-raw.json with this
//     reasoning, rather than converting them to Create().

// cf14153e46ac is byte-identical to 194062f24f48 and lives in the same file
// (PatchGroup vs CreateGroup). Gate (h) refuses a half-converted pair, because
// converting one renumbers the survivor's site ID and rebinds its parity test.
func TestWave2Pilot_cf14153e46ac(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cf14153e46ac", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("lat", 51.5)
	})
}
