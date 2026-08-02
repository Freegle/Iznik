package test

// Wave 2 (single-table writes), auth/admin modules (plan section 7.3+):
// admin messages (admin/admin.go), session creation (auth/auth.go), donations
// and Gift Aid (donations/donations.go, donations/giftaid.go,
// donations/paypalipn.go), group memberships (membership/membership.go),
// moderation config (modconfig/modconfig.go), sessions and account
// deletion/forget (session/session.go), social login (session/social_auth.go),
// and user creation/edit (user/auth.go, user/user.go).
//
// Write conventions, same as the rest of wave 2:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for a literal or expression value (NOW(), NULL, a bare
//     0 or 1, or a quoted string literal such as 'Link') rather than a plain
//     Go value, which would bind as "?" and diverge from a golden that writes
//     the literal inline.
//   - Table(...).Create(map[string]interface{}{...}) for INSERTs; a value
//     that must render as a SQL literal goes through gorm.Expr the same way
//     an UPDATE value would.
//
// Eight sites are left raw:
//   - Five are an INSERT whose caller reads the new row's id via
//     sql.Result.LastInsertId() on the same connection that ran it: admin.go's
//     PostAdmin create case (88399aa73e56), auth/auth.go's CreateSessionAndJWT
//     (45c97c1f1f58), modconfig.go's copy-from-existing and simple-create
//     paths (6b9a23982cc9, 181d8342ea4a), session/social_auth.go's
//     socialMatchOrCreate (bbbc465b075c), and user/user.go's PutUser
//     (24704432f4d4, c9c7922e5379 - two sites, new user and new session).
//     GORM's map-Create id writeback for a schema-less Table()+map call is
//     undocumented behaviour, not something to rely on for a fresh row id.
//   - One (modconfig.go's bulkops copy, e137c396bd13) is INSERT ... SELECT,
//     which has no GORM builder equivalent that keeps it a single atomic
//     statement without changing to a separate SELECT-then-INSERT (a
//     different, less safe shape, and one that would no longer match the
//     golden SQL text at all). No site with this shape is converted anywhere
//     in the codebase.
//
// A number of sites this batch converts were previously left raw with a
// "map-Create/Updates(map) would reorder the column/SET list" comment. That
// concern is obsolete: ormharness/normalise_test.go pins
// TestNormaliseColumnOrder_Insert, TestNormaliseColumnOrder_InsertWithNestedFunctionArgs
// and TestNormaliseColumnOrder_Update, which prove normaliseColumnOrder sorts
// both the golden and the rendered SQL's column/SET list (moving each column
// WITH its value) before comparing, so a harmless reorder from GORM's
// alphabetical map iteration does not fail the golden comparison. Where a
// SET list has a value that references another assigned column (load-bearing
// order), that is checked separately by check-set-order.sh /
// setOrderIsLoadBearing - none of the sites here have that shape (verified
// per-site in the production code comments).
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- admin/admin.go: PostAdmin (Hold / Release cases) -----------------------

func TestWave2Auth_758cc8542da6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "758cc8542da6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("heldby", 2)
	})
}

func TestWave2Auth_392f5063e394(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "392f5063e394", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("heldby", gorm.Expr("NULL"))
	})
}

// --- admin/admin.go: PatchAdmin ----------------------------------------------

func TestWave2Auth_410038882811(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "410038882811", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("subject", "X")
	})
}

func TestWave2Auth_4848bb7140d6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4848bb7140d6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).
			Updates(map[string]interface{}{"complete": gorm.Expr("NOW()"), "heldby": gorm.Expr("NULL")})
	})
}

func TestWave2Auth_a6f6b67bbf10(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a6f6b67bbf10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("pending", 1)
	})
}

func TestWave2Auth_ff2cd33b1601(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ff2cd33b1601", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("ctatext", "X")
	})
}

func TestWave2Auth_aeab343d2b29(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "aeab343d2b29", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("ctalink", "X")
	})
}

func TestWave2Auth_dfb2e5f3ddfb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dfb2e5f3ddfb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("essential", true)
	})
}

func TestWave2Auth_4129ec17482b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4129ec17482b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("template", "X")
	})
}

func TestWave2Auth_339f96b301b3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "339f96b301b3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).Update("editprotected", true)
	})
}

func TestWave2Auth_e833309ff21a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e833309ff21a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("admins").Where("id = ?", 1).
			Updates(map[string]interface{}{"editedat": gorm.Expr("NOW()"), "editedby": 2})
	})
}

// --- donations/donations.go: AddDonation ------------------------------------

