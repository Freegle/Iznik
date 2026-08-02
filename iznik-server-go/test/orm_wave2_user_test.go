package test

// Wave 2 (single-table writes), user module: accounts, emails, permissions,
// sessions, memberships and the account-merge helper.
//
// Conventions (same as the Wave 2 pilot and the message module's Wave 2
// batch — see orm_wave2_pilot_test.go and orm_wave2_message_test.go):
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed
//     and no soft-delete field can silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for any literal or expression SET value (NOW(), NULL,
//     a bare 0 or 1, GREATEST(...), COALESCE(...)) — a plain Go value there
//     would bind as "?" and diverge from the golden's literal/expression text.
//   - .Updates(map[string]interface{}{...}) only where the map's alphabetical
//     column order happens to match the golden's SET order (checked per
//     site below); GORM offers no way to control map-Create/Updates column
//     order otherwise.
//   - "UPDATE IGNORE ..." sites use .Clauses(clause.Update{Modifier: "IGNORE"})
//     ahead of .Table(...): GORM's update callback only adds its own (empty)
//     clause.Update if one isn't already on the statement
//     (callbacks/update.go: db.Statement.AddClauseIfNotExists(clause.Update{})),
//     so a Modifier set via .Clauses(...) survives untouched — verified
//     against clause/update_test.go's own "LOW_PRIORITY" case, which proves
//     the identical mechanism for a different modifier string.
//
// user.go's handleMerge runs its writes on `tx` (a *gorm.DB from db.Begin()),
// not the plain connection — same situation as pilot site 4718b42d0c88
// (also in handleMerge, referenced again below where it sits between two
// sites converted in this batch). The dry-run build function renders
// identically regardless of which *gorm.DB it's handed, so proving these
// against the harness's plain "tx" here is safe.
//
// Sites left on raw SQL, not converted in this batch (reported to the
// migration owner rather than forced):
//
//   - INSERT sites whose caller needs LastInsertId() on the same connection
//     under the read/write split: user.go 24704432f4d4 (INSERT INTO users,
//     via sqlDB.Exec) and c9c7922e5379 (INSERT INTO sessions, via
//     sqlDB.Exec). Same category already recorded for the three Wave 2
//     pilot INSERTs (orm_wave2_pilot_test.go) and tryst.CreateTryst in
//     keep-raw.json.
//   - INSERT sites where a map-valued Create's alphabetical column order
//     does not match the golden's handwritten order: auth.go 5bd4b2e2c8ff
//     (users_images — also has two literal, non-bound columns baked into
//     the SQL text); user.go f619bcea08df (users_logins, literal 'Link'),
//     c5b11d58ae60 (users_emails), 9c4b09b388ef (users_logins), fec927e74aaa
//     (memberships), 2f4ae0de2f36 (logs), e7a34e3dea3b
//     (memberships_history), 896940a53068 (users_aboutme), 564e5329c133 and
//     cfcd2f885279 and 8bb010b9529b (logs, three more call sites), and the
//     duplicate pair 8278f05f07af/6f9705358ae3 (logs, handleMerge — a
//     half-converted duplicate pair would also fail gate (h), so both stay
//     raw together).
//   - UPDATE sites with more than one SET column where the map's
//     alphabetical order doesn't match the golden: user.go 07c4bef29c2f
//     (users_emails: golden is userid, preferred, validated, canon,
//     backwards — alphabetical is backwards, canon, preferred, userid,
//     validated), 29d38c03b5c7 (users: golden is fullname, firstname,
//     lastname — alphabetical is firstname, fullname, lastname), and
//     80bda541244b (memberships: golden is reviewrequestedat, reviewreason —
//     alphabetical is reviewreason, reviewrequestedat).
//
// All of the above keep their original db.Exec/sqlDB.Exec production code
// untouched.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- auth.go ---------------------------------------------------------------

func TestWave2User_90c56d7f4ab1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "90c56d7f4ab1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("lastlocation", 2)
	})
}

// --- authMiddleware.go ------------------------------------------------------

