package test

// Wave 2 (single-table writes), message module, second batch ("msg2").
//
// The first wave-2 message batch (orm_wave2_message_test.go) proved twelve
// sites in bulkEdit.go and bulkItem.go. This batch covers the remaining
// wave-2 single-table WRITE sites across bulkItem.go, helper.go and
// message.go: 113 of the 119 assigned sites. The other six are INSERTs whose
// caller reads back the new row's id via sql.Result.LastInsertId() on the
// same connection (findOrCreateUser2UserRoom-adjacent patterns in
// upsertBulkItems, ingestBulkItemPhotos, helperCreateProposal,
// insertHelperChat, helperSendAction's proposal path, and PutMessageAs's
// core INSERT INTO messages) and were deliberately left raw, per the same
// reasoning as orm_wave2_pilot_test.go's write-up on findOrCreateUserForDraft
// and CreatePartnerUser.
//
// Conventions (same as orm_wave2_message_test.go and the wave 2 pilot):
//   - .Table(...) and never .Model(...), so stmt.Schema stays nil and GORM
//     cannot inject an updated_at column the golden does not have.
//   - .Delete(nil) rather than .Delete(&Struct{}), so no schema is parsed and
//     a soft-delete field cannot silently turn a DELETE into an UPDATE.
//   - A literal in the golden (NOW(), NULL, a bare 0 or 1, a quoted string
//     constant, an expression like LEAST(...)/GREATEST(...)/JSON_ARRAY_APPEND(...))
//     goes through gorm.Expr(...), optionally with its own binds, rather than
//     as a plain map/Update value, which would render as a bound "?" and
//     diverge from the golden text.
//   - Several sites here run on a *gorm.DB transaction (tx := db.Begin() or
//     db.Transaction(func(tx *gorm.DB) error {...})) in production rather than
//     on the plain connection. The dry-run build function takes whatever
//     *gorm.DB it is handed and renders identically either way - the same
//     reasoning orm_wave2_pilot_test.go's handleMerge note gives - so proving
//     them here via the plain tx parameter is safe.
//   - Duplicate-golden site groups (gate (h): converting one renumbers the
//     other's occurrence index) are converted and tested together; each still
//     gets its own test bearing its own site ID, since Gate 2 requires a
//     passing test named after each site.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- bulkItem.go ------------------------------------------------------------

func TestWave2Msg2_2403316ebe7c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2403316ebe7c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Where("bulkitemid = ? AND userid = ?", 1, 2).
			Updates(map[string]interface{}{"state": gorm.Expr("'Withdrawn'"), "quantity": gorm.Expr("0")})
	})
}

func TestWave2Msg2_35ec3910a568(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "35ec3910a568", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"message": "body", "date": "2026-01-01"})
	})
}

// 1fec19922ea6 and 54b1921a63a5 are the same statement at two call sites
// (handleBulkInterest and sendAccessInstructions), so they are converted and
// proven together per gate (h).
func TestWave2Msg2_1fec19922ea6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1fec19922ea6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "type": "Interested",
			"refmsgid": 3, "date": "2026-01-01", "message": "body",
			"processingrequired": gorm.Expr("1"),
		})
	})
}

func TestWave2Msg2_54b1921a63a5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "54b1921a63a5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "type": "Default",
			"refmsgid": 3, "date": "2026-01-01", "message": "body",
			"processingrequired": gorm.Expr("1"),
		})
	})
}

func TestWave2Msg2_b26bbbd1f279(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b26bbbd1f279", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Where("id = ? AND msgid = ?", 1, 2).
			Updates(map[string]interface{}{
				"position": 0, "name": "n", "quantity": 1, "condition": "New",
				"dimensions": "d", "photourl": "p", "description": "desc",
			})
	})
}

// --- helper.go ---------------------------------------------------------------

