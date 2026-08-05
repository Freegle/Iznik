package test

// Wave 3, batch A (plan section 7.3+): the upsert-shaped sites (INSERT IGNORE
// and INSERT ... ON DUPLICATE KEY UPDATE) in message/message.go,
// message/bulkItem.go, chat/chatmessage.go, session/social_auth.go,
// communityevent/communityEvent.go, job/job.go, donations/donations.go,
// housekeeper/housekeeper.go, user/user.go, message/helper.go,
// donations/stripeipn.go, browse/scroll.go, donations/giftaid.go and
// message/bulkEdit.go.
//
// Upsert conventions, pinned by ormharness/upsert_test.go before this batch
// was written (read that file first if this looks unfamiliar):
//   - INSERT IGNORE converts with clause.Insert{Modifier: "IGNORE"}, never
//     clause.OnConflict{DoNothing: true}. Our .Table(...) convention keeps
//     stmt.Schema nil, so the MySQL driver's DoNothing fallback (which only
//     fires when Schema is non-nil) never runs, and DoNothing would render a
//     dangling "ON DUPLICATE KEY UPDATE" with nothing after it - not valid SQL.
//   - INSERT ... ON DUPLICATE KEY UPDATE converts with
//     clause.OnConflict{DoUpdates: ...}.
//   - "col = VALUES(col)" needs its assignment Value to be
//     clause.Column{Table: "excluded", Name: "col"} - the MySQL driver
//     rewrites that specific shape to VALUES(col); a plain Go value would
//     bind instead, which is a different statement.
//   - An assignment whose value is an EXPRESSION that merely mentions
//     VALUES(...) or another column (GREATEST(...), COALESCE(...), an
//     arithmetic increment) is not a bare column reference, so the excluded-
//     table trick does not apply; it goes through gorm.Expr with the literal
//     MySQL text instead, which MySQL evaluates identically to the original
//     raw SQL.
//
// SET-order care (gate (i)'s reasoning, extended to DoUpdates - see
// upsert_test.go's file comment and check-set-order.sh, which does not yet
// scan clause.Assignments(...) or clause.OnConflict literals, only
// Updates(map...)): clause.Assignments(map[string]interface{}{...}) sorts
// its keys alphabetically, same as Updates(map...). Every multi-assignment
// site in this batch was checked by hand: either every assigned value is
// independent of every OTHER assigned column (reordering changes nothing -
// the common case here, e.g. point/msgtype/arrival in messages_spatial, or
// name/description/interval_hours/enabled/placeholder in housekeeper_tasks),
// or the one cross-reference is a column referencing ITSELF (chat_roster's
// self-referencing "date = date", messages_bulk_items_interest's
// self-referencing state IF(...)), which is not order-dependent with respect
// to any of its statement's OTHER assignments. Sites with such a self- or
// no-reference use clause.Assignments(map...) for brevity; sites needing the
// excluded-table trick, or a specific SET order preserved for readability,
// spell out an explicit clause.Set literal instead - either form renders the
// same SQL once normaliseColumnOrder compares it against the golden (see
// golden.go), since none of these statements actually depend on assignment
// order.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- browse/scroll.go: RecordScrollDepth (session upsert) -------------------

func TestWave3A_b39f76d2f182(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b39f76d2f182", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("browse_scroll_depth").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"max_position":    gorm.Expr("GREATEST(max_position, VALUES(max_position))"),
				"items_available": gorm.Expr("COALESCE(VALUES(items_available), items_available)"),
				"userid":          gorm.Expr("COALESCE(VALUES(userid), userid)"),
			}),
		}).Create(map[string]interface{}{
			"session":         "sess-1",
			"userid":          1,
			"max_position":    5,
			"items_available": 10,
			"context":         "browse",
		})
	})
}

// --- chat/chatmessage.go: recordReplyAttribution -----------------------------

func TestWave3A_49e43e92d1e5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "49e43e92d1e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reply_attribution").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":           1,
			"userid":          2,
			"replied_at":      gorm.Expr("NOW()"),
			"was_home_member": 1,
		})
	})
}

func TestWave3A_db03a274a8a2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "db03a274a8a2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reply_attribution").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":                   1,
			"userid":                  2,
			"replied_at":              gorm.Expr("NOW()"),
			"was_home_member":         1,
			"was_notified":            0,
			"was_ripple_group_member": 0,
			"was_ripple_join":         0,
			"in_origin_catchment":     nil,
			"in_reach":                nil,
			"post_had_rippled":        1,
			"attribution":             "Notified",
			"client_source":           "web",
		})
	})
}

