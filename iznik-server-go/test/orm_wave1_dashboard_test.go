package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the dashboard module's batch:
// iznik-server-go/dashboard/dashboard.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Five sites from this module's wave-1 inventory are deliberately NOT
// converted and have no test here: db58dba433f8, 770ce1ca6e09, 382a6666f320,
// 42a6b16c1a09 and 114e3d2c526c. All five bind groupIDs (a Go []uint64) to a
// literal "groupid IN (?)" placeholder; GORM's dry-run substitutes the slice
// element-by-element into that same "?", so the rendered SQL carries as many
// placeholders as the slice has elements, whereas the golden text records a
// single "?" - a real, length-dependent divergence the harness has no
// approvedDiff entry for yet. Same shape already skipped six times across
// the group and membership modules; see those modules' wave 1 test files.
// They are left as raw SQL.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- getPopularPosts: reply count for a post -------------------------------

func TestWave1Dashboard_ed34569d9b36(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "ed34569d9b36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("refmsgid = ?", 1).Count(&dest)
	})
}

// --- getUsersPosting / getUsersReplying / getModeratorsActive: display name lookup ---

func TestWave1Dashboard_3efc6311d1fb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3efc6311d1fb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Dashboard_afe6a06e35d5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "afe6a06e35d5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Dashboard_1f7c9782b252(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1f7c9782b252", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- getDonations: systemwide donation totals by day ------------------------

func TestWave1Dashboard_43bd10f45284(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "43bd10f45284", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").
			Select("SUM(GrossAmount) AS count, DATE(timestamp) AS date").
			Where("timestamp >= ? AND timestamp <= ?", "2026-01-01", "2026-02-01").
			Group("date").
			Order("date ASC").
			Find(&dest)
	})
}

// --- getHappiness: systemwide outcome happiness breakdown -------------------

func TestWave1Dashboard_0fdf3ccacd34(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0fdf3ccacd34", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").
			Select("COUNT(*) AS count, happiness").
			Where("timestamp >= ? AND timestamp <= ? AND happiness IS NOT NULL", "2026-01-01", "2026-02-01").
			Group("happiness").
			Order("count DESC").
			Find(&dest)
	})
}

// --- resolveGroupIDs: systemwide group list, and a mod's own groups ---------

func TestWave1Dashboard_7b7dd94d9688(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7b7dd94d9688", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("id").
			Where("publish = 1 AND onhere = 1").
			Find(&dest)
	})
}

func TestWave1Dashboard_b9033fc5427a(t *testing.T) {
	// Two explicit `?` placeholders inside a literal `IN (?, ?)`, bound to two
	// scalar args (ROLE_MODERATOR, ROLE_OWNER) - not the `groupid IN (?)`
	// slice shape this batch is skipping elsewhere, so the shape carries over
	// to GORM unchanged.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b9033fc5427a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").
			Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").
			Find(&dest)
	})
}