func TestWave2Msg2_257f269f3d36(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "257f269f3d36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Where("id = ?", 1).Update("lastpolledat", gorm.Expr("NOW()"))
	})
}

func TestWave2Msg2_da66ba09e8cf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "da66ba09e8cf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Where("id = ?", 1).Update("briefing", "b")
	})
}

func TestWave2Msg2_e7cb0b1990d3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e7cb0b1990d3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Where("id = ?", 1).
			Updates(map[string]interface{}{"status": "paused", "pausedat": gorm.Expr("NOW()")})
	})
}

func TestWave2Msg2_fb65a4932547(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fb65a4932547", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Where("id = ?", 1).
			Updates(map[string]interface{}{"status": "active", "pausedat": gorm.Expr("NULL")})
	})
}

func TestWave2Msg2_b7320249a46d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b7320249a46d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Where("id = ?", 1).Update("automode", "approve")
	})
}

func TestWave2Msg2_1f1db38c17ab(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1f1db38c17ab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Where("id = ?", 1).Update("latestmessage", gorm.Expr("NOW()"))
	})
}

func TestWave2Msg2_80634b30b8a9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "80634b30b8a9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").Where("id = ?", 1).Update("chatid", 2)
	})
}

func TestWave2Msg2_0a2d7de8a9b7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0a2d7de8a9b7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_proposals").Where("id = ?", 1).
			Updates(map[string]interface{}{"status": gorm.Expr("'dismissed'"), "resolvedat": gorm.Expr("NOW()"), "resolvedby": 2})
	})
}

func TestWave2Msg2_a03b9302bbb8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a03b9302bbb8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Where("bulkitemid = ? AND userid = ?", 1, 2).
			Update("state", gorm.Expr("'Reserved'"))
	})
}

func TestWave2Msg2_362515fa583b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "362515fa583b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", 1, 2).
			Update("state", gorm.Expr("'ALLOCATED'"))
	})
}

func TestWave2Msg2_100ebe4c33c8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "100ebe4c33c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").
			Where("id = ? AND state IN ('QUALIFIED','GATHERING','NEW','ESCALATED','PARKED_REPLIED','PARKED_QUIET','TIMED_OUT')", 1).
			Update("state", gorm.Expr("'ALLOCATED'"))
	})
}

// 17c08ee0c835 and 15b15ae11812 are the same statement in the "rejection" and
// "withdrawal_notice" proposal-type cases, converted together per gate (h).
func TestWave2Msg2_17c08ee0c835(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "17c08ee0c835", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", 1, 2).
			Update("state", gorm.Expr("'REJECTED'"))
	})
}

func TestWave2Msg2_15b15ae11812(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "15b15ae11812", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_item_states").Where("replierid = ? AND bulkitemid = ?", 1, 2).
			Update("state", gorm.Expr("'REJECTED'"))
	})
}

func TestWave2Msg2_e2d465d97cf9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e2d465d97cf9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Where("bulkitemid = ? AND userid = ?", 1, 2).
			Update("state", gorm.Expr("'Rejected'"))
	})
}

func TestWave2Msg2_a416584a1903(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a416584a1903", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").Where("id = ?", 1).
			Updates(map[string]interface{}{"state": gorm.Expr("'ESCALATED'"), "escalation_reason": "r"})
	})
}

func TestWave2Msg2_78e5a64166c8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "78e5a64166c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_proposals").Where("id = ?", 1).
			Updates(map[string]interface{}{"status": gorm.Expr("'sent'"), "resolved_text": "t", "resolvedat": gorm.Expr("NOW()"), "resolvedby": 2})
	})
}

// --- message.go: Search -------------------------------------------------

func TestWave2Msg2_6d0aed05d5eb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6d0aed05d5eb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("search_history").Create(map[string]interface{}{
			"userid": 1, "term": "t", "locationid": nil, "groups": "1,2",
		})
	})
}