// --- chat/chatmessage.go: CreateChatMessage (held-reply metric) -------------

func TestWave3A_ac238fc96e7e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ac238fc96e7e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_event_metrics").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"count": gorm.Expr("count + 1")}),
		}).Create(map[string]interface{}{
			"day":   gorm.Expr("CURDATE()"),
			"event": gorm.Expr("'held'"),
			"count": gorm.Expr("1"),
		})
	})
}

// --- chat/chatmessage.go: approveChatMessage (spam whitelist) ---------------

func TestWave3A_af7676dc1734(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "af7676dc1734", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_whitelist_subjects").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"subject": "Free chair",
			"comment": gorm.Expr("'Marked as not spam'"),
		})
	})
}

// --- communityevent/communityEvent.go: Create / PatchRequest (AddGroup) -----

// 74c3a59d2291 and 154548bd2551 are the same statement shape at two call
// sites (Create and the PatchRequest AddGroup action); converted together
// per gate (h).
func TestWave3A_74c3a59d2291(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "74c3a59d2291", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"eventid": 1,
			"groupid": 2,
		})
	})
}

func TestWave3A_154548bd2551(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "154548bd2551", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("communityevents_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"eventid": 1,
			"groupid": 2,
		})
	})
}

// --- donations/donations.go: AddDonation -------------------------------------

func TestWave3A_6204c4ea5ebe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6204c4ea5ebe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "userid"}, Value: clause.Column{Table: "excluded", Name: "userid"}},
				{Column: clause.Column{Name: "timestamp"}, Value: clause.Column{Table: "excluded", Name: "timestamp"}},
			},
		}).Create(map[string]interface{}{
			"userid":           1,
			"Payer":            "payer@example.org",
			"PayerDisplayName": "A Donor",
			"timestamp":        "2026-01-01 12:00:00",
			"TransactionID":    "External for #1",
			"GrossAmount":      10.0,
			"type":             "External",
			"source":           "BankTransfer",
		})
	})
}

// --- donations/donations.go: BulkUploadDonations -----------------------------

func TestWave3A_e71c28654c59(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e71c28654c59", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_donations").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "userid"}, Value: clause.Column{Table: "excluded", Name: "userid"}},
				{Column: clause.Column{Name: "timestamp"}, Value: clause.Column{Table: "excluded", Name: "timestamp"}},
				{Column: clause.Column{Name: "source"}, Value: clause.Column{Table: "excluded", Name: "source"}},
				{Column: clause.Column{Name: "GrossAmount"}, Value: clause.Column{Table: "excluded", Name: "GrossAmount"}},
			},
		}).Create(map[string]interface{}{
			"userid":           1,
			"Payer":            "donor@example.org",
			"PayerDisplayName": "A Donor",
			"timestamp":        "2026-01-01 12:00:00",
			"TransactionID":    "TXN123",
			"GrossAmount":      10.0,
			"type":             gorm.Expr("'PayPal'"),
			"source":           "PayPalGivingFund",
		})
	})
}

// --- donations/giftaid.go: DeleteGiftAid --------------------------------------

func TestWave3A_baf0645f5f91(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "baf0645f5f91", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("giftaid").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"period":  gorm.Expr("'Declined'"),
				"deleted": gorm.Expr("NOW()"),
			}),
		}).Create(map[string]interface{}{
			"userid":      1,
			"period":      gorm.Expr("'Declined'"),
			"fullname":    "A User",
			"homeaddress": gorm.Expr("''"),
		})
	})
}

// --- donations/stripeipn.go: handleGiftAidNotification ------------------------

func TestWave3A_a827fcf725a7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a827fcf725a7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_notifications").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"fromuser":  gorm.Expr("NULL"),
			"touser":    1,
			"type":      gorm.Expr("'GiftAid'"),
			"timestamp": gorm.Expr("NOW()"),
		})
	})
}

// --- housekeeper/housekeeper.go: upsertRegistry -------------------------------

func TestWave3A_9bf0aed4b060(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9bf0aed4b060", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("housekeeper_tasks").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "name"}, Value: clause.Column{Table: "excluded", Name: "name"}},
				{Column: clause.Column{Name: "description"}, Value: clause.Column{Table: "excluded", Name: "description"}},
				{Column: clause.Column{Name: "interval_hours"}, Value: clause.Column{Table: "excluded", Name: "interval_hours"}},
				{Column: clause.Column{Name: "enabled"}, Value: clause.Column{Table: "excluded", Name: "enabled"}},
				{Column: clause.Column{Name: "placeholder"}, Value: clause.Column{Table: "excluded", Name: "placeholder"}},
				{Column: clause.Column{Name: "updated_at"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"task_key":       "task-1",
			"name":           "Task One",
			"description":    "Does a thing",
			"interval_hours": 24,
			"enabled":        1,
			"placeholder":    0,
			"updated_at":     gorm.Expr("NOW()"),
		})
	})
}