func TestWave2Auth_dcb461443c86(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dcb461443c86", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Create(map[string]interface{}{
			"touser":    1,
			"type":      gorm.Expr("'GiftAid'"),
			"timestamp": gorm.Expr("NOW()"),
			"seen":      gorm.Expr("0"),
		})
	})
}

// --- donations/giftaid.go: EditGiftAid --------------------------------------

func TestWave2Auth_4f053cd8dd78(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4f053cd8dd78", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("period", "X")
	})
}

func TestWave2Auth_645c6f681043(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "645c6f681043", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("fullname", "X")
	})
}

func TestWave2Auth_ef4a0c1acdcb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ef4a0c1acdcb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("firstname", "X")
	})
}

func TestWave2Auth_09c76dc0c164(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "09c76dc0c164", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("lastname", "X")
	})
}

func TestWave2Auth_d21ef86c2517(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d21ef86c2517", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("homeaddress", "X")
	})
}

func TestWave2Auth_6eb0a1069fb6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6eb0a1069fb6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("postcode", "X")
	})
}

func TestWave2Auth_246bbfcc9225(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "246bbfcc9225", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("housenameornumber", "X")
	})
}

func TestWave2Auth_2ecbd6874f42(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2ecbd6874f42", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("reviewed", gorm.Expr("NOW()"))
	})
}

func TestWave2Auth_40da338804c9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "40da338804c9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

// --- donations/paypalipn.go: PayPalIPN ---------------------------------------

func TestWave2Auth_6fdb2c3b96f5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6fdb2c3b96f5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").Create(map[string]interface{}{
			"userid":           1,
			"Payer":            "payer@example.com",
			"PayerDisplayName": "X",
			"timestamp":        "2026-01-01 00:00:00",
			"TransactionID":    "TXN1",
			"GrossAmount":      "10.00",
			"source":           "DonateWithPayPal",
			"TransactionType":  "web_accept",
			"type":             "PayPal",
		})
	})
}

// --- membership/membership.go: PostMemberships (Leave / Approve / Reject) ---

// 3b43ce5f3f6c, a7dec15999b7 and 8d3cfe20b403 all render the same
// INSERT shape (only the bound values differ), one per action case.
func TestWave2Auth_3b43ce5f3f6c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3b43ce5f3f6c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_mod_stdmsg",
			"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "X", "Y", 4, "Leave Approved Member"),
		})
	})
}

func TestWave2Auth_a7dec15999b7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a7dec15999b7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_mod_stdmsg",
			"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "X", "Y", 0, "Approve Member"),
		})
	})
}

func TestWave2Auth_8d3cfe20b403(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d3cfe20b403", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_mod_stdmsg",
			"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "X", "Y", 4, "Reject"),
		})
	})
}

// --- membership/membership.go: putMembershipsPartner / addMemberToGroup ----

// 759766c83c01 and 27aa0e237120 are the same statement at two call sites
// (putMembershipsPartner and addMemberToGroup), converted together: gate (h)
// refuses a half-converted pair, because converting one renumbers the
// survivor's site ID. The same applies to 32d907621f09 / 2f0c55ec88d6.
func TestWave2Auth_759766c83c01(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "759766c83c01", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Create(map[string]interface{}{
			"userid":     1,
			"groupid":    2,
			"role":       "Member",
			"collection": "Approved",
		})
	})
}

func TestWave2Auth_27aa0e237120(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "27aa0e237120", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Create(map[string]interface{}{
			"userid":     1,
			"groupid":    2,
			"role":       "Member",
			"collection": "Approved",
		})
	})
}

func TestWave2Auth_32d907621f09(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "32d907621f09", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships_history").Create(map[string]interface{}{
			"userid":             1,
			"groupid":            2,
			"collection":         "Approved",
			"processingrequired": gorm.Expr("1"),
		})
	})
}

func TestWave2Auth_2f0c55ec88d6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f0c55ec88d6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships_history").Create(map[string]interface{}{
			"userid":             1,
			"groupid":            2,
			"collection":         "Approved",
			"processingrequired": gorm.Expr("1"),
		})
	})
}

// --- modconfig/modconfig.go: PostModConfig (copy-from-existing path) -------

func TestWave2Auth_207260729430(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "207260729430", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Where("id = ?", 1).Update("createdby", 2)
	})
}