func TestWave2User_4319778ec12f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4319778ec12f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Where("id = ? AND (lastaccess IS NULL OR lastaccess < DATE_SUB(NOW(), INTERVAL 10 MINUTE))", 1).
			Update("lastaccess", gorm.Expr("NOW()"))
	})
}

func TestWave2User_397a8f863bd8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "397a8f863bd8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("sessions").
			Where("id = ? AND lastactive < DATE_SUB(NOW(), INTERVAL 10 MINUTE)", 1).
			Update("lastactive", gorm.Expr("NOW()"))
	})
}

// --- relevantoff.go ----------------------------------------------------------

func TestWave2User_1cd19cb774b2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1cd19cb774b2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("relevantallowed", gorm.Expr("0"))
	})
}

// --- systemrole.go: SyncSystemRole ------------------------------------------

// df3c6bdb7aba and e4f7cca3adb8 are byte-identical SQL text (occurrence 0
// and 1 of the same statement in the file — the User<->Moderator promote and
// demote branches) so they're converted together per gate (h).
func TestWave2User_df3c6bdb7aba(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "df3c6bdb7aba", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND systemrole = ?", 1, "User").
			Update("systemrole", "Moderator")
	})
}

func TestWave2User_e4f7cca3adb8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e4f7cca3adb8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ? AND systemrole = ?", 1, "Moderator").
			Update("systemrole", "User")
	})
}

// --- user.go: InventName -----------------------------------------------------

func TestWave2User_2f3126db180a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f3126db180a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").
			Where("id = ? AND (fullname IS NULL OR fullname = '' OR fullname = 'A freegler' OR fullname LIKE 'FBUser%' OR (CHAR_LENGTH(fullname) = 32 AND fullname REGEXP '[A-Za-z].*[0-9]|[0-9].*[A-Za-z]'))", 1).
			Updates(map[string]interface{}{"fullname": "Jo Bloggs", "inventedname": gorm.Expr("1")})
	})
}

// --- user.go: DeleteUserSearch ----------------------------------------------

func TestWave2User_aaa43b677e1f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "aaa43b677e1f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_searches").Where("userid = ? AND term = ?", 1, "sofa").
			Update("deleted", gorm.Expr("1"))
	})
}

// --- user.go: handleEngaged ---------------------------------------------------

func TestWave2User_10216b47378d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "10216b47378d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("engage").Where("id = ?", 1).Update("succeeded", gorm.Expr("NOW()"))
	})
}

func TestWave2User_f78aeb6435bd(t *testing.T) {
	// Both SET columns are expressions (no bound value), and "action" sorts
	// before "rate" — the map's alphabetical order happens to match the
	// golden's column order here.
	ormharness.AssertGoldenSQL(t, "f78aeb6435bd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("engage_mails").Where("id = ?", 1).
			Updates(map[string]interface{}{
				"action": gorm.Expr("action + 1"),
				"rate":   gorm.Expr("COALESCE(100 * action / shown, 0)"),
			})
	})
}

// --- user.go: handleRate / handleRatingReviewed -------------------------------

func TestWave2User_b4968f94d154(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b4968f94d154", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id IN (?, ?)", 1, 2).Update("lastupdated", gorm.Expr("NOW()"))
	})
}

func TestWave2User_dbaf7d925bf5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dbaf7d925bf5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings").Where("id = ?", 1).Update("reviewrequired", gorm.Expr("0"))
	})
}

// --- user.go: handleAddEmail --------------------------------------------------

func TestWave2User_c25d3f0cbd14(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c25d3f0cbd14", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("id = ?", 1).Update("preferred", 1)
	})
}

// 1eb5dd8ca162 and a80f1b38c186 are byte-identical SQL text at two call
// sites in handleAddEmail (the "already on this user" branch and the
// "reassign existing row" branch) so they're converted together per gate (h).
func TestWave2User_1eb5dd8ca162(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1eb5dd8ca162", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ? AND id != ?", 1, 2).
			Update("preferred", gorm.Expr("0"))
	})
}

func TestWave2User_a80f1b38c186(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a80f1b38c186", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ? AND id != ?", 1, 2).
			Update("preferred", gorm.Expr("0"))
	})
}

