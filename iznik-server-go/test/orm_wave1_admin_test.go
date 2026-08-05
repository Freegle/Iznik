package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), covering four small modules
// together: iznik-server-go/{admin,auth,noticeboard,merge}.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// All 29 raw-SQL sites recorded for these four modules in wave 1 are
// converted here - none needed to be skipped. Two sites in noticeboard.go
// (17992b4893d5, 2719baff5f09) select several columns in one statement and
// keep `.Row().Scan(...)` in production, which is not one of the terminal
// calls AssertGoldenSQL's build function accepts (Find, Count, Create,
// Delete, Update, Take, First - see golden.go); their tests below use
// `.Find(&dest)` instead purely to trigger the render, matching the same
// pattern already used for this shape in message.go (site 7841a4655468,
// orm_wave1_message_core_test.go).
//
// auth.go and merge.go read and act on account identity and merge
// authorisation - WHERE-clause text is checked verbatim by Layer 1 (this
// file), since a subtly different predicate here would let one account's
// data leak into another's session or merge flow, not just look wrong.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- admin.go: GetAdmin ------------------------------------------------------

func TestWave1Admin_0ddc492f685c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0ddc492f685c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("id, createdby, groupid, subject, text, ctatext, ctalink, created, complete, heldby, pending, essential, template, editprotected").Where("id = ?", 1).Find(&dest)
	})
}

// --- admin.go: PostAdmin Hold/Release, PatchAdmin, DeleteAdmin group checks -

func TestWave1Admin_6eb1be4ff453(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6eb1be4ff453", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_0ebce3bdec81(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0ebce3bdec81", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_8607a46d5c6f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8607a46d5c6f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_a1ff219a08d7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a1ff219a08d7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", 1).Find(&dest)
	})
}

// --- admin.go: adminHeldByAnother --------------------------------------------

func TestWave1Admin_be61683f2a10(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "be61683f2a10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Select("COALESCE(heldby, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_619fb338bc20(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "619fb338bc20", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

// --- auth.go: WhoAmI old-style persistent token lookup -----------------------

func TestWave1Admin_e57197b50601(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e57197b50601", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Select("userid").Where("id = ? AND token = ?", 1, "tok").Limit(1).Find(&dest)
	})
}

// --- auth.go: IsAdminOrSupport / IsAdmin / IsSystemMod ------------------------

func TestWave1Admin_e449ae451594(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e449ae451594", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_2c0bbedc0eb2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2c0bbedc0eb2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_135b17140f36(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "135b17140f36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("systemrole").Where("id = ?", 1).Find(&dest)
	})
}

// --- auth.go: HasPermission ---------------------------------------------------

func TestWave1Admin_2f4a87c7cb53(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2f4a87c7cb53", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("permissions").Where("id = ?", 1).Find(&dest)
	})
}

// --- auth.go: IsModOfGroup / IsModOfAnyGroup ----------------------------------

func TestWave1Admin_a8691b8ac0e6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a8691b8ac0e6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").Where("userid = ? AND groupid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1Admin_401a7aa34330(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "401a7aa34330", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").Count(&dest)
	})
}

// --- auth.go: VerifyPassword ---------------------------------------------------

func TestWave1Admin_8d14c119b9c8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8d14c119b9c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("credentials, salt").Where("userid = ? AND type = ?", 1, "Native").Order("lastaccess DESC").Find(&dest)
	})
}

// --- merge.go: fetchLoginsForUser ---------------------------------------------

func TestWave1Admin_d42efd0197f3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d42efd0197f3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Select("id, userid, type, CAST(uid AS CHAR) AS uid, added, lastaccess").
			Where("userid = ?", 1).Order("lastaccess DESC").Find(&dest)
	})
}

// --- merge.go: GetMerge --------------------------------------------------------

func TestWave1Admin_df35e6ee770d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "df35e6ee770d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("merges").Select("id, user1, user2, uid, accepted, rejected").Where("id = ? AND uid = ?", 1, "u").Find(&dest)
	})
}

func TestWave1Admin_7e64501cc8fd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7e64501cc8fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, 'A freegler')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_4655e85d1bb5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4655e85d1bb5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("COALESCE(email, '')").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

func TestWave1Admin_1ba3ae16b6a7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1ba3ae16b6a7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, 'A freegler')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_a3be2bf2b81f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a3be2bf2b81f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("COALESCE(email, '')").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

// --- merge.go: PostMerge -------------------------------------------------------

func TestWave1Admin_8d9acac47724(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8d9acac47724", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("merges").Select("id, user1, user2, uid").Where("id = ? AND uid = ?", 1, "u").Find(&dest)
	})
}

// --- noticeboard.go: getSingle --------------------------------------------------

func TestWave1Admin_92d2a939593e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "92d2a939593e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Select("id, name, lat, lng, added, addedby, description, active, lastcheckedat").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_3bcf101e10b0(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3bcf101e10b0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_checks").Select("id, userid, askedat, checkedat, inactive, refreshed, declined, comments").Where("noticeboardid = ?", 1).Order("id DESC").Find(&dest)
	})
}

func TestWave1Admin_49eee194e249(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "49eee194e249", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards_images").Select("id").Where("noticeboardid = ?", 1).Limit(1).Find(&dest)
	})
}

// --- noticeboard.go: getList (no authority filter) -------------------------------

func TestWave1Admin_b62e9af236c5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b62e9af236c5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Select("id, name, lat, lng").Where("name IS NOT NULL AND active = 1").Find(&dest)
	})
}

// --- noticeboard.go: PatchNoticeboard exists/auth check and newsfeed lookup ------
//
// Production keeps `.Row().Scan(...)` for these two multi-column selects
// (Row is not one of AssertGoldenSQL's accepted terminals - see golden.go and
// this file's header), so the test below uses `.Find(&dest)` purely to
// trigger the render; the rendered SELECT text is identical either way.

func TestWave1Admin_17992b4893d5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "17992b4893d5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Select("COUNT(*), COALESCE(name, ''), COALESCE(addedby, 0)").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Admin_2719baff5f09(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2719baff5f09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Select("COALESCE(addedby, 0), COALESCE(lat, 0), COALESCE(lng, 0)").Where("id = ?", 1).Find(&dest)
	})
}

// --- noticeboard.go: DeleteNoticeboard existence check ----------------------------

func TestWave1Admin_6f1e9fffdf7e(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "6f1e9fffdf7e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("noticeboards").Where("id = ?", 1).Count(&dest)
	})
}