// --- housekeeper/housekeeper.go: upsertLastRun --------------------------------

func TestWave3A_dbbe018930e1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dbbe018930e1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("housekeeper_tasks").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "last_run_at"}, Value: gorm.Expr("NOW()")},
				{Column: clause.Column{Name: "last_status"}, Value: clause.Column{Table: "excluded", Name: "last_status"}},
				{Column: clause.Column{Name: "last_summary"}, Value: clause.Column{Table: "excluded", Name: "last_summary"}},
				{Column: clause.Column{Name: "updated_at"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"task_key":     "task-1",
			"name":         "task-1",
			"last_run_at":  gorm.Expr("NOW()"),
			"last_status":  "success",
			"last_summary": "Done",
			"updated_at":   gorm.Expr("NOW()"),
		})
	})
}

// --- job/job.go: RecordJobClick (known user / anonymous) ---------------------

func TestWave3A_427bf8bd0bc7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "427bf8bd0bc7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs_jobs").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid":    1,
			"jobid":     2,
			"link":      "https://example.org/job",
			"placement": "list",
			"source":    "search",
			"page":      1,
		})
	})
}

// 9d84c4751dd8: anonymous click. "NULL" is written into the golden SQL text
// itself (not a bind), because the Go source branches to a literal-NULL
// variant of the statement rather than binding a nil userid - so the
// conversion must use gorm.Expr("NULL") to match, not a nil map value (which
// would render as a bound "?").
func TestWave3A_9d84c4751dd8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9d84c4751dd8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs_jobs").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid":    gorm.Expr("NULL"),
			"jobid":     2,
			"link":      "https://example.org/job",
			"placement": "list",
			"source":    "search",
			"page":      1,
		})
	})
}

// --- message/bulkEdit.go: ensureBulkEditToken ---------------------------------

func TestWave3A_eccba6faf480(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "eccba6faf480", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"edittoken": gorm.Expr("COALESCE(edittoken, VALUES(edittoken))"),
			}),
		}).Create(map[string]interface{}{
			"msgid":     1,
			"edittoken": "tok123",
		})
	})
}

// --- message/bulkItem.go: linkBulkItemAttachment ------------------------------

func TestWave3A_48b8f4df0768(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "48b8f4df0768", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_item_attachments").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "bulkitemid"}, Value: clause.Column{Table: "excluded", Name: "bulkitemid"}},
			},
		}).Create(map[string]interface{}{
			"bulkitemid":   1,
			"attachmentid": 2,
		})
	})
}

// --- message/bulkItem.go: saveAccessInstructions ------------------------------

func TestWave3A_a3b40fe84086(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a3b40fe84086", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "accessinstructions"}, Value: clause.Column{Table: "excluded", Name: "accessinstructions"}},
			},
		}).Create(map[string]interface{}{
			"msgid":              1,
			"accessinstructions": "Ring the bell",
		})
	})
}

// --- message/bulkItem.go: handleBulkInterest (picked items) -------------------

// fde42951834d: state's IF(...) expression is self-referencing (it reads
// "state", the very column it assigns), not a reference to a DIFFERENT
// assigned column, so the SET order is not load-bearing here.
func TestWave3A_fde42951834d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fde42951834d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "quantity"}, Value: clause.Column{Table: "excluded", Name: "quantity"}},
				{Column: clause.Column{Name: "cancollect"}, Value: clause.Column{Table: "excluded", Name: "cancollect"}},
				{Column: clause.Column{Name: "chatid"}, Value: clause.Column{Table: "excluded", Name: "chatid"}},
				{Column: clause.Column{Name: "state"}, Value: gorm.Expr("IF(state IN ('Reserved','Collected','Rejected'), state, 'Interested')")},
			},
		}).Create(map[string]interface{}{
			"bulkitemid": 1,
			"msgid":      2,
			"userid":     3,
			"quantity":   4,
			"cancollect": "Yes",
			"chatid":     5,
			"state":      gorm.Expr("'Interested'"),
		})
	})
}

// --- message/bulkItem.go: handleBulkInterestState (messages_by) ---------------

