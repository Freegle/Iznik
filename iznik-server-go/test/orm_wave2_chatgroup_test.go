package test

// Wave 2 (single-table writes), chat and group modules (plan section 7.3+):
// chat message creation/moderation (chat/chatmessage.go) and chat room/roster
// management (chat/chatroom.go), plus group settings edits and group/
// membership creation (group/group.go).
//
// Note the write conventions, which differ from the read waves:
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - gorm.Expr(...) for a literal or expression value (NOW(), NULL, a bare
//     0 or 1, or a quoted string literal such as 'Unmoderated') rather than a
//     plain Go value, which would bind as "?" and diverge from a golden that
//     writes the literal inline.
//   - Table(...).Create(map[string]interface{}{...}) for INSERTs; a value
//     that must render as a SQL expression (NOW(), or a JSON_OBJECT(...) call
//     with its own embedded placeholders) goes through gorm.Expr the same way
//     an UPDATE value would.
//
// One site (1f5798e8214d, chat/chatroom.go handleNudge) is left raw: the
// INSERT exists specifically to call sql.Result.LastInsertId() on the same
// connection that ran it, and GORM's map-Create id writeback for a
// schema-less Table()+map call is undocumented behaviour, not something to
// rely on for a fresh message id. See the "Left raw" comment at that site.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- chat/chatmessage.go: CreateChatMessage ---------------------------------

func TestWave2ChatGroup_e9be2e263367(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e9be2e263367", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_held_replies").Create(map[string]interface{}{
			"chatid":        1,
			"chatmsgid":     2,
			"msgid":         3,
			"replieruserid": 4,
			"source":        gorm.Expr("'web'"),
			"lat":           51.5,
			"lng":           -0.1,
			"status":        gorm.Expr("'held'"),
			"created_at":    gorm.Expr("NOW()"),
		})
	})
}

// b443c0f36dd2 and 8eddd54c5c0b are the same statement at two call sites
// (CreateChatMessage and CreateChatMessageLoveJunk), converted together: gate
// (h) refuses a half-converted pair, because converting one renumbers the
// survivor's site ID.
func TestWave2ChatGroup_b443c0f36dd2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b443c0f36dd2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_images").Where("id = ?", 1).Update("chatmsgid", 2)
	})
}

func TestWave2ChatGroup_8eddd54c5c0b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8eddd54c5c0b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_images").Where("id = ?", 1).Update("chatmsgid", 2)
	})
}

// --- chat/chatmessage.go: CreateChatMessageLoveJunk -------------------------

func TestWave2ChatGroup_d0743f528af9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d0743f528af9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("ljofferid", 2)
	})
}

// --- chat/chatmessage.go: PatchChatMessage ----------------------------------

func TestWave2ChatGroup_89645312ccd2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "89645312ccd2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).Update("replyexpected", 1)
	})
}

// --- chat/chatmessage.go: DeleteChatMessage ---------------------------------

// 4c1209980f1f: golden sets literals deleted = 1 and imageid = NULL, so both
// go through gorm.Expr rather than as binds.
func TestWave2ChatGroup_4c1209980f1f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4c1209980f1f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"type": "Default", "deleted": gorm.Expr("1"), "imageid": gorm.Expr("NULL")})
	})
}

func TestWave2ChatGroup_4b773363a4f4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4b773363a4f4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_images").Where("chatmsgid = ?", 1).Delete(nil)
	})
}

// --- chat/chatmessage.go: autoApproveModmails / approveChatMessage ---------

// 7631a317a13b and a2ab9aecba3e are the same statement at two call sites
// (autoApproveModmails and approveChatMessage), converted together.
func TestWave2ChatGroup_7631a317a13b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7631a317a13b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "reviewedby": 2})
	})
}

func TestWave2ChatGroup_a2ab9aecba3e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a2ab9aecba3e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "reviewedby": 2})
	})
}

// --- chat/chatmessage.go: updateMessageCounts -------------------------------

func TestWave2ChatGroup_f4cc7314bea4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f4cc7314bea4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).
			Updates(map[string]interface{}{"msgvalid": 2, "msginvalid": 3, "latestmessage": "2026-01-01 00:00:00"})
	})
}

// --- chat/chatmessage.go: approveChatMessage / rejectChatMessage -----------

// ef470baa050b and b8fc832d50a0 are the same statement at two call sites
// (approveChatMessage and rejectChatMessage), converted together.
func TestWave2ChatGroup_ef470baa050b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ef470baa050b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      "Chat",
			"subtype":   "Approved",
			"user":      1,
			"byuser":    2,
			"text":      "Chat message 3 approved",
		})
	})
}

func TestWave2ChatGroup_b8fc832d50a0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b8fc832d50a0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      "Chat",
			"subtype":   "Rejected",
			"user":      1,
			"byuser":    2,
			"text":      "Chat message 3 rejected",
		})
	})
}

