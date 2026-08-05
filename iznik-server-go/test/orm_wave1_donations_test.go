package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3), donations
// module: iznik-server-go/donations/{donations,giftaid,paypalipn,stripe,
// stripeipn}.go.
//
// Each test names the site ID it proves, which is what plan 7.2's Gate 2
// checks mechanically - a converted site's raw SQL no longer exists in the
// source, so the extractor can only tell "converted" apart from "silently
// deleted" by finding a parity test that names the ID.
//
// A first pass over this module left thirteen `LIMIT 1` sites raw, because
// GORM's clause.Limit always binds the limit (clause/limit.go: Build calls
// builder.AddVar), so `.Limit(1)` used to render `LIMIT ?` and diverge from a
// golden's literal `LIMIT 1`. golden.go's AssertGoldenSQL now resolves bound
// LIMIT/OFFSET values back into the rendered SQL before comparing
// (resolveLimitOffset), so `.Limit(1)` and a golden `LIMIT 1` match directly
// and all thirteen convert below like any other site.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- GetDonations: group funding target -------------------------------------

func TestWave1Donations_e05d3c7dc0c1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e05d3c7dc0c1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("fundingtarget").Where("id = ?", 1).Find(&dest)
	})
}

// --- AddDonation: permission check, donor lookup, transaction id lookup ----

func TestWave1Donations_86e67ec8afc4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "86e67ec8afc4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("permissions").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Donations_8f09f277831b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8f09f277831b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Select("id, COALESCE(NULLIF(fullname, ''), NULLIF(TRIM(CONCAT(COALESCE(firstname, ''), ' ', COALESCE(lastname, ''))), ''), '') AS name").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Donations_e2d485938df9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e2d485938df9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").Select("id").Where("TransactionID = ?", "x").Find(&dest)
	})
}

// --- isGiftAidAdmin / SearchGiftAid / DeleteGiftAid -------------------------

func TestWave1Donations_753a53ffa510(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "753a53ffa510", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("permissions").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Donations_d42b2cee70cd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d42b2cee70cd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").
			Where("fullname LIKE ? OR homeaddress LIKE ? OR id LIKE ?", "%x%", "%x%", "%x%").
			Find(&dest)
	})
}

func TestWave1Donations_bb29b5996e58(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "bb29b5996e58", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", 1).Find(&dest)
	})
}

// --- PayPalIPN: user id lookup from Stripe-metadata match -------------------

func TestWave1Donations_b55d22304524(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b55d22304524", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

// --- CreateSubscription: donor fullname lookup ------------------------------

func TestWave1Donations_0d395b814481(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0d395b814481", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

// --- matchDonorUser: metadata/customer-metadata existence checks, name -----

func TestWave1Donations_015d0fcc34c4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "015d0fcc34c4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Donations_83599f80cec3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "83599f80cec3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1Donations_9b52d8bd115c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9b52d8bd115c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").Where("id = ?", 1).Find(&dest)
	})
}

// --- MatchUserByEmailOrPriorDonation: registered-address lookup ------------

func TestWave1Donations_5ae46192a944(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5ae46192a944", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").
			Where("(email = ? OR canon = ?) AND userid IS NOT NULL", "a@b.c", "a@b.c").
			Limit(1).
			Find(&dest)
	})
}

// --- AddDonation: preferred-email lookup, first pass and fallback ----------

func TestWave1Donations_9330b7d3045a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9330b7d3045a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").
			Select("email").
			Where("userid = ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE ? AND email NOT LIKE '%@yahoogroups.%'",
				1, "%@users.ilovefreegle.org", "%@groups.ilovefreegle.org", "%@direct.ilovefreegle.org", "%@republisher.freegle.in").
			Order("preferred DESC, added DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1Donations_3b4f1c2cf9eb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3b4f1c2cf9eb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").
			Select("email").
			Where("userid = ?", 1).
			Order("preferred DESC, added DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- AddDonation: existing gift-aid period, before creating a prompt -------

func TestWave1Donations_21e8dbdd136c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "21e8dbdd136c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Select("period").Where("userid = ? AND deleted IS NULL", 1).Limit(1).Find(&dest)
	})
}

// --- BulkUploadDonations: donor email match ---------------------------------

func TestWave1Donations_16c4a7f6e566(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "16c4a7f6e566", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", "a@b.c").Limit(1).Find(&dest)
	})
}

// --- GetGiftAid: logged-in user's declaration -------------------------------

func TestWave1Donations_275465713fef(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "275465713fef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").
			Select("id, userid, timestamp, period, fullname, firstname, lastname, homeaddress, deleted, reviewed, updated, postcode, housenameornumber").
			Where("userid = ? AND deleted IS NULL", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- ListGiftAid / SearchGiftAid: per-row email lookup ----------------------

func TestWave1Donations_06f02d4d35de(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "06f02d4d35de", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

func TestWave1Donations_ba853e58442d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ba853e58442d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

// --- CreateSubscription: donor email lookup ---------------------------------

func TestWave1Donations_4e1a7726a577(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4e1a7726a577", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

// --- matchDonorUser: customer-email and billing-email lookups, name/email --

func TestWave1Donations_3c00a7ee8fd9(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3c00a7ee8fd9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", "a@b.c").Limit(1).Find(&dest)
	})
}

func TestWave1Donations_7104e922999e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7104e922999e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("userid").Where("email = ? AND userid IS NOT NULL", "a@b.c").Limit(1).Find(&dest)
	})
}

func TestWave1Donations_c1cf9529710a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c1cf9529710a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", 1).Order("preferred DESC").Limit(1).Find(&dest)
	})
}

// --- handleGiftAidNotification: most recent gift-aid period ----------------

func TestWave1Donations_433192020fab(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "433192020fab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Select("period").Where("userid = ?", 1).Order("id DESC").Limit(1).Find(&dest)
	})
}
