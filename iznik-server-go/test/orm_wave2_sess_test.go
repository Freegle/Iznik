package test

// Wave 2 (single-table writes), session and membership modules (plan section
// 7.3+): moderator membership actions (Hold/Release/Approve/Reject/Ban/
// Unban/Review*), membership settings (PatchMemberships), account merge
// (session/merge.go), account deletion/forget (session/session.go), and
// social login (session/social_auth.go).
//
// Note the write conventions, which differ from the read waves:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for a literal or expression value (NOW(), NULL, a bare
//     0 or 1) rather than a plain Go value, which would bind as "?" and
//     diverge from a golden that writes the literal inline.
//   - clause.Update{Modifier: "IGNORE"} for the three "UPDATE IGNORE ..."
//     sites in session/merge.go: GORM's Update callback only adds a default
//     clause.Update{} via AddClauseIfNotExists, so pre-registering one with
//     Modifier set via .Clauses(...) before the terminal Update() survives.
//
// A large share of this batch's INSERTs and multi-column UPDATEs are left
// raw rather than converted - see the "ORM migration site ... Left raw"
// comments at each site in membership/membership.go, session/session.go and
// session/social_auth.go for the specific reason (map-Create's alphabetical
// column order not matching the golden, Updates(map)'s alphabetical SET
// order not matching the golden, or a LastInsertId() the read/write split
// requires). Nothing here is taken on trust: each converted render is
// compared against the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- membership/membership.go: PostMemberships -----------------------------

// 7301cba96a50 and 39aa0175d3ce are the same statement at two call sites
// (Hold and ReviewHold), converted together: gate (h) refuses a
// half-converted pair, because converting one renumbers the survivor's site
// ID.
func TestWave2Sess_7301cba96a50(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7301cba96a50", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("heldby", 3)
	})
}

func TestWave2Sess_39aa0175d3ce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "39aa0175d3ce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("heldby", 3)
	})
}

// 19356cdf20b2 and 8190a1ccc42c are the same statement at two call sites
// (Release and ReviewRelease), converted together for the same reason.
func TestWave2Sess_19356cdf20b2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "19356cdf20b2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("heldby", gorm.Expr("NULL"))
	})
}

func TestWave2Sess_8190a1ccc42c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8190a1ccc42c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("heldby", gorm.Expr("NULL"))
	})
}

// eeb78024d807 (Approve): map keys "collection" and "heldby" already sort
// alphabetically in that order, so Updates(map) happens to match the golden
// without needing an approved diff.
func TestWave2Sess_eeb78024d807(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "eeb78024d807", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Updates(map[string]interface{}{"collection": "Approved", "heldby": gorm.Expr("NULL")})
	})
}

// dd9f2b939bce (Reject / Delete Approved Member).
func TestWave2Sess_dd9f2b939bce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dd9f2b939bce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND collection IN (?, ?)", 1, 2, "Pending", "Approved").
			Delete(nil)
	})
}

// 98ee705a8a74 and d60d7b2e0f2a are the same statement at two call sites
// (PostMemberships's Ban action and DeleteMemberships's Ban path), converted
// together.
func TestWave2Sess_98ee705a8a74(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "98ee705a8a74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).Delete(nil)
	})
}

func TestWave2Sess_d60d7b2e0f2a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d60d7b2e0f2a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).Delete(nil)
	})
}

// 3747c49ac43e (Unban).
func TestWave2Sess_3747c49ac43e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3747c49ac43e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Where("userid = ? AND groupid = ?", 1, 2).Delete(nil)
	})
}

// f15eacecdc43 (HappinessReviewed): golden sets a literal 1, so the value
// goes through gorm.Expr rather than as a bind, which would have rendered
// "reviewed = ?".
func TestWave2Sess_f15eacecdc43(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f15eacecdc43", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("id = ?", 1).Update("reviewed", gorm.Expr("1"))
	})
}

// --- membership/membership.go: getRelatedMembers ---------------------------

// 0477a681ffd6: golden sets a literal 1, same reasoning as f15eacecdc43.
func TestWave2Sess_0477a681ffd6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0477a681ffd6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_related").Where("id = ?", 1).Update("notified", gorm.Expr("1"))
	})
}

// --- membership/membership.go: DeleteMemberships / deleteMembershipsPartner

// 535641088fb3 and 0fe2da6629e8 are the same statement at two call sites
// (DeleteMemberships and deleteMembershipsPartner), converted together. The
// caller checks result.RowsAffected, which .Table().Delete(nil) still
// populates, so that behaviour is unchanged.
func TestWave2Sess_535641088fb3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "535641088fb3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Delete(nil)
	})
}

func TestWave2Sess_0fe2da6629e8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0fe2da6629e8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Delete(nil)
	})
}

// --- membership/membership.go: PatchMemberships -----------------------------

func TestWave2Sess_c3cff12ce730(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c3cff12ce730", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("emailfrequency", 3)
	})
}

func TestWave2Sess_02963d1e570a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "02963d1e570a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("eventsallowed", 1)
	})
}

func TestWave2Sess_54535c67055f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "54535c67055f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("volunteeringallowed", 1)
	})
}

func TestWave2Sess_3d8f2d88b42d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3d8f2d88b42d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("settings", "{}")
	})
}

func TestWave2Sess_683e16a0d881(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "683e16a0d881", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("configid", 3)
	})
}

// 5a436bd174af: "ourPostingStatus" is a mixed-case column name. GORM backtick-
// quotes whatever string Update() is given verbatim; canonical.go strips
// backticks from identifiers without lowercasing them, so the case survives
// the comparison unchanged.
func TestWave2Sess_5a436bd174af(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5a436bd174af", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("ourPostingStatus", "X")
	})
}

