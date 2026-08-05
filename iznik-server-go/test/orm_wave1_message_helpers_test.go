package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the message module's smaller
// helper files: iznik-server-go/message/bulkItem.go, helper.go, bulkEdit.go,
// message_list.go, reach.go, sitemap.go. message.go itself is a separate
// batch, converted elsewhere.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// One site from message_list.go's wave-1 inventory is deliberately NOT
// converted and has no test here: 1aa7cdd2a963 (ListMessagesMT's pagination
// cursor query, "SELECT MAX(arrival) FROM messages_groups WHERE msgid = ? AND
// groupid IN (?) AND deleted = 0"). It binds a Go []uint64 group-ID slice to a
// literal "groupid IN (?)" placeholder; GORM's dry-run substitutes the slice
// element-by-element into that same "?", so the rendered SQL carries as many
// placeholders as the slice has elements, whereas the golden text records a
// single "?" - a real, length-dependent divergence the harness has no
// approvedDiff entry for yet. Same shape already skipped in the dashboard,
// group, membership and session modules; see those modules' wave 1 test
// files. It is left as raw SQL.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- bulkEdit.go: logged-out "secret link" bulk-offer edit page ---

func TestWave1MessageBulkEdit_36a2a7be3fff(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "36a2a7be3fff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access").Select("COALESCE(edittoken, '')").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkEdit_52538d138ab2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "52538d138ab2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access").Select("COALESCE(edittoken, '')").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkEdit_ffb087326c1a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ffb087326c1a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Select("id, position, name, quantity, available, `condition`, dimensions, photourl").
			Where("msgid = ?", 1).
			Order("position ASC, id ASC").
			Find(&dest)
	})
}

func TestWave1MessageBulkEdit_99b4885acfb6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "99b4885acfb6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("availablenow").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkEdit_2765d951f216(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2765d951f216", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("subject").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkEdit_e7dc4cec288b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e7dc4cec288b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Select("msgid").Where("id = ?", 1).Find(&dest)
	})
}

// --- bulkItem.go: catalogue items, interest, chat rooms, collection slots ---

func TestWave1MessageBulkItem_0c576c0140ff(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0c576c0140ff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_access").Select("COALESCE(accessinstructions, '')").Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_6d41381ea61b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6d41381ea61b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").
			Select("id, msgid, position, name, quantity, available, `condition`, dimensions, photourl, description").
			Where("msgid = ?", 1).
			Order("position ASC, id ASC").
			Find(&dest)
	})
}

func TestWave1MessageBulkItem_4b13c81d7f07(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4b13c81d7f07", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").
			Select("id, bulkitemid, msgid, userid, quantity, cancollect, state, chatid").
			Where("msgid = ?", 1).
			Find(&dest)
	})
}

func TestWave1MessageBulkItem_644035e792fd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "644035e792fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", 1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_b169ddebf3b7(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b169ddebf3b7", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Select("name, quantity, msgid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_4c3f0662bf60(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "4c3f0662bf60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Select("id").Where("msgid = ?", 1).
			Order("position ASC, id ASC").Find(&dest)
	})
}

func TestWave1MessageBulkItem_b3c3cfed62ed(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "b3c3cfed62ed", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("id").
			Where("chatid = ? AND userid = ? AND refmsgid = ? AND type = ?", 1, 2, 3, "Interested").
			Order("id DESC").Limit(1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_14de6b4b23b0(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "14de6b4b23b0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Select("COALESCE(state, '')").
			Where("bulkitemid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageBulkItem_a1b05269552d(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "a1b05269552d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Select("COALESCE(quantity, 1)").
			Where("bulkitemid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageBulkItem_6f136c99e5de(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6f136c99e5de", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", 1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_bec9f1bec5e5(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "bec9f1bec5e5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items").Where("msgid = ?", 1).Count(&dest)
	})
}

func TestWave1MessageBulkItem_24edbdb077a3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "24edbdb077a3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))", "User2User", 1, 2, 2, 1).
			Limit(1).Find(&dest)
	})
}

func TestWave1MessageBulkItem_7fd0b7c845d2(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7fd0b7c845d2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_slots").Select("slot").Where("msgid = ?", 1).
			Order("position ASC, id ASC").Find(&dest)
	})
}

// --- helper.go: Freegle Helper (AI concierge) batches, repliers, proposals ---

