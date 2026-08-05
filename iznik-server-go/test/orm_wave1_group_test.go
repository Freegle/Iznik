package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the group module's batch:
// iznik-server-go/group/group.go and iznik-server-go/group/groupWork.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Four sites from this module's wave-1 inventory are deliberately NOT
// converted and have no test here: 9494e3480fa0 and a7496f46878c
// (group.go), 1de7b48d433b and de52b33ad2c2 (groupWork.go). All four bind a
// Go slice to a single "IN ?" placeholder; the golden SQL records the
// literal source text "IN ?", but GORM's dry-run expands a slice argument to
// "IN (?,?,?,...)", which is a real divergence the harness has no
// approvedDiff entry for yet. They are left as raw SQL.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- group.go: GetGroup / getMultipleGroups ---------------------------

func TestWave1Group_21406c23a191(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "21406c23a191", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups_sponsorship").
			Where("groupid = ? AND startdate <= NOW() AND enddate >= DATE(NOW()) AND visible = 1", 1).
			Order("amount DESC").
			Find(&dest)
	})
}

func TestWave1Group_7c5c81bc5dc0(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7c5c81bc5dc0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("poly, polyofficial").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Group_06597ffa764d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "06597ffa764d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 1, "Approved").
			Find(&dest)
	})
}

func TestWave1Group_01adb146166c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "01adb146166c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").
			Where("userid = ?", 1).
			Order("preferred DESC, id ASC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1Group_3f55e9081ae4(t *testing.T) {
	// Same shape as 06597ffa764d: getMultipleGroups' per-group myrole lookup.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3f55e9081ae4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 1, "Approved").
			Find(&dest)
	})
}

// --- group.go: ListGroups ----------------------------------------------

func TestWave1Group_1a4bd532caa4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1a4bd532caa4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").
			Select("id, nameshort, namefull, lat, lng, altlat, altlng, onmap, onhere, ontn, onlovejunk, publish, region, contactmail, mentored, "+
				"CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, "+
				"founded, lastmoderated, lastmodactive, lastautoapprove, activeownercount, activemodcount, "+
				"backupmodsactive, backupownersactive, affiliationconfirmed, affiliationconfirmedby").
			Where("type = ?", "Freegle").
			Find(&dest)
	})
}

func TestWave1Group_d7629b3fa332(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d7629b3fa332", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").
			Select("id, nameshort, namefull, lat, lng, onmap, onhere, ontn, onlovejunk, publish, region, contactmail, mentored, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin").
			Where("publish = 1 AND onhere = 1 AND type = ?", "Freegle").
			Find(&dest)
	})
}

func TestWave1Group_f207e41516c4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f207e41516c4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Select("COUNT(*) AS count, groupid").
			Where("timestamp >= ? AND type = ? AND subtype = ?", "2026-01-01", "Message", "Autoapproved").
			Group("groupid").
			Find(&dest)
	})
}

func TestWave1Group_a1d28f99a959(t *testing.T) {
	// Same shape as f207e41516c4, different subtype literal.
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a1d28f99a959", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Select("COUNT(*) AS count, groupid").
			Where("timestamp >= ? AND type = ? AND subtype = ?", "2026-01-01", "Message", "Approved").
			Group("groupid").
			Find(&dest)
	})
}

func TestWave1Group_9ab327a70a09(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9ab327a70a09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Select("groupid, SUM(approvedby IS NOT NULL) AS moderated_count, COUNT(*) AS total_count").
			Where("arrival >= ?", "2026-01-01").
			Group("groupid").
			Find(&dest)
	})
}

// --- group.go: PatchGroup / CreateGroup ---------------------------------

func TestWave1Group_88ec4f8b3364(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "88ec4f8b3364", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Count(&dest)
	})
}

func TestWave1Group_fcf7a3fd9364(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "fcf7a3fd9364", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Owner", "Moderator").Count(&dest)
	})
}

// --- groupWork.go: GetGroupWork -----------------------------------------

func TestWave1Group_dfcf063d9320(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "dfcf063d9320", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid, settings").
			Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").
			Find(&dest)
	})
}