func TestWave3A_35b87def61f8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "35b87def61f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"count": gorm.Expr("count + VALUES(count)"),
			}),
		}).Create(map[string]interface{}{
			"msgid":  1,
			"userid": 2,
			"count":  3,
		})
	})
}

// --- message/bulkItem.go: findOrCreateUser2UserRoom (chat_roster x2) ----------

// 239d2cb0036e and 9e4c6da913a4 are the same statement at two call sites (one
// per participant); converted together per gate (h). The DoUpdates value is
// a bare clause.Column{Name: "date"} with no Table set, so the MySQL driver's
// "excluded" rewrite does not apply and it renders the column's own name -
// "date = date", a deliberate no-op that satisfies MySQL's requirement for
// something after ON DUPLICATE KEY UPDATE without touching the existing row.
func TestWave3A_239d2cb0036e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "239d2cb0036e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "date"}, Value: clause.Column{Name: "date"}},
			},
		}).Create(map[string]interface{}{
			"chatid": 1,
			"userid": 2,
			"status": "Online",
			"date":   gorm.Expr("NOW()"),
		})
	})
}

func TestWave3A_9e4c6da913a4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9e4c6da913a4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "date"}, Value: clause.Column{Name: "date"}},
			},
		}).Create(map[string]interface{}{
			"chatid": 1,
			"userid": 3,
			"status": "Online",
			"date":   gorm.Expr("NOW()"),
		})
	})
}

// --- message/helper.go: insertHelperChat --------------------------------------

func TestWave3A_82f73ff0af1b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "82f73ff0af1b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_sent_messages").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "kind"}, Value: clause.Column{Table: "excluded", Name: "kind"}},
			},
		}).Create(map[string]interface{}{
			"batchid":    1,
			"chatmsgid":  2,
			"chatid":     3,
			"replierid":  4,
			"kind":       "offer",
			"auto":       true,
			"proposalid": 5,
		})
	})
}

// --- message/message.go: scrapeTNPhotosToAttachments ---------------------------

func TestWave3A_fa561d9680e2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fa561d9680e2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":       1,
			"externaluid": "ext-1",
			"primary":     1,
		})
	})
}

// --- message/message.go: GetMessagesByIds (reply_blocked metric) --------------

func TestWave3A_1bdbafed31fa(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1bdbafed31fa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_event_metrics").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"count": gorm.Expr("count + ?", 3),
			}),
		}).Create(map[string]interface{}{
			"day":   gorm.Expr("CURDATE()"),
			"event": gorm.Expr("'reply_blocked'"),
			"count": 3,
		})
	})
}

// --- message/message.go: addApprovedMessageToSpatialIndex ---------------------

// groupid is part of the unique key, so it is deliberately absent from
// DoUpdates - never updated on conflict.
func TestWave3A_b00f9d848435(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b00f9d848435", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spatial").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "point"}, Value: clause.Column{Table: "excluded", Name: "point"}},
				{Column: clause.Column{Name: "msgtype"}, Value: clause.Column{Table: "excluded", Name: "msgtype"}},
				{Column: clause.Column{Name: "arrival"}, Value: clause.Column{Table: "excluded", Name: "arrival"}},
			},
		}).Create(map[string]interface{}{
			"msgid":   1,
			"point":   gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)", -0.1, 51.5),
			"groupid": 2,
			"msgtype": "Offer",
			"arrival": "2026-01-01 12:00:00",
		})
	})
}

// --- message/message.go: RecordRippleEvent -------------------------------------

func TestWave3A_6c9b19809e6c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6c9b19809e6c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_event_metrics").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"count": gorm.Expr("count + 1")}),
		}).Create(map[string]interface{}{
			"day":   gorm.Expr("CURDATE()"),
			"event": "rippled",
			"count": gorm.Expr("1"),
		})
	})
}

// --- message/message.go: handlePartnerConsent -----------------------------------

func TestWave3A_dc26aeceefa9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dc26aeceefa9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("partners_messages").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"partnerid": 1,
			"msgid":     2,
		})
	})
}

// --- message/message.go: handleRejectToDraft --------------------------------

func TestWave3A_b9a68fc74595(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b9a68fc74595", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_drafts").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":   1,
			"groupid": 2,
			"userid":  3,
		})
	})
}

// --- message/message.go: JoinAndPostAs (memberships / messages_groups / --------
// --- messages_history / users_logins) -------------------------------------