func TestWave2User_265058d37c74(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "265058d37c74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ? AND email != ?", 1, "a@b.c").
			Update("preferred", gorm.Expr("0"))
	})
}

// --- user.go: handleRemoveEmail ------------------------------------------------

func TestWave2User_e7c6bfbc5607(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e7c6bfbc5607", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("email = ? AND userid = ?", "a@b.c", 1).Delete(nil)
	})
}

// --- user.go: ProcessSettingsUpdate ---------------------------------------------

func TestWave2User_fbea0c27cf49(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fbea0c27cf49", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones_users").Where("userid = ?", 1).Delete(nil)
	})
}

// --- user.go: PatchUser -----------------------------------------------------

func TestWave2User_846ce190fcf8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "846ce190fcf8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("newsfeedmodstatus", "Suppressed")
	})
}

func TestWave2User_2d1aadd1887d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2d1aadd1887d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("onholidaytill", gorm.Expr("NULL"))
	})
}

func TestWave2User_863ce73a0abc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "863ce73a0abc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("onholidaytill", "2026-01-01")
	})
}

func TestWave2User_a0e311646f19(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a0e311646f19", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("relevantallowed", 1)
	})
}

func TestWave2User_3df9a32dc731(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3df9a32dc731", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("newslettersallowed", 1)
	})
}

// 70eeb41b22a3 is byte-identical SQL text to 846ce190fcf8 above (the
// mod-acting-on-another-user branch vs the self-update branch of the same
// newsfeedmodstatus write) so both were converted in this batch per gate (h).
func TestWave2User_70eeb41b22a3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "70eeb41b22a3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("newsfeedmodstatus", "Suppressed")
	})
}

func TestWave2User_7b1efa32121d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7b1efa32121d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("source", "Android")
	})
}

// 1dc8cf0d9098/4cc44cc47cbe (trustlevel = NULL) and a716e0a18fb3/8798aebadf32
// (trustlevel = ?) are two byte-identical pairs — the moderator branch and
// the non-moderator self-service branch of the same two writes — converted
// together per gate (h).
func TestWave2User_1dc8cf0d9098(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1dc8cf0d9098", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("trustlevel", gorm.Expr("NULL"))
	})
}

func TestWave2User_a716e0a18fb3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a716e0a18fb3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("trustlevel", "Basic")
	})
}

func TestWave2User_4cc44cc47cbe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4cc44cc47cbe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("trustlevel", gorm.Expr("NULL"))
	})
}

func TestWave2User_8798aebadf32(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8798aebadf32", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("trustlevel", "Basic")
	})
}

// --- user.go: handleUnbounce -------------------------------------------------

func TestWave2User_9c39bf7d978a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9c39bf7d978a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("bouncing", gorm.Expr("0"))
	})
}

func TestWave2User_c62fbb94a8ff(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c62fbb94a8ff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ?", 1).Update("bounced", gorm.Expr("NULL"))
	})
}

// --- user.go: softLimboUser --------------------------------------------------

func TestWave2User_79f72a928ca3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "79f72a928ca3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND collection = ?", 1, "Approved").Delete(nil)
	})
}

func TestWave2User_b561dab1c2bd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b561dab1c2bd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

// --- user.go: handleMerge ------------------------------------------------------
// Runs on `tx` (db.Begin()) in production; see the file doc comment above.

func TestWave2User_d02deddf93b1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d02deddf93b1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ? AND preferred = 1", 1).
			Update("preferred", gorm.Expr("0"))
	})
}

func TestWave2User_c0bdaf91d946(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c0bdaf91d946", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_d0f1c6abfca1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d0f1c6abfca1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("id = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_1ca61377ab9d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1ca61377ab9d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).Update("role", "Owner")
	})
}

func TestWave2User_69e208d229bf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "69e208d229bf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("configid", gorm.Expr("COALESCE(configid, ?)", 3))
	})
}

func TestWave2User_93e40a364c53(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "93e40a364c53", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("settings", gorm.Expr("COALESCE(settings, ?)", "{}"))
	})
}

