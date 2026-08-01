package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3), membership
// module: iznik-server-go/membership/membership.go.
//
// Each test names the site ID it proves, which is what plan 7.2's Gate 2
// checks mechanically - a converted site's raw SQL no longer exists in the
// source, so the extractor can only tell "converted" apart from "silently
// deleted" by finding a parity test that names the ID.
//
// Three sites in this module were left raw and are not tested here:
// `userid IN ?` bound to a Go slice (users_logins, users x2 in
// membership.go) renders as `IN (?,?,?)` under GORM's dry run, which is a
// real divergence from the golden's literal `IN ?` text that the harness has
// no approved-diff entry for yet - see the wave 1 membership conversion
// report for the exact site IDs and expected renders.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- getRoleForGroup / isModOfGroup: role lookup by userid+groupid+collection ---

func TestWave1Membership_b93b47daeb87(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "b93b47daeb87", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Scan(&dest)
	})
}

func TestWave1Membership_57cbae19bf26(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "57cbae19bf26", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Scan(&dest)
	})
}

// --- PostMemberships hold check: heldby lookup and holder's name -----------

func TestWave1Membership_e472a5457ade(t *testing.T) {
	var dest uint64
	ormharness.AssertGoldenSQL(t, "e472a5457ade", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("COALESCE(heldby, 0)").
			Where("userid = ? AND groupid = ?", 1, 2).
			Scan(&dest)
	})
}

func TestWave1Membership_8c02651afe8b(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "8c02651afe8b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").
			Where("id = ?", 1).
			Scan(&dest)
	})
}

// --- putMembershipsPartner: group exists, banned, existing role ------------

func TestWave1Membership_cf56bf438429(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "cf56bf438429", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Count(&dest)
	})
}

func TestWave1Membership_dd226f0cacca(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "dd226f0cacca", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Where("userid = ? AND groupid = ?", 1, 2).Count(&dest)
	})
}

func TestWave1Membership_553e621a63b0(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "553e621a63b0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ?", 1, 2).
			Scan(&dest)
	})
}

// --- addMemberToGroup: group exists, existing role, banned -----------------

func TestWave1Membership_927556278e70(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "927556278e70", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Count(&dest)
	})
}

func TestWave1Membership_5b811f0c2cc6(t *testing.T) {
	var dest string
	ormharness.AssertGoldenSQL(t, "5b811f0c2cc6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("role").
			Where("userid = ? AND groupid = ?", 1, 2).
			Scan(&dest)
	})
}

func TestWave1Membership_4651063e075b(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "4651063e075b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Where("userid = ? AND groupid = ?", 1, 2).Count(&dest)
	})
}

// --- PatchMemberships: membership exists, config exists, remaining mod count ---

func TestWave1Membership_d52f362bd794(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "d52f362bd794", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Count(&dest)
	})
}

func TestWave1Membership_a35651ab9f60(t *testing.T) {
	var dest uint64
	ormharness.AssertGoldenSQL(t, "a35651ab9f60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Select("id").
			Where("id = ?", 1).
			Scan(&dest)
	})
}

func TestWave1Membership_01aaf9fe55ea(t *testing.T) {
	// Two explicit `?` placeholders inside a literal `IN (?, ?)`, bound to two
	// scalar args - not the `IN ?`-with-a-slice shape that this batch is
	// skipping elsewhere, so the shape carries over to GORM unchanged.
	var dest int64
	ormharness.AssertGoldenSQL(t, "01aaf9fe55ea", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND role IN (?, ?) AND collection = ?", 1, "Moderator", "Owner", "Approved").
			Count(&dest)
	})
}
