package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the volunteering group's
// batch: iznik-server-go/{communityevent,microvolunteering,volunteering}.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Every wave-1 raw site in this module's inventory converts cleanly: none of
// the "IN ?" slice-binding or multi-column INSERT-from-map shapes that force
// a skip elsewhere in this wave show up here (nearest example: session,
// dashboard, group, membership and user modules' wave 1 test files).
//
// COUNT(*) sites use Count(&dest) with dest int64, matching the production
// code's own destination type; every other site uses Find(&dest) with
// dest []map[string]interface{}, per this package's Layer 1 convention
// (Scan is rejected under dry-run - see golden.go).

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- communityEvent.go -------------------------------------------------

func TestWave1Vol_b91b1dace445(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b91b1dace445", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_images").Select("id, archived, externaluid, externalmods").
			Where("eventid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1Vol_03a8e9d4e6f9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "03a8e9d4e6f9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_groups").Select("groupid").Where("eventid = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_d0fb8ea8e991(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d0fb8ea8e991", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_dates").Where("eventid = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_fb639d4f1343(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fb639d4f1343", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_c722e5c178bc(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "c722e5c178bc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").Count(&dest)
	})
}

func TestWave1Vol_960df0d64e88(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "960df0d64e88", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Select("COALESCE(heldby, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_20615e06821f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "20615e06821f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_374932713da4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "374932713da4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_f47f012d1d3f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f47f012d1d3f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_376e4b3a5941(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "376e4b3a5941", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

// --- microvolunteering.go -----------------------------------------------

func TestWave1Vol_2d399fd44a2c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2d399fd44a2c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(trustlevel, ?)", "Basic").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_fa53f46653e6(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "fa53f46653e6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").
			Where("userid = ? AND actiontype = ? AND DATEDIFF(NOW(), timestamp) < 31", 1, "Invite").
			Count(&dest)
	})
}

func TestWave1Vol_5a4706a34749(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "5a4706a34749", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").
			Where("msgid = ? AND result = 'Reject' AND comments IS NOT NULL AND (msgcategory IS NULL OR msgcategory = 'ShouldntBeHere')", 1).
			Count(&dest)
	})
}

func TestWave1Vol_c94e3bfae9b3(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "c94e3bfae9b3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("rotatedimage = ? AND result = 'Reject'", 1).Count(&dest)
	})
}

func TestWave1Vol_85e57d30b7c7(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "85e57d30b7c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid = ? AND rippled_in = 0 AND deleted = 0 AND collection = ?", 1, "Approved").
			Count(&dest)
	})
}

func TestWave1Vol_2b059ba266dc(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "2b059ba266dc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ? AND role IN (?, ?)", 1, 2, "Moderator", "Owner").Count(&dest)
	})
}

func TestWave1Vol_1514d35d670c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1514d35d670c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_bc4e3f39c868(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "bc4e3f39c868", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").
			Where("msgid = ? AND result = 'Reject' AND comments IS NOT NULL AND (msgcategory IS NULL OR msgcategory = 'ShouldntBeHere')", 1).
			Count(&dest)
	})
}

func TestWave1Vol_253bc6651f22(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "253bc6651f22", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("aiimageid = ? AND actiontype = ?", 1, "AIImageReview").Count(&dest)
	})
}

func TestWave1Vol_4a4e6ef7b504(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "4a4e6ef7b504", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").
			Where("aiimageid = ? AND actiontype = ? AND result = 'Reject'", 1, "AIImageReview").
			Count(&dest)
	})
}

func TestWave1Vol_bb15df86ae5f(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "bb15df86ae5f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").Where("aiimageid = ? AND actiontype = ?", 1, "AIImageReview").Count(&dest)
	})
}

func TestWave1Vol_c011457f1962(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "c011457f1962", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("microactions").
			Where("aiimageid = ? AND actiontype = ? AND result = 'Suppress'", 1, "AIImageReview").
			Count(&dest)
	})
}

// --- volunteering.go ------------------------------------------------------

func TestWave1Vol_fff128f61679(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fff128f61679", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_images").Select("id, archived, externaluid, externalmods").
			Where("opportunityid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1Vol_e0cd4a54dacc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e0cd4a54dacc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_groups").Select("groupid").Where("volunteeringid = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_b0844c29851e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b0844c29851e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering_dates").Where("volunteeringid = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_e12bc9781fda(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e12bc9781fda", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_f4ea5e89a6aa(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "f4ea5e89a6aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").Count(&dest)
	})
}

func TestWave1Vol_fe225e945349(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fe225e945349", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Select("COALESCE(heldby, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_eb24d79c1868(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "eb24d79c1868", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_757206d64631(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "757206d64631", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_51ccb0455fec(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "51ccb0455fec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Vol_c9633d8edcc8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c9633d8edcc8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("volunteering").Select("id").Where("id = ?", 1).Find(&dest)
	})
}