func TestWave2Sess_948119564065(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "948119564065", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND collection = ?", 1, 2, "Approved").
			Update("role", "Moderator")
	})
}

func TestWave2Sess_58a93e92bdd0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "58a93e92bdd0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND systemrole = ?", 1, "User").
			Update("systemrole", "Moderator")
	})
}

func TestWave2Sess_e28d4a7241be(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e28d4a7241be", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND systemrole = ?", 1, "Moderator").
			Update("systemrole", "User")
	})
}

// --- session/merge.go: MergeUserAccounts ------------------------------------

func TestWave2Sess_d7333aa6fdae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d7333aa6fdae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("fromuser = ?", 1).Update("fromuser", 2)
	})
}

func TestWave2Sess_14cccaf3f10c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "14cccaf3f10c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2Sess_ea1047806312(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ea1047806312", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ?", 1).Update("userid", 2)
	})
}

// e3d91cc664c5: "UPDATE IGNORE memberships ...". clause.Update{Modifier:
// "IGNORE"} pre-registers the UPDATE clause with its modifier set before the
// terminal Update() runs; the callback's own AddClauseIfNotExists then
// leaves it alone.
func TestWave2Sess_e3d91cc664c5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e3d91cc664c5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Clauses(clause.Update{Modifier: "IGNORE"}).
			Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2Sess_191766b7cd39(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "191766b7cd39", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ?", 1).Delete(nil)
	})
}

func TestWave2Sess_5060d022dfe8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5060d022dfe8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

// --- session/merge.go: mergeChatRooms ---------------------------------------

func TestWave2Sess_787b2eadea22(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "787b2eadea22", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").
			Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
				1, 2, 2, 1, 2, 2).
			Delete(nil)
	})
}

func TestWave2Sess_90a890072eab(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "90a890072eab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("chatid = ?", 1).Update("chatid", 2)
	})
}

func TestWave2Sess_d820bc9bf511(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d820bc9bf511", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("refchatid = ?", 1).Update("refchatid", 2)
	})
}

func TestWave2Sess_2c505360400e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2c505360400e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Update{Modifier: "IGNORE"}).
			Where("chatid = ?", 1).Update("chatid", 2)
	})
}

func TestWave2Sess_e91e1b857064(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e91e1b857064", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2Sess_e5c1d288cbae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e5c1d288cbae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("user1 = ?", 1).Update("user1", 2)
	})
}

func TestWave2Sess_3b4c210e4a12(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3b4c210e4a12", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("user2 = ?", 1).Update("user2", 2)
	})
}

func TestWave2Sess_f370eb323bd4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f370eb323bd4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.Update{Modifier: "IGNORE"}).
			Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2Sess_8a7c730e0d5c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8a7c730e0d5c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Where("userid = ?", 1).Delete(nil)
	})
}

// --- session/session.go: handleForget ---------------------------------------

// aeda8c91f9ff and 54406e904bd5 are the same statement at two call sites
// (the partner flow and the self-service flow), converted together.
func TestWave2Sess_aeda8c91f9ff(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "aeda8c91f9ff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND collection = ?", 1, "Approved").Delete(nil)
	})
}

func TestWave2Sess_54406e904bd5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "54406e904bd5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND collection = ?", 1, "Approved").Delete(nil)
	})
}

// c38c5422a649 and da41536965a2 are the same statement at two call sites,
// converted together for the same reason.
func TestWave2Sess_c38c5422a649(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c38c5422a649", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

func TestWave2Sess_da41536965a2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "da41536965a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

func TestWave2Sess_5e425bd87624(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5e425bd87624", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Where("userid = ?", 1).Delete(nil)
	})
}

// --- session/session.go: PatchSession ---------------------------------------

// fc7cf4ff8b35: golden sets a literal 0, so the value goes through gorm.Expr
// rather than as a bind, which would have rendered "preferred = ?".
func TestWave2Sess_fc7cf4ff8b35(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fc7cf4ff8b35", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ?", 1).Update("preferred", gorm.Expr("0"))
	})
}

func TestWave2Sess_54fd76b4cc2c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "54fd76b4cc2c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("id = ?", 1).Update("bounced", gorm.Expr("NULL"))
	})
}

func TestWave2Sess_3fc481b1a1a6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3fc481b1a1a6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("bouncing", gorm.Expr("0"))
	})
}

// --- session/session.go: DeleteSession ---------------------------------------

func TestWave2Sess_c49c4a6dd162(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c49c4a6dd162", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Where("userid = ? AND series = ?", 1, 2).Delete(nil)
	})
}

func TestWave2Sess_5f7862e7e461(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5f7862e7e461", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").Where("id = ? AND userid = ?", 1, 2).Delete(nil)
	})
}

// --- session/social_auth.go: socialMatchOrCreate ----------------------------

// cdcc4a38c65d: golden sets NOW(), so the value goes through gorm.Expr
// rather than as a bind.
func TestWave2Sess_cdcc4a38c65d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cdcc4a38c65d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Where("userid = ? AND type = ?", 1, "Google").
			Update("lastaccess", gorm.Expr("NOW()"))
	})
}

// Proof that the harness's column-order normalisation works on a real site.
// The golden's SET order is reviewedat, reviewrequestedat, heldby; a map-valued
// Updates emits it alphabetically as heldby, reviewedat, reviewrequestedat.
// normaliseColumnOrder sorts each side while keeping every column attached to
// its own value, so the reordering is accepted and a mispaired conversion would
// still fail.
func TestWave2Sess_93875f6e74f0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "93875f6e74f0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ?", 1, 2).
			Updates(map[string]interface{}{
				"reviewedat":        gorm.Expr("NOW()"),
				"reviewrequestedat": gorm.Expr("NULL"),
				"heldby":            gorm.Expr("NULL"),
			})
	})
}