func TestWave2Auth_ef309513694a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ef309513694a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_stdmsgs").Create(map[string]interface{}{
			"configid":     1,
			"title":        "X",
			"action":       "X",
			"subjpref":     "X",
			"subjsuff":     "X",
			"body":         "X",
			"rarelyused":   1,
			"autosend":     1,
			"newmodstatus": "X",
			"newdelstatus": "X",
			"edittext":     "X",
			"insert":       "X",
		})
	})
}

// e07a25573e68, b4d152ba261c, d42d9aa90149 and e31c7ddcc714 all render the
// same "log the creation/edit/deletion" INSERT shape at four call sites
// (create-copy, simple-create, PatchModConfig, DeleteModConfig).
func TestWave2Auth_e07a25573e68(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e07a25573e68", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"configid":  4,
		})
	})
}

func TestWave2Auth_b4d152ba261c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b4d152ba261c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"configid":  4,
		})
	})
}

func TestWave2Auth_d42d9aa90149(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d42d9aa90149", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"configid":  4,
		})
	})
}

func TestWave2Auth_954d3085c050(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "954d3085c050", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("mod_configs").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2Auth_e31c7ddcc714(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e31c7ddcc714", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"byuser":    3,
			"configid":  4,
		})
	})
}

// --- session/session.go: getOrCreateLoginKey --------------------------------

func TestWave2Auth_812775236c88(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "812775236c88", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Create(map[string]interface{}{
			"userid":      1,
			"type":        "Link",
			"uid":         "1",
			"credentials": "X",
		})
	})
}

// --- session/session.go: handleForget (partner-delete flow) ----------------

func TestWave2Auth_02506a663a0e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "02506a663a0e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"user":      3,
			"byuser":    gorm.Expr("NULL"),
		})
	})
}

func TestWave2Auth_735b4f446b8e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "735b4f446b8e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("fromuser = ?", 1).Updates(map[string]interface{}{
			"fromip":       gorm.Expr("NULL"),
			"message":      gorm.Expr("NULL"),
			"envelopefrom": gorm.Expr("NULL"),
			"fromname":     gorm.Expr("NULL"),
			"fromaddr":     gorm.Expr("NULL"),
			"messageid":    gorm.Expr("NULL"),
			"textbody":     gorm.Expr("NULL"),
			"htmlbody":     gorm.Expr("NULL"),
			"deleted":      gorm.Expr("NOW()"),
		})
	})
}

// --- session/session.go: handleForget (self-service flow) ------------------

func TestWave2Auth_9f1d1bde8950(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9f1d1bde8950", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"user":      3,
			"byuser":    3,
		})
	})
}

// --- session/session.go: PatchSession ---------------------------------------

func TestWave2Auth_db15655f044f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "db15655f044f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("id = ?", 1).Updates(map[string]interface{}{
			"userid":      2,
			"preferred":   gorm.Expr("1"),
			"validated":   gorm.Expr("NOW()"),
			"validatekey": gorm.Expr("NULL"),
		})
	})
}

func TestWave2Auth_3e8f726f0ef8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3e8f726f0ef8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_aboutme").Create(map[string]interface{}{
			"userid":    1,
			"text":      "X",
			"timestamp": gorm.Expr("NOW()"),
		})
	})
}

// --- session/social_auth.go: saveProfileImage -------------------------------

func TestWave2Auth_9ccb23bbcdaf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9ccb23bbcdaf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Create(map[string]interface{}{
			"userid":      1,
			"url":         "https://example.com/pic.jpg",
			"default":     gorm.Expr("0"),
			"contenttype": gorm.Expr("'image/jpeg'"),
		})
	})
}

// --- session/social_auth.go: socialMatchOrCreate ----------------------------

func TestWave2Auth_f6bd87f2df8e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f6bd87f2df8e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Create(map[string]interface{}{
			"userid":    1,
			"email":     "member@example.com",
			"preferred": gorm.Expr("0"),
			"validated": gorm.Expr("NOW()"),
			"canon":     "memberexamplecom",
			"backwards": "moc.elpmaxe@rebmem",
		})
	})
}

func TestWave2Auth_994ffacdcb47(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "994ffacdcb47", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND (fullname IS NULL OR fullname = '')", 1).
			Updates(map[string]interface{}{
				"firstname": "X",
				"lastname":  "Y",
				"fullname":  "X Y",
			})
	})
}

// --- user/auth.go: GetLoveJunkUser -------------------------------------------

func TestWave2Auth_5bd4b2e2c8ff(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5bd4b2e2c8ff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").Create(map[string]interface{}{
			"userid":      1,
			"url":         "https://example.com/pic.jpg",
			"contenttype": gorm.Expr("'image/jpeg'"),
			"default":     gorm.Expr("0"),
		})
	})
}