func TestWave1MessageHelper_21a605961dab(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "21a605961dab", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Select("fromuser").Where("id = ? AND deleted IS NULL", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_c035008cb1fd(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c035008cb1fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Select("msgid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_aa389db67fc8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "aa389db67fc8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").
			Select("id, msgid, offereruserid, status, COALESCE(automode,'automatic') AS automode, briefing, lastpolledat, lastrunat, pausedat").
			Where("msgid = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_6b0609403867(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6b0609403867", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").
			Select("id, batchid, userid, chatid, state, collection_ok, criteria_met, transport_ok, distance_miles, "+
				"is_connector, related_to, escalation_reason, parked_reason, next_action, other_items_mentioned, "+
				"cooldown_until, offerer_last_message_at, last_processed_chatmsgid, knowledge").
			Where("batchid = ?", 1).
			Order("id ASC").
			Find(&dest)
	})
}

func TestWave1MessageHelper_2e78b7d10b8c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "2e78b7d10b8c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_proposals").
			Select("id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status, "+
				"resolved_text, resolvedat, resolvedby").
			Where("batchid = ?", 1).
			Order("(status = 'pending') DESC, id DESC").
			Find(&dest)
	})
}

func TestWave1MessageHelper_c0f8da161e8c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c0f8da161e8c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_sent_messages").
			Select("id, batchid, chatmsgid, chatid, replierid, kind, auto, proposalid").
			Where("batchid = ?", 1).
			Order("id ASC").
			Find(&dest)
	})
}

func TestWave1MessageHelper_62b930561c93(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "62b930561c93", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Select("status").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_992730fc5688(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "992730fc5688", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").Select("id").Where("batchid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

func TestWave1MessageHelper_c34cf9a8b19c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c34cf9a8b19c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_batches").Select("COALESCE(automode, 'automatic')").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_85dff277e409(t *testing.T) {
	// Production keeps Row().Scan(&priorAuto) into an int (see helper.go); the
	// parity test still uses Count, since Layer 1 only compares rendered SQL
	// text and Count is the terminal the harness accepts under dry-run.
	var dest int64
	ormharness.AssertGoldenSQL(t, "85dff277e409", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_sent_messages").
			Where("batchid = ? AND replierid = ? AND auto = 1", 1, 2).Count(&dest)
	})
}

func TestWave1MessageHelper_12e5ba92ff74(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "12e5ba92ff74", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_proposals").
			Select("id, batchid, type, replierid, bulkitemid, summary, proposed_text, payload, rationale, status").
			Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_3b97c08e324f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3b97c08e324f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("helper_repliers").Select("userid").Where("id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageHelper_128798d274f6(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "128798d274f6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_bulk_items_interest").Select("COALESCE(state,'')").
			Where("bulkitemid = ? AND userid = ?", 1, 2).Find(&dest)
	})
}

// --- message_list.go: ListMessages / ListMessagesMT per-message detail fetches ---

func TestWave1MessageList_8e02578d3e34(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8e02578d3e34", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages m").
			Select("m.id, m.subject, m.type, m.fromuser, m.arrival, m.lat, m.lng, m.availablenow, m.availableinitially, m.tnpostid").
			Where("m.id = ?", 1).Find(&dest)
	})
}

func TestWave1MessageList_74340fd8d8f1(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "74340fd8d8f1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid, collection, arrival, heldby, rippled_in").
			Where("msgid = ? AND deleted = 0", 1).Find(&dest)
	})
}

func TestWave1MessageList_005a06f7ad40(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "005a06f7ad40", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_attachments").Select("id, msgid, archived, externaluid, externalmods").
			Where("msgid = ?", 1).Order("`primary` DESC, id ASC").Limit(1).Find(&dest)
	})
}

func TestWave1MessageList_c77acb905614(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "c77acb905614", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Where("refmsgid = ? AND type = ? AND reviewrequired = 0 AND reviewrejected = 0", 1, "Interested").
			Count(&dest)
	})
}

// --- reach.go: post rippling-out progress (moderation reach map) ---

func TestWave1MessageReach_52c8694fa39b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "52c8694fa39b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").Where("msgid = ? AND deleted = 0", 1).Find(&dest)
	})
}

func TestWave1MessageReach_0be1f2f8556d(t *testing.T) {
	// Production keeps Row().Scan(&inSpatial) into an int (see reach.go); the
	// parity test still uses Count, since Layer 1 only compares rendered SQL
	// text and Count is the terminal the harness accepts under dry-run.
	var dest int64
	ormharness.AssertGoldenSQL(t, "0be1f2f8556d", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spatial").Where("msgid = ?", 1).Count(&dest)
	})
}

// --- sitemap.go: search-engine sitemap feed ---

func TestWave1MessageSitemap_fd13a45cf36f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "fd13a45cf36f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spatial").
			Select("msgid AS id, GREATEST(COALESCE(modified, arrival), arrival) AS lastmod").
			Where("successful = 0 AND msgtype IN (?, ?) AND arrival IS NOT NULL", "Offer", "Wanted").
			Order("arrival DESC").
			Find(&dest)
	})
}