func TestWave3A_419abfa0cef3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "419abfa0cef3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid":     1,
			"groupid":    2,
			"role":       "Member",
			"collection": "Approved",
		})
	})
}

func TestWave3A_578ac6d80c06(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "578ac6d80c06", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":      1,
			"groupid":    2,
			"collection": "Pending",
			"arrival":    gorm.Expr("NOW()"),
		})
	})
}

func TestWave3A_cb477fe8b7d2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cb477fe8b7d2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_history").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":    1,
			"groupid":  2,
			"source":   gorm.Expr("'Platform'"),
			"fromuser": 3,
			"fromname": "A User",
			"fromaddr": "user@users.ilovefreegle.org",
			"subject":  "Offer: Chair (Bristol)",
			"arrival":  gorm.Expr("NOW()"),
			"fromip":   "127.0.0.1",
		})
	})
}

func TestWave3A_92e739e16c30(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "92e739e16c30", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "credentials"}, Value: clause.Column{Table: "excluded", Name: "credentials"}},
				{Column: clause.Column{Name: "salt"}, Value: clause.Column{Table: "excluded", Name: "salt"}},
			},
		}).Create(map[string]interface{}{
			"userid":      1,
			"type":        "Native",
			"uid":         1,
			"credentials": "hashed",
			"salt":        "salt",
		})
	})
}

// --- message/message.go: PutMessageAs (messages_items) --------------------

func TestWave3A_e7f18e30931f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e7f18e30931f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_items").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":  1,
			"itemid": 2,
		})
	})
}

// --- message/message.go: handleOutcomeIntended -------------------------------

func TestWave3A_62d2f10ad97c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "62d2f10ad97c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes_intended").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "outcome"}, Value: clause.Column{Table: "excluded", Name: "outcome"}},
			},
		}).Create(map[string]interface{}{
			"msgid":   1,
			"outcome": "Taken",
		})
	})
}

// --- message/message.go: handleView (messages_likes) -------------------------

// e8a9588d340c: none of timestamp/count/pageview/source reference a
// DIFFERENT assigned column (count and source are self-referencing only), so
// the SET order is not load-bearing.
func TestWave3A_e8a9588d340c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e8a9588d340c", func(tx *gorm.DB) *gorm.DB {
		src := "browse"
		return tx.Table("messages_likes").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "timestamp"}, Value: gorm.Expr("NOW()")},
				{Column: clause.Column{Name: "count"}, Value: gorm.Expr("count + 1")},
				{Column: clause.Column{Name: "pageview"}, Value: gorm.Expr("1")},
				{Column: clause.Column{Name: "source"}, Value: gorm.Expr("COALESCE(?, source)", src)},
			},
		}).Create(map[string]interface{}{
			"msgid":    1,
			"userid":   2,
			"type":     gorm.Expr("'View'"),
			"pageview": gorm.Expr("1"),
			"source":   src,
		})
	})
}

// --- message/message.go: recordAIDeletions ------------------------------------

func TestWave3A_44a89f7db2ae(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "44a89f7db2ae", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_ai_declined").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid": 1,
		})
	})
}

// --- session/social_auth.go: socialMatchOrCreate ------------------------------

func TestWave3A_c86a6d9efeb3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c86a6d9efeb3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid": 1,
			"type":   "Google",
			"uid":    "google-uid-1",
		})
	})
}

func TestWave3A_1013ca206ab1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1013ca206ab1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid":    1,
			"email":     "user@example.org",
			"preferred": gorm.Expr("0"),
			"validated": gorm.Expr("NOW()"),
			"canon":     "user@example.org",
			"backwards": "gro.elpmaxe@resu",
		})
	})
}

func TestWave3A_9cb4eab12012(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9cb4eab12012", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid": 1,
			"type":   "Facebook",
			"uid":    "fb-uid-1",
		})
	})
}

// --- user/user.go: PatchUser (set password for self/other user) ------------

// 51fa5d2c1016: unlike the other users_logins upserts in this batch, the
// original SQL rebinds the SAME Go variables (hashed, salt) for both the
// INSERT values and the ON DUPLICATE KEY UPDATE, rather than referencing
// VALUES(col) - so the conversion binds plain Go values, not
// clause.Column{Table: "excluded", ...}.
func TestWave3A_51fa5d2c1016(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "51fa5d2c1016", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_logins").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"credentials": "hashed",
				"salt":        "salt",
			}),
		}).Create(map[string]interface{}{
			"userid":      1,
			"type":        "Native",
			"uid":         "1",
			"credentials": "hashed",
			"salt":        "salt",
		})
	})
}
