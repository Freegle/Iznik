package test

// LastInsertId reopening (see test/orm_insertid_test.go for the proof against
// the real database): 58 INSERT sites were kept raw on the theory that
// LAST_INSERT_ID() is connection-scoped session state and GORM's map-Create id
// writeback is undocumented. The first half is true of the SQL FUNCTION and
// irrelevant to what GORM does - gorm.io/gorm/callbacks/create.go reads
// result.LastInsertId() from the SAME sql.Result the INSERT returned, and with
// Statement.Schema nil (the Table()+map case) writes it into the map under
// "@id". No second query, so no connection to lose.
//
// This batch: the sites in user/user.go, modconfig/modconfig.go,
// session/social_auth.go, auth/auth.go, admin/admin.go, chat/chatroom.go,
// message/message.go, message/helper.go and message/bulkItem.go that fit the
// plain "INSERT then read the id in Go" pattern. Two sub-categories of
// LastInsertId site in this same file set do NOT fit it and were left alone,
// with their keep-raw.json reasons corrected rather than removed:
//   - the `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` upsert idiom in
//     SQL TEXT (nine sites) - a different mechanism (the SQL function forcing
//     the connection's last-insert-id to the pre-existing row's id on a
//     duplicate-key hit), which check-lastinsertid.sh still has a real job
//     enforcing;
//   - modconfig.go's two INSERT ... SELECT sites (6b9a23982cc9, e137c396bd13)
//     - GORM's map-Create takes values, not a query, so there is nothing to
//     port this pattern onto regardless of the id question.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- message/helper.go: helperCreateProposal ---------------------------------

func TestInsertIDConv_7394291c903d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7394291c903d", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"batchid":       1,
			"type":          "allocation",
			"replierid":     2,
			"bulkitemid":    3,
			"summary":       "summary",
			"proposed_text": "text",
			"payload":       "{}",
			"rationale":     "because",
			"status":        gorm.Expr("'pending'"),
		}
		return tx.Table("helper_proposals").Create(row)
	})
}

// --- message/helper.go: insertHelperChat --------------------------------------

func TestInsertIDConv_d3790f53ec52(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d3790f53ec52", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"chatid":             1,
			"userid":             2,
			"type":               "Default",
			"refmsgid":           3,
			"date":               "2026-01-01 12:00:00",
			"message":            "hello",
			"processingrequired": gorm.Expr("1"),
		}
		return tx.Table("chat_messages").Create(row)
	})
}

// --- message/helper.go: helperSendAction (approve-mode proposal) -------------

func TestInsertIDConv_7ec60cde5d39(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7ec60cde5d39", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"batchid":       1,
			"type":          gorm.Expr("'message'"),
			"replierid":     2,
			"summary":       "Message to send (email)",
			"proposed_text": "body",
			"status":        gorm.Expr("'pending'"),
		}
		return tx.Table("helper_proposals").Create(row)
	})
}

// --- message/bulkItem.go: upsertBulkItems (new item) --------------------------

func TestInsertIDConv_faa9018435a3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "faa9018435a3", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"msgid":       1,
			"position":    0,
			"name":        "Chair",
			"quantity":    1,
			"condition":   "Good",
			"dimensions":  "1x1",
			"photourl":    "http://example.org/1.jpg",
			"description": "A chair",
		}
		return tx.Table("messages_bulk_items").Create(row)
	})
}

// --- message/bulkItem.go: ingestBulkItemPhotos --------------------------------

func TestInsertIDConv_fb88302d28cb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fb88302d28cb", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{"msgid": 1, "externaluid": "ext-1"}
		return tx.Table("messages_attachments").Create(row)
	})
}

// --- user/user.go: PutUser (new user) -----------------------------------------

func TestInsertIDConv_24704432f4d4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "24704432f4d4", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"fullname":  "A User",
			"firstname": "A",
			"lastname":  "User",
			"added":     gorm.Expr("NOW()"),
		}
		return tx.Table("users").Create(row)
	})
}

// --- user/user.go: PutUser (session for the new user) --------------------------

func TestInsertIDConv_c9c7922e5379(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c9c7922e5379", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":     1,
			"series":     2,
			"token":      "tok",
			"lastactive": gorm.Expr("NOW()"),
		}
		return tx.Table("sessions").Create(row)
	})
}

// --- modconfig/modconfig.go: PostModConfig (simple create) ---------------------

func TestInsertIDConv_181d8342ea4a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "181d8342ea4a", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"name":           "My config",
			"createdby":      1,
			"ccrejectaddr":   gorm.Expr("''"),
			"ccfollowupaddr": gorm.Expr("''"),
			"ccrejmembaddr":  gorm.Expr("''"),
			"ccfollmembaddr": gorm.Expr("''"),
			"network":        gorm.Expr("''"),
		}
		return tx.Table("mod_configs").Create(row)
	})
}

// --- session/social_auth.go: socialMatchOrCreate (new user) --------------------

func TestInsertIDConv_bbbc465b075c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bbbc465b075c", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"fullname":  "A User",
			"firstname": "A",
			"lastname":  "User",
			"added":     gorm.Expr("NOW()"),
		}
		return tx.Table("users").Create(row)
	})
}

// --- auth/auth.go: CreateSessionAndJWT -----------------------------------------

func TestInsertIDConv_45c97c1f1f58(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "45c97c1f1f58", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":     1,
			"series":     2,
			"token":      "tok",
			"date":       gorm.Expr("NOW()"),
			"lastactive": gorm.Expr("NOW()"),
		}
		return tx.Table("sessions").Create(row)
	})
}

// --- admin/admin.go: PostAdmin --------------------------------------------------

func TestInsertIDConv_88399aa73e56(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "88399aa73e56", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"createdby":     1,
			"groupid":       2,
			"subject":       "subject",
			"text":          "text",
			"ctatext":       "cta",
			"ctalink":       "https://example.org",
			"essential":     true,
			"template":      "default",
			"editprotected": false,
			"sendafter":     "2026-01-01 12:00:00",
			"created":       gorm.Expr("NOW()"),
		}
		return tx.Table("admins").Create(row)
	})
}

// --- chat/chatroom.go: handleNudge (create nudge message) ----------------------

func TestInsertIDConv_1f5798e8214d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1f5798e8214d", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"chatid":               1,
			"userid":               2,
			"type":                 "Nudge",
			"date":                 "2026-01-01 12:00:00",
			"message":              gorm.Expr("''"),
			"replyexpected":        gorm.Expr("1"),
			"reportreason":         gorm.Expr("NULL"),
			"reviewrequired":       gorm.Expr("0"),
			"reviewrejected":       gorm.Expr("0"),
			"processingsuccessful": gorm.Expr("1"),
		}
		return tx.Table("chat_messages").Create(row)
	})
}

// --- message/message.go: PutMessageAs (create the message) ---------------------

func TestInsertIDConv_1590b130f529(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1590b130f529", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"fromuser":           1,
			"type":               "Offer",
			"subject":            "Chair",
			"textbody":           "Free chair",
			"message":            "Free chair",
			"arrival":            gorm.Expr("NOW()"),
			"date":               gorm.Expr("NOW()"),
			"source":             gorm.Expr("'Platform'"),
			"availableinitially": 1,
			"availablenow":       1,
			"locationid":         2,
			"fromip":             "127.0.0.1",
			"fromcountry":        "GB",
			"messageid":          "1.0@example.org",
		}
		return tx.Table("messages").Create(row)
	})
}