// --- user/user.go: enrichUserForModtools ------------------------------------

func TestWave2Auth_f619bcea08df(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f619bcea08df", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Create(map[string]interface{}{
			"userid":      1,
			"type":        gorm.Expr("'Link'"),
			"credentials": "X",
		})
	})
}

// --- user/user.go: handleAddEmail -------------------------------------------

func TestWave2Auth_07c4bef29c2f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "07c4bef29c2f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("id = ?", 1).Updates(map[string]interface{}{
			"userid":    2,
			"preferred": 1,
			"validated": gorm.Expr("NOW()"),
			"canon":     "memberexamplecom",
			"backwards": "moc.elpmaxe@rebmem",
		})
	})
}

// --- user/user.go: PutUser ---------------------------------------------------

func TestWave2Auth_c5b11d58ae60(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c5b11d58ae60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Create(map[string]interface{}{
			"userid":    1,
			"email":     "member@example.com",
			"preferred": gorm.Expr("1"),
			"validated": gorm.Expr("NOW()"),
			"canon":     "memberexamplecom",
			"backwards": "moc.elpmaxe@rebmem",
		})
	})
}

func TestWave2Auth_9c4b09b388ef(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9c4b09b388ef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Create(map[string]interface{}{
			"userid":      1,
			"type":        "Native",
			"uid":         1,
			"credentials": "X",
			"salt":        "Y",
		})
	})
}

func TestWave2Auth_fec927e74aaa(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fec927e74aaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Create(map[string]interface{}{
			"userid":     1,
			"groupid":    2,
			"role":       "Member",
			"collection": "Approved",
		})
	})
}

func TestWave2Auth_2f4ae0de2f36(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f4ae0de2f36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"groupid":   3,
			"user":      4,
			"byuser":    4,
		})
	})
}

func TestWave2Auth_e7a34e3dea3b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e7a34e3dea3b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships_history").Create(map[string]interface{}{
			"userid":             1,
			"groupid":            2,
			"collection":         "Approved",
			"processingrequired": gorm.Expr("1"),
			"added":              gorm.Expr("NOW()"),
		})
	})
}

// --- user/user.go: CheckLocationChangeVelocity ------------------------------

func TestWave2Auth_80bda541244b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "80bda541244b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? "+
			"AND (reviewrequestedat IS NULL OR (reviewedat IS NOT NULL AND reviewedat >= reviewrequestedat))", 1).
			Updates(map[string]interface{}{
				"reviewrequestedat": gorm.Expr("NOW()"),
				"reviewreason":      "X",
			})
	})
}

// --- user/user.go: PatchUser -------------------------------------------------

func TestWave2Auth_29d38c03b5c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29d38c03b5c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Updates(map[string]interface{}{
			"fullname":  "X",
			"firstname": gorm.Expr("NULL"),
			"lastname":  gorm.Expr("NULL"),
		})
	})
}

func TestWave2Auth_896940a53068(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "896940a53068", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_aboutme").Create(map[string]interface{}{
			"userid":    1,
			"text":      "X",
			"timestamp": gorm.Expr("NOW()"),
		})
	})
}

// --- user/user.go: LogGroupLeftForApprovedMemberships -----------------------

func TestWave2Auth_564e5329c133(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "564e5329c133", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"user":      3,
			"byuser":    gorm.Expr("NULL"),
			"groupid":   4,
		})
	})
}

func TestWave2Auth_cfcd2f885279(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cfcd2f885279", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"user":      3,
			"byuser":    4,
			"groupid":   5,
		})
	})
}

// --- user/user.go: softLimboUser ---------------------------------------------

func TestWave2Auth_8bb010b9529b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8bb010b9529b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      1,
			"subtype":   2,
			"user":      3,
			"byuser":    4,
		})
	})
}

// --- user/user.go: handleMerge -----------------------------------------------

// 8278f05f07af and 6f9705358ae3 are the same statement written twice (one log
// entry per merged user), converted together.
func TestWave2Auth_8278f05f07af(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8278f05f07af", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"user":      1,
			"byuser":    2,
			"type":      3,
			"subtype":   4,
			"text":      "X",
			"timestamp": gorm.Expr("NOW()"),
		})
	})
}

func TestWave2Auth_6f9705358ae3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6f9705358ae3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"user":      1,
			"byuser":    2,
			"type":      3,
			"subtype":   4,
			"text":      "X",
			"timestamp": gorm.Expr("NOW()"),
		})
	})
}