func TestWave2Msg2_160920e559dd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "160920e559dd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_searches").Create(map[string]interface{}{
			"userid": 1, "term": "t", "locationid": nil,
		})
	})
}

func TestWave2Msg2_e0c033ce3d69(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e0c033ce3d69", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("search_history").Create(map[string]interface{}{
			"userid": gorm.Expr("NULL"), "term": "t", "locationid": nil, "groups": "1,2",
		})
	})
}

// --- message.go: logModAction / logMessageReceived ------------------------

func TestWave2Msg2_420288c252f9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "420288c252f9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": "Message", "subtype": "Approved", "groupid": 1,
			"user": 2, "byuser": 3, "msgid": 4, "stdmsgid": 5, "text": "t",
		})
	})
}

func TestWave2Msg2_7e5d8a92a1e9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e5d8a92a1e9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": "Message", "subtype": "Approved", "groupid": 1,
			"user": 2, "byuser": 3, "msgid": 4, "text": "t",
		})
	})
}

func TestWave2Msg2_72d2bdb608e5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "72d2bdb608e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": "Message", "subtype": "Received",
			"groupid": 1, "user": 2, "byuser": gorm.Expr("NULL"), "msgid": 3, "text": "mid",
		})
	})
}

// --- message.go: invalidateMessageSearchIndexes ---------------------------

func TestWave2Msg2_345156507896(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "345156507896", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_index").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_99835b243d85(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "99835b243d85", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_embeddings").Where("msgid = ?", 1).Delete(nil)
	})
}

// --- message.go: handleApprove ---------------------------------------------

func TestWave2Msg2_3eab7820f52c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3eab7820f52c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid = ? AND groupid IN ? AND collection != ?", 1, []uint64{2, 3}, "Approved").
			Updates(map[string]interface{}{
				"collection": "Approved", "approvedby": 4,
				"approvedat": gorm.Expr("NOW()"), "arrival": gorm.Expr("NOW()"),
			})
	})
}

// 6180dc848f02 and cc381d7c669b (handleRelease) are the same statement,
// converted together per gate (h).
func TestWave2Msg2_6180dc848f02(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6180dc848f02", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).
			Update("heldby", gorm.Expr("NULL"))
	})
}

// b25ea3ba4ade is identical to 02b3821ea3b9, 7603ee833330 and e1f780721381
// (four call sites of the same background_tasks/email_message_* insert
// shape); each is converted and tested independently, since gate (h) only
// requires groups of literally-identical SQL to be converted TOGETHER, not
// merged into one test.
func TestWave2Msg2_b25ea3ba4ade(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b25ea3ba4ade", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_approved",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "s", "b", 4, "Approve"),
		})
	})
}

// --- message.go: handleReject -----------------------------------------------

func TestWave2Msg2_e6c4a74e1ea8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e6c4a74e1ea8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid = ? AND groupid IN ? AND collection = ?", 1, []uint64{2, 3}, "Pending").
			Updates(map[string]interface{}{"collection": "Rejected", "rejectedat": gorm.Expr("NOW()"), "heldby": gorm.Expr("NULL")})
	})
}

func TestWave2Msg2_084f87f8787b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "084f87f8787b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid = ? AND groupid IN ? AND collection = ?", 1, []uint64{2, 3}, "Pending").
			Updates(map[string]interface{}{"deleted": gorm.Expr("1"), "heldby": gorm.Expr("NULL")})
	})
}

// 522c1e7c91cf is identical to ef364ece98ef (handleDeleteMessage) and
// 22ed790e0691 (handleOutcome); converted and tested together per gate (h).
func TestWave2Msg2_522c1e7c91cf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "522c1e7c91cf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})
	})
}

func TestWave2Msg2_02b3821ea3b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "02b3821ea3b9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_rejected",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "s", "b", 4, "Reject"),
		})
	})
}

// --- message.go: ClipReachForRejectedGroup ---------------------------------