// 2e5a6f9c7077, 030c4c489394 and 91271beda4fa are the same statement at three
// call sites (approveChatMessage, rejectChatMessage's two hold-removal paths
// share one site since they render identical SQL, and releaseChatMessage),
// converted together: a half-converted group renumbers the survivors' site
// IDs, so gate (h) refuses the split state.
func TestWave2ChatGroup_2e5a6f9c7077(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2e5a6f9c7077", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages_held").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2ChatGroup_030c4c489394(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "030c4c489394", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages_held").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2ChatGroup_91271beda4fa(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "91271beda4fa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages_held").Where("msgid = ?", 1).Delete(nil)
	})
}

// 818065956105: golden sets a literal 'Unmoderated', so it goes through
// gorm.Expr rather than as a bind, which would have rendered
// "chatmodstatus = ?".
func TestWave2ChatGroup_818065956105(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "818065956105", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("chatmodstatus", gorm.Expr("'Unmoderated'"))
	})
}

// --- chat/chatmessage.go: rejectChatMessage (duplicate-message flood path) -

// 09878cd3c660 and 29b68b1db029 are the same statement at two call sites
// within rejectChatMessage (the primary reject and the loop over identical
// pending duplicates), converted together.
func TestWave2ChatGroup_09878cd3c660(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "09878cd3c660", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "reviewedby": 2, "reviewrejected": gorm.Expr("1")})
	})
}

func TestWave2ChatGroup_29b68b1db029(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29b68b1db029", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "reviewedby": 2, "reviewrejected": gorm.Expr("1")})
	})
}

// --- chat/chatmessage.go: redactChatMessage ---------------------------------

func TestWave2ChatGroup_8e568332ecdb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8e568332ecdb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).Update("message", "cleaned text")
	})
}

// --- chat/chatroom.go: PutChatRoom ------------------------------------------

// c3c151b4b7a5 and a29c37823e28 are the same statement at two call sites
// (the existing-chat early return and the newly-created-chat path),
// converted together.
func TestWave2ChatGroup_c3c151b4b7a5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c3c151b4b7a5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Where("chatid = ? AND userid = ?", 1, 2).Update("status", "Online")
	})
}

func TestWave2ChatGroup_a29c37823e28(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a29c37823e28", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Where("chatid = ? AND userid = ?", 1, 2).Update("status", "Online")
	})
}

// --- chat/chatroom.go: handleNudge -------------------------------------------

// 8a247fbd07a3: identical SQL also appears in GetOrCreateUser2ModChat, which
// is left entirely raw (transaction/FOR UPDATE, keep-raw). That occurrence
// precedes this one in the file, so converting only this later occurrence
// does not renumber the untouched one - site IDs are keyed on (file, SQL,
// occurrence index) counted in file order, and removing a later occurrence
// never shifts an earlier one's index.
func TestWave2ChatGroup_8a247fbd07a3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8a247fbd07a3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestWave2ChatGroup_05c1cd917f86(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "05c1cd917f86", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_nudges").Create(map[string]interface{}{
			"fromuser": 1,
			"touser":   2,
		})
	})
}

// --- chat/chatroom.go: handleTyping ------------------------------------------

func TestWave2ChatGroup_16ab70b058c0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "16ab70b058c0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Where("chatid = ? AND userid = ?", 1, 2).Update("lasttype", gorm.Expr("NOW()"))
	})
}

// --- chat/chatroom.go: handleRosterUpdate ------------------------------------

func TestWave2ChatGroup_645c3f56baac(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "645c3f56baac", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Where("chatid = ? AND userid = ?", 1, 2).Update("lastmsgseen", 3)
	})
}

func TestWave2ChatGroup_54cdf0e1dfa0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "54cdf0e1dfa0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Where("chatid = ? AND userid = ? AND (lastmsgseen IS NULL OR lastmsgseen < ?)", 1, 2, 3).
			Update("lastmsgseen", 3)
	})
}

// --- chat/chatroom.go: handleReferToSupport / handleReportNoGroup ----------

// 747de3c4a649: golden's JSON_OBJECT(...) call has its own embedded
// placeholders, so it goes through gorm.Expr with its args rather than a
// plain map value.
func TestWave2ChatGroup_747de3c4a649(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "747de3c4a649", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "refer_to_support",
			"data":      gorm.Expr("JSON_OBJECT('chatid', ?, 'userid', ?)", 1, 2),
		})
	})
}

func TestWave2ChatGroup_bc1ca64ed7ec(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bc1ca64ed7ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_chat_spam_report",
			"data":      gorm.Expr("JSON_OBJECT('chatid', ?, 'userid', ?, 'reason', ?, 'comment', ?)", 1, 2, "Spam", "comment text"),
		})
	})
}

