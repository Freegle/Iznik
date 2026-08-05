package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the newsfeed module's batch:
// iznik-server-go/newsfeed/convertinfo.go, create.go, duplicate.go and
// newsfeed.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// Every wave-1 site in this module converted; none were skipped. The
// module's remaining raw SQL (newsfeed.go's union-of-subquery feed builders
// with FORCE INDEX, and the moderate-complexity joins at lines 891 and 1347)
// is wave 4/5 and out of scope here.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- convertinfo.go: ConvertInfo --------------------------------------------

func TestWave1Newsfeed_7684033bc776(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7684033bc776", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid").Where("id = ? AND deleted IS NULL", 1).Find(&dest)
	})
}

// --- create.go: CreateNewsfeedEntry -----------------------------------------

func TestWave1Newsfeed_f615ed45438f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f615ed45438f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("lat, lng").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Newsfeed_9c0779fdc3cc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9c0779fdc3cc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(newsfeedmodstatus, 'Unmoderated')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Newsfeed_606016a06713(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "606016a06713", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("userid = ? AND collection = ?", 1, "Spammer").Count(&dest)
	})
}

func TestWave1Newsfeed_94168ea2d29c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "94168ea2d29c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("`type`").Where("userid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1Newsfeed_def955a54c71(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "def955a54c71", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("nameshort").Where("id = ?", 1).Find(&dest)
	})
}

// --- duplicate.go: Duplicate -------------------------------------------------

func TestWave1Newsfeed_ac4a5e5f4cf9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ac4a5e5f4cf9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid, COALESCE(message, '') AS message, type").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: fetchSingle -----------------------------------------------

func TestWave1Newsfeed_a01ad5c474b4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a01ad5c474b4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("type").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Newsfeed_fa1ef7660b85(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "fa1ef7660b85", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_likes").Where("newsfeedid = ?", 1).Count(&dest)
	})
}

func TestWave1Newsfeed_93a1565d8106(t *testing.T) {
	// Production keeps Row().Scan(&loved) into a bool (see newsfeed.go); the
	// parity test still uses Count, since Layer 1 only compares rendered SQL
	// text and Count is the terminal the harness accepts under dry-run.
	var dest int64
	ormharness.AssertGoldenSQL(t, "93a1565d8106", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_likes").Where("newsfeedid = ? AND userid = ?", 1, 1).Count(&dest)
	})
}

func TestWave1Newsfeed_2900c82bc4b2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2900c82bc4b2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_likes").Where("newsfeedid = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: fetchReplies -----------------------------------------------

func TestWave1Newsfeed_496dfed25e99(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "496dfed25e99", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("id").Where("replyto = ? AND deleted IS NULL", 1).Order("timestamp ASC").Find(&dest)
	})
}

// --- newsfeed.go: Count -------------------------------------------------------

func TestWave1Newsfeed_4b16cf99872b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4b16cf99872b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_users").Select("newsfeedid").Where("userid = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: canModifyPost -----------------------------------------------

func TestWave1Newsfeed_7cf110b6d96b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7cf110b6d96b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Newsfeed_9136a0c1eb27(t *testing.T) {
	// Two explicit `?` placeholders inside a literal `IN (?, ?)`, bound to two
	// scalar args (ROLE_MODERATOR, ROLE_OWNER) - not the slice-bound `IN ?`
	// shape parked in wave 5, so the shape carries over to GORM unchanged.
	var dest int64
	ormharness.AssertGoldenSQL(t, "9136a0c1eb27", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").Count(&dest)
	})
}

// --- newsfeed.go: Post / "Love" -----------------------------------------------

func TestWave1Newsfeed_1da76e2ebae6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1da76e2ebae6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid, replyto").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: Post / "Seen" -----------------------------------------------

func TestWave1Newsfeed_f6196dd0e38d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f6196dd0e38d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_users").Select("newsfeedid").Where("userid = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: Post / "AttachToThread" -------------------------------------

func TestWave1Newsfeed_5fc8acdf88b8(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "5fc8acdf88b8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").Count(&dest)
	})
}

// --- newsfeed.go: Post / "ConvertToStory" -------------------------------------

func TestWave1Newsfeed_6b08e5e232dc(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "6b08e5e232dc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

func TestWave1Newsfeed_0baa90745699(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0baa90745699", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid, message").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: createPost --------------------------------------------------

func TestWave1Newsfeed_0a09af7a9caf(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "0a09af7a9caf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", 1, "PendingAdd", "Spammer").Count(&dest)
	})
}

func TestWave1Newsfeed_4a4ca7275daa(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4a4ca7275daa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(newsfeedmodstatus, '')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Newsfeed_aa81c1da64cb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "aa81c1da64cb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("id, replyto, type, message").Where("userid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}

// --- newsfeed.go: bumpThread --------------------------------------------------

func TestWave1Newsfeed_084c277ca6e1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "084c277ca6e1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("replyto").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: notifyThreadContributors ------------------------------------

func TestWave1Newsfeed_23edb49d13f9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "23edb49d13f9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("id, userid, timestamp").Where("replyto = ? OR id = ?", 1, 1).Find(&dest)
	})
}

// --- newsfeed.go: createRefer -------------------------------------------------

func TestWave1Newsfeed_b8962955bf49(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b8962955bf49", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: Edit ---------------------------------------------------------

func TestWave1Newsfeed_06b4f286eaca(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "06b4f286eaca", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

// --- newsfeed.go: Delete ---------------------------------------------------------

func TestWave1Newsfeed_fb21f433b4d6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fb21f433b4d6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}