func TestWave2User_7d3ed9c6a8df(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7d3ed9c6a8df", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 1, 2).
			Update("heldby", gorm.Expr("COALESCE(heldby, ?)", 3))
	})
}

func TestWave2User_0b0cfc4af179(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0b0cfc4af179", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2User_4c001e1eeba2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4c001e1eeba2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ?", 1).Delete(nil)
	})
}

func TestWave2User_cd3e53cfadea(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cd3e53cfadea", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("fromuser = ?", 1).Update("fromuser", 2)
	})
}

func TestWave2User_d45d5de0c8c3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d45d5de0c8c3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_history").Where("fromuser = ?", 1).Update("fromuser", 2)
	})
}

func TestWave2User_9f0c252974c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9f0c252974c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships_history").Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_8d21c566d0ae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d21c566d0ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Where("byuser = ?", 1).Update("byuser", 2)
	})
}

func TestWave2User_94b0be1107e0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "94b0be1107e0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("chatid = ?", 1).Update("chatid", 2)
	})
}

func TestWave2User_b986dc546bdb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b986dc546bdb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).
			Update("latestmessage", gorm.Expr("GREATEST(latestmessage, ?)", "2026-01-01 00:00:00"))
	})
}

func TestWave2User_b54105bda6de(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b54105bda6de", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2User_c76f7046abbd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c76f7046abbd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("user1", 2)
	})
}

func TestWave2User_9bddeb538459(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9bddeb538459", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("user2", 2)
	})
}

func TestWave2User_8dc9211fdcbe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8dc9211fdcbe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("userid = ?", 1).Update("userid", 2)
	})
}

// 47daaacbf97d, 3c462363aa82, e0386c3b3a9c, af0ff5e0f4de and f4a8db8dd183
// are all "UPDATE IGNORE ..." — see the file doc comment for why
// .Clauses(clause.Update{Modifier: "IGNORE"}) ahead of .Table(...) is safe
// here (it survives GORM's AddClauseIfNotExists in the update callback).
func TestWave2User_47daaacbf97d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "47daaacbf97d", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("chat_roster").
			Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_3c462363aa82(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3c462363aa82", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("sessions").
			Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_d19e540cf33b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d19e540cf33b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_e0386c3b3a9c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e0386c3b3a9c", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_logins").
			Where("userid = ? AND type = 'Native'", 2).Update("uid", 2)
	})
}

func TestWave2User_a54abf2c60c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a54abf2c60c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("fullname", "Jo Bloggs")
	})
}

func TestWave2User_fa6c771b3c3a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fa6c771b3c3a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("firstname", "Jo")
	})
}

func TestWave2User_247abd84a59e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "247abd84a59e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("lastname", "Bloggs")
	})
}

func TestWave2User_c26ff21a50e2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c26ff21a50e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("yahooid", "jbloggs")
	})
}

func TestWave2User_93b72dfa783e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "93b72dfa783e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("systemrole", "Moderator")
	})
}

func TestWave2User_c04d965dfd0e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c04d965dfd0e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("lastupdated", gorm.Expr("NOW()"))
	})
}

func TestWave2User_313bbe346bf7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "313bbe346bf7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("tnuserid", gorm.Expr("NULL"))
	})
}

func TestWave2User_a2ad9104af21(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a2ad9104af21", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 2).Update("tnuserid", 5)
	})
}

func TestWave2User_af0ff5e0f4de(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "af0ff5e0f4de", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_banned").
			Where("userid = ?", 1).Update("userid", 2)
	})
}

func TestWave2User_f4a8db8dd183(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f4a8db8dd183", func(tx *gorm.DB) *gorm.DB {
		return tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_banned").
			Where("byuser = ?", 1).Update("byuser", 2)
	})
}

func TestWave2User_ac3cfe1aa429(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ac3cfe1aa429", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND groupid = ?", 2, 3).Delete(nil)
	})
}

func TestWave2User_0c8e0836a74b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0c8e0836a74b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Delete(nil)
	})
}

func TestWave2User_fbbd963966cb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fbbd963966cb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Where("id = ?", 1).Update("userid", 2)
	})
}