func TestWave2Msg2_99b2c17a8727(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "99b2c17a8727", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reach").
			Where("msgid = ? AND (rejected_groups IS NULL OR JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) = 0)", 1, 2).
			Update("rejected_groups", gorm.Expr("JSON_ARRAY_APPEND(COALESCE(rejected_groups, JSON_ARRAY()), '$', ?)", 2))
	})
}

// --- message.go: handleDeleteMessage ----------------------------------------

// 3a50dbee0fa0 is identical to f90b6df0a3bb (handleRejectToDraft), converted
// together per gate (h).
func TestWave2Msg2_3a50dbee0fa0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3a50dbee0fa0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).Delete(nil)
	})
}

func TestWave2Msg2_ef364ece98ef(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ef364ece98ef", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})
	})
}

func TestWave2Msg2_7603ee833330(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7603ee833330", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_rejected",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "s", "b", 4, "Delete Approved Message"),
		})
	})
}

// --- message.go: handleSpam -------------------------------------------------

func TestWave2Msg2_c6e83a7877cb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c6e83a7877cb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).
			Update("deleted", gorm.Expr("1"))
	})
}

// 73672934d660 is identical to 499057e391e9 (DeleteMessageEndpoint),
// converted together per gate (h).
func TestWave2Msg2_73672934d660(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "73672934d660", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

// --- message.go: handleHold / handleBackToPending ---------------------------

// 8c1766162f86 is identical to 1a12de474647 (handleBackToPending), converted
// together per gate (h).
func TestWave2Msg2_8c1766162f86(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8c1766162f86", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).
			Update("heldby", 4)
	})
}

func TestWave2Msg2_1a12de474647(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1a12de474647", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).
			Update("heldby", 4)
	})
}

func TestWave2Msg2_6fda96dc660b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6fda96dc660b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ?", 1, "Approved").
			Updates(map[string]interface{}{"approvedby": gorm.Expr("NULL"), "approvedat": gorm.Expr("NULL")})
	})
}

// --- message.go: handleRelease -----------------------------------------------

func TestWave2Msg2_cc381d7c669b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cc381d7c669b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).
			Update("heldby", gorm.Expr("NULL"))
	})
}

// --- message.go: handleApproveEdits / handleRevertEdits ---------------------

// 06b3d2e46af9 is identical to 83ab41e7c9ac (handleRevertEdits), converted
// together per gate (h).
func TestWave2Msg2_06b3d2e46af9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "06b3d2e46af9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("editedby", gorm.Expr("NULL"))
	})
}

func TestWave2Msg2_22dae99e96dc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "22dae99e96dc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("subject", "s")
	})
}

func TestWave2Msg2_d1a1d099f7b1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d1a1d099f7b1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("textbody", "t")
	})
}

func TestWave2Msg2_cc3eec4538f6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cc3eec4538f6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").
			Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "approvedat": gorm.Expr("NOW()")})
	})
}

func TestWave2Msg2_83ab41e7c9ac(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "83ab41e7c9ac", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("editedby", gorm.Expr("NULL"))
	})
}

func TestWave2Msg2_332e96fb2185(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "332e96fb2185", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").
			Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", 1).
			Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "revertedat": gorm.Expr("NOW()")})
	})
}

// --- message.go: handleReply -------------------------------------------------

func TestWave2Msg2_e1f780721381(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e1f780721381", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_reply",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				1, 2, 3, "s", "b", 4, "Leave Approved Message"),
		})
	})
}

// --- message.go: handleRejectToDraft (runs on tx) ---------------------------

func TestWave2Msg2_f90b6df0a3bb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f90b6df0a3bb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", 1, []uint64{2, 3}).Delete(nil)
	})
}

// 854c7e93efe3 is identical to dc8914d8b9d5 (JoinAndPostAs) and a08c7f4426c7
// (handleOutcome), converted together per gate (h).
func TestWave2Msg2_854c7e93efe3(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "854c7e93efe3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_0486830f6eda(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0486830f6eda", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes_intended").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_c306c6bbc740(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c306c6bbc740", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Update("availablenow", gorm.Expr("availableinitially"))
	})
}

