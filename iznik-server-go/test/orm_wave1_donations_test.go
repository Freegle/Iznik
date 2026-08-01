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
// Thirteen sites in this module were left raw and are not tested here: every
// one binds a literal `LIMIT 1` in its golden SQL. GORM's clause.Limit always
// renders the limit as a bound `LIMIT ?` (clause/limit.go: Build calls
// builder.AddVar), so `.Limit(1)` is a real text divergence from the golden,
// not a cosmetic one - see canonical.go's identifier/keyword-case/whitespace
// normalisation, none of which covers this. That divergence can legitimately
// be accepted via an approvedDiff entry (as wave 0 did for site 242735a48039,
// social_auth.go), but that requires editing manifest.json, which is outside
// this batch's scope. Left for a follow-up: donations.go:97, donations.go:261,
// donations.go:279, donations.go:317, donations.go:411, giftaid.go:68,
// giftaid.go:177, giftaid.go:215, stripe.go:149, stripeipn.go:178,
// stripeipn.go:190, stripeipn.go:213, stripeipn.go:237.

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