// --- group/group.go: logGroupEdit -------------------------------------------

func TestWave2ChatGroup_cbad92a90c0d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cbad92a90c0d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      "Group",
			"subtype":   "Edit",
			"groupid":   1,
			"byuser":    2,
			"text":      "Settings",
		})
	})
}

// --- group/group.go: PatchGroup - mod/owner settable fields ----------------

func TestWave2ChatGroup_b1c25f5b67a9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b1c25f5b67a9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("tagline", "X")
	})
}

func TestWave2ChatGroup_bc7de11031d3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bc7de11031d3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("namefull", "X")
	})
}

func TestWave2ChatGroup_7a9e12196036(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7a9e12196036", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("welcomemail", "X")
	})
}

func TestWave2ChatGroup_fb4e48c03bee(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fb4e48c03bee", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("description", "X")
	})
}

func TestWave2ChatGroup_4fd65f2418a6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4fd65f2418a6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("region", "X")
	})
}

func TestWave2ChatGroup_b7a7c29b7611(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b7a7c29b7611", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).
			Updates(map[string]interface{}{"affiliationconfirmed": "2026-01-01 00:00:00", "affiliationconfirmedby": 2})
	})
}

func TestWave2ChatGroup_0510fa1a8a85(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0510fa1a8a85", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("onhere", 1)
	})
}

func TestWave2ChatGroup_fd0917d06441(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fd0917d06441", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("publish", 1)
	})
}

func TestWave2ChatGroup_2e011a3ca233(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2e011a3ca233", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("microvolunteering", 1)
	})
}

func TestWave2ChatGroup_11cd46e11f5a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "11cd46e11f5a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("microvolunteeringoptions", "{}")
	})
}

func TestWave2ChatGroup_dbd2165e28c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dbd2165e28c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("mentored", 1)
	})
}

func TestWave2ChatGroup_5a1f3cd17397(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5a1f3cd17397", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("ontn", 1)
	})
}

func TestWave2ChatGroup_1e4d0a106c72(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1e4d0a106c72", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("onlovejunk", 1)
	})
}

func TestWave2ChatGroup_23cf0e34c542(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "23cf0e34c542", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("profile", 2)
	})
}

func TestWave2ChatGroup_585a51354a68(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "585a51354a68", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("settings", "{}")
	})
}

func TestWave2ChatGroup_6de535f15717(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6de535f15717", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("rules", "{}")
	})
}

// --- group/group.go: PatchGroup / CreateGroup - admin-only fields ----------

// 6a4f5b776c87 and adbbb9dadd0c are the same statement at two call sites
// (PatchGroup and CreateGroup), converted together: a half-converted pair
// renumbers the survivor's site ID, so gate (h) refuses the split state.
func TestWave2ChatGroup_6a4f5b776c87(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6a4f5b776c87", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("lng", 2.5)
	})
}

func TestWave2ChatGroup_adbbb9dadd0c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "adbbb9dadd0c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("lng", 2.5)
	})
}

func TestWave2ChatGroup_0e9905e6f0ce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0e9905e6f0ce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("altlat", 2.5)
	})
}

func TestWave2ChatGroup_22727b8ed343(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "22727b8ed343", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("altlng", 2.5)
	})
}

func TestWave2ChatGroup_e5fa7f0e05bd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e5fa7f0e05bd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("nameshort", "X")
	})
}

func TestWave2ChatGroup_dd57ad0485df(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dd57ad0485df", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("licenserequired", 1)
	})
}

// 7993248ef4e6: golden clears the column with a literal NULL, so it goes
// through gorm.Expr rather than as a bind.
func TestWave2ChatGroup_7993248ef4e6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7993248ef4e6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("poly", gorm.Expr("NULL"))
	})
}

func TestWave2ChatGroup_450c5b5fca94(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "450c5b5fca94", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("poly", "POINT(0 0)")
	})
}

// cff1d8adacf1: same reasoning as 7993248ef4e6.
func TestWave2ChatGroup_cff1d8adacf1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cff1d8adacf1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("polyofficial", gorm.Expr("NULL"))
	})
}

func TestWave2ChatGroup_e4f0bcf9f2eb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e4f0bcf9f2eb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("polyofficial", "POINT(0 0)")
	})
}

func TestWave2ChatGroup_34c2c6e9128b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "34c2c6e9128b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Where("id = ?", 1).Update("showonyahoo", 1)
	})
}

// --- group/group.go: CreateGroup ---------------------------------------------

func TestWave2ChatGroup_ea603dbc3fe0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ea603dbc3fe0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Create(map[string]interface{}{
			"userid":     1,
			"groupid":    2,
			"role":       "Owner",
			"collection": "Approved",
		})
	})
}