func TestWave2Msg2_a853f24ea5b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a853f24ea5b9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_2f4fa5385a74(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f4fa5385a74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("deadline", gorm.Expr("NULL"))
	})
}

// --- message.go: JoinAndPostAs -----------------------------------------------

func TestWave2Msg2_1b2b2a30455e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1b2b2a30455e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": "Group", "subtype": "Joined",
			"groupid": 1, "user": 2, "byuser": 2,
		})
	})
}

// a218fb801dd5 is identical to 2f30762bf955 (applyPatchMessageCore) and
// b53892a17f40 (PutMessageAs), converted together per gate (h).
func TestWave2Msg2_a218fb801dd5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a218fb801dd5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"subject": "s", "suggestedsubject": "s"})
	})
}

func TestWave2Msg2_8c57c53511ec(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8c57c53511ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("deadline", "2026-01-01")
	})
}

func TestWave2Msg2_4754a3558c44(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4754a3558c44", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("deliverypossible", 1)
	})
}

// dc8914d8b9d5 is identical to 854c7e93efe3 (handleRejectToDraft) and
// a08c7f4426c7 (handleOutcome), converted together per gate (h).
func TestWave2Msg2_dc8914d8b9d5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dc8914d8b9d5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_ce1d968cff70(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ce1d968cff70", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes_intended").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_c95c096df653(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c95c096df653", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_postings").Create(map[string]interface{}{"msgid": 1, "groupid": 2})
	})
}

func TestWave2Msg2_d12584380a19(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d12584380a19", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("fromaddr", "a@b.c")
	})
}

func TestWave2Msg2_2aaca5a913de(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2aaca5a913de", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_drafts").Where("msgid = ?", 1).Delete(nil)
	})
}

// --- message.go: applyPatchMessageCore ---------------------------------------

func TestWave2Msg2_69685398ad05(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "69685398ad05", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ?", 1).Update("msgtype", "Offer")
	})
}

func TestWave2Msg2_18deff8279ec(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "18deff8279ec", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_drafts").Where("msgid = ?", 1).Update("groupid", 2)
	})
}

func TestWave2Msg2_3368171d089d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3368171d089d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("msgid = ? AND outcome = 'Expired'", 1).Delete(nil)
	})
}

func TestWave2Msg2_e9e614befbc7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e9e614befbc7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_items").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_d9ee371b9e9a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d9ee371b9e9a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_items").Create(map[string]interface{}{"msgid": 1, "itemid": 2})
	})
}

func TestWave2Msg2_2f30762bf955(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2f30762bf955", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"subject": "s", "suggestedsubject": "s"})
	})
}

func TestWave2Msg2_33a4f1de7366(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "33a4f1de7366", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ?", 1, "Rejected").
			Update("collection", "Pending")
	})
}

func TestWave2Msg2_b3e27bdd694b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b3e27bdd694b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("id = ?", 1).
			Updates(map[string]interface{}{"msgid": 2, "primary": true})
	})
}

func TestWave2Msg2_f30172c24e2d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f30172c24e2d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("msgid = ? AND id NOT IN (?)", 1, []uint64{2, 3}).Delete(nil)
	})
}

// 8ef16859487a is identical to cebe07bfb873 (PatchMessageByTN), converted
// together per gate (h).
func TestWave2Msg2_8ef16859487a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8ef16859487a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_0f9c08165d29(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0f9c08165d29", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ?", 1).
			Updates(map[string]interface{}{"contentcheck_checked_at": gorm.Expr("NULL"), "contentcheck_reasons": gorm.Expr("NULL")})
	})
}

func TestWave2Msg2_b2e32c804c19(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b2e32c804c19", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_edits").Create(map[string]interface{}{
			"msgid": 1, "byuser": 2, "oldsubject": "os", "newsubject": "ns",
			"oldtype": "ot", "newtype": "nt", "oldtext": "otx", "newtext": "ntx",
			"olditems": "[1]", "newitems": "[2]", "oldimages": "[3]", "newimages": "[4]",
			"oldlocation": 5, "newlocation": 6, "reviewrequired": 0,
		})
	})
}

func TestWave2Msg2_f935f69bc2ce(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f935f69bc2ce", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("editedby", 2)
	})
}

// 01bedb9d631d is identical to 9d1cfd7098bc (PutMessageAs), converted
// together per gate (h).
func TestWave2Msg2_01bedb9d631d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "01bedb9d631d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"availableinitially": 2, "availablenow": 2})
	})
}

// b560d268dc4e is identical to 9beaa0265ff1 (PutMessageAs), converted
// together per gate (h).
func TestWave2Msg2_b560d268dc4e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b560d268dc4e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"textbody": "t", "message": "t"})
	})
}

// --- message.go: PatchMessageByTN --------------------------------------------

func TestWave2Msg2_cebe07bfb873(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cebe07bfb873", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("msgid = ?", 1).Delete(nil)
	})
}

// --- message.go: DeleteMessageEndpoint ---------------------------------------

func TestWave2Msg2_499057e391e9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "499057e391e9", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).Update("deleted", gorm.Expr("NOW()"))
	})
}

// --- message.go: PutMessageAs -------------------------------------------------

func TestWave2Msg2_c2d76084bb4c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c2d76084bb4c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_drafts").Create(map[string]interface{}{
			"msgid": 1, "groupid": 2, "userid": 3,
		})
	})
}

func TestWave2Msg2_7bed34d0e1c8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7bed34d0e1c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Create(map[string]interface{}{
			"msgid": 1, "groupid": 2, "collection": "Pending", "arrival": gorm.Expr("NOW()"),
		})
	})
}

func TestWave2Msg2_463ec6508c13(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "463ec6508c13", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Where("id = ?", 1).Update("msgid", 2)
	})
}

func TestWave2Msg2_9d1cfd7098bc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9d1cfd7098bc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"availableinitially": 2, "availablenow": 2})
	})
}

func TestWave2Msg2_9beaa0265ff1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9beaa0265ff1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"textbody": "t", "message": "t"})
	})
}

func TestWave2Msg2_028c42d610f7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "028c42d610f7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("lastlocation", 2)
	})
}

func TestWave2Msg2_b53892a17f40(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b53892a17f40", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"subject": "s", "suggestedsubject": "s"})
	})
}

// --- message.go: handleRenege -------------------------------------------------

func TestWave2Msg2_2dc99e82e230(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2dc99e82e230", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_reneged").Create(map[string]interface{}{"userid": 1, "msgid": 2})
	})
}

func TestWave2Msg2_547c9cac0b8d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "547c9cac0b8d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_promises").Where("msgid = ? AND userid = ?", 1, 2).Delete(nil)
	})
}

// --- message.go: handleOutcome ------------------------------------------------

func TestWave2Msg2_a58569a3101e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a58569a3101e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ? AND collection = ?", 1, "Pending").
			Update("deleted", gorm.Expr("1"))
	})
}

// 22ed790e0691 is identical to 522c1e7c91cf (handleReject) and ef364ece98ef
// (handleDeleteMessage), converted together per gate (h).
func TestWave2Msg2_22ed790e0691(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "22ed790e0691", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})
	})
}

// 4064113639bf is identical to 0486830f6eda (handleRejectToDraft) and
// ce1d968cff70 (JoinAndPostAs), converted together per gate (h).
func TestWave2Msg2_4064113639bf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4064113639bf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes_intended").Where("msgid = ?", 1).Delete(nil)
	})
}

// a08c7f4426c7 is identical to 854c7e93efe3 (handleRejectToDraft) and
// dc8914d8b9d5 (JoinAndPostAs), converted together per gate (h).
func TestWave2Msg2_a08c7f4426c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a08c7f4426c7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Where("msgid = ?", 1).Delete(nil)
	})
}

func TestWave2Msg2_4ee5c6f34496(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4ee5c6f34496", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Create(map[string]interface{}{
			"msgid": 1, "outcome": "Taken", "happiness": "5", "comments": "c",
		})
	})
}

func TestWave2Msg2_977f9505dd10(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "977f9505dd10", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_outcomes").Create(map[string]interface{}{
			"msgid": 1, "outcome": "Taken", "comments": "c",
		})
	})
}

func TestWave2Msg2_6cb10f0daf5f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6cb10f0daf5f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Create(map[string]interface{}{
			"msgid": 1, "userid": 2, "count": 3,
		})
	})
}

func TestWave2Msg2_ec4948877cc2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ec4948877cc2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spatial").Where("msgid = ?", 1).Update("successful", gorm.Expr("1"))
	})
}

func TestWave2Msg2_58c40f7a8589(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "58c40f7a8589", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").
			Where("msgid = ? AND collection = ? AND deleted = 0", 1, "Pending").
			Update("deleted", gorm.Expr("1"))
	})
}

func TestWave2Msg2_78f1364c0347(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "78f1364c0347", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "message_outcome",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'outcome', ?, 'happiness', ?, 'comment', ?, 'userid', ?, 'byuser', ?, 'message', ?)",
				1, "Taken", "5", "c", 2, 3, "m"),
		})
	})
}

// --- message.go: handleAddBy / handleRemoveBy --------------------------------

// 98534528cf3e is identical to 228b6b678e0c (handleRemoveBy), converted
// together per gate (h).
func TestWave2Msg2_98534528cf3e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "98534528cf3e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Update("availablenow", gorm.Expr("LEAST(availableinitially, availablenow + ?)", 2))
	})
}

func TestWave2Msg2_637dfbef1ef4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "637dfbef1ef4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Where("id = ?", 1).Update("count", 2)
	})
}

func TestWave2Msg2_abfaa5681f50(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "abfaa5681f50", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Create(map[string]interface{}{"userid": 1, "msgid": 2, "count": 3})
	})
}

func TestWave2Msg2_bc1759cfd933(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bc1759cfd933", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Update("availablenow", gorm.Expr("GREATEST(LEAST(availableinitially, availablenow - ?), 0)", 2))
	})
}

func TestWave2Msg2_228b6b678e0c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "228b6b678e0c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("id = ?", 1).
			Update("availablenow", gorm.Expr("LEAST(availableinitially, availablenow + ?)", 2))
	})
}

func TestWave2Msg2_8df7fad1ee3d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8df7fad1ee3d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_by").Where("id = ?", 1).Delete(nil)
	})
}

// --- message.go: handleView ---------------------------------------------------

func TestWave2Msg2_4ad419f5c797(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4ad419f5c797", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_likes").Where("msgid = ? AND userid = ? AND type = 'View'", 1, 2).
			Updates(map[string]interface{}{
				"pageview": gorm.Expr("1"),
				"source":   gorm.Expr("COALESCE(?, source)", "web"),
			})
	})
}

// --- message.go: createSystemChatMessage -------------------------------------

func TestWave2Msg2_65bff989abbe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "65bff989abbe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Create(map[string]interface{}{
			"chatid": 1, "userid": 2, "type": "Reneged", "refmsgid": 3,
			"date": "2026-01-01", "message": gorm.Expr("''"), "processingrequired": gorm.Expr("1"),
		})
	})
}

// --- message.go: handleMove (runs on tx) --------------------------------------

func TestWave2Msg2_dbd744cc0e15(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dbd744cc0e15", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ?", 1).Delete(nil)
	})
}
