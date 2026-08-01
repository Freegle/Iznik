package test

// Wave 1 of the raw-SQL-to-ORM migration (plan section 7.3+, database-
// migration-evaluation-2026-07.md section 7), the chat module's batch:
// iznik-server-go/chat/chatmessage.go and iznik-server-go/chat/chatroom.go.
//
// Each test names its site ID. The extractor only counts a site converted
// once a parity test bearing its ID exists and passes - see
// ormharness.AssertGoldenSQL's doc comment (golden.go) and plan 7.2's Gate 2.
//
// One site from this module's wave-1 inventory is deliberately NOT converted
// and has no test here: 65fde41159df (chatroom.go, GetOrCreateUser2ModChat).
// Its SELECT ... FOR UPDATE runs on a raw *sql.Tx obtained via db.DB().Begin()
// (not a *gorm.DB) so it can share a transaction with a following raw INSERT
// that needs sql.Result.LastInsertId() - the same reason plan section 7's
// keep-raw list cites for tryst.CreateTryst. Porting just the SELECT to GORM
// would mean either running it outside the lock's transaction (defeating the
// lock) or restructuring the whole function onto db.Transaction(), which
// contorts far more production code than one call site's conversion
// warrants. Left as raw SQL; reported to the parent session instead of
// forced.
//
// chatmessage.go and chatroom.go also contain dynamic query builders that
// pass a prebuilt string variable to db.Raw (e.g. ListForUserMT, the review
// queue UNION, and the ModTools search/filter queries) - those are marked
// dynamic/wave 5 in the manifest and are untouched here.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- GetChatMessages / PostChatMessage: chat_rooms lookups ------------------

func TestWave1Chat_3ae5a9640854(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3ae5a9640854", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("user1, user2, COALESCE(groupid, 0) AS groupid").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_3194998a3535(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "3194998a3535", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("user1, user2, COALESCE(groupid, 0) AS groupid").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_79c2ce2c99fc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "79c2ce2c99fc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("chattype").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_649b3df011ed(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "649b3df011ed", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Select("groupid").
			Where("msgid = ?", 1).
			Order("groupid").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1Chat_d825142662c8(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d825142662c8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("chattype, COALESCE(groupid, 0) AS groupid").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- CreateChatMessageLoveJunk: ban check and chat lookup -------------------

func TestWave1Chat_e32ed86d4150(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e32ed86d4150", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_banned").Select("userid").
			Where("userid = ? AND groupid = ?", 1, 1).
			Find(&dest)
	})
}

func TestWave1Chat_8137d3d33011(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8137d3d33011", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").
			Where("chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))",
				"User2User", 1, 2, 2, 1).
			Find(&dest)
	})
}

// --- PatchChatMessage / DeleteChatMessage: ownership checks -----------------

func TestWave1Chat_7b5ea85896db(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "7b5ea85896db", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("userid").
			Where("id = ? AND chatid = ?", 1, 1).
			Find(&dest)
	})
}

func TestWave1Chat_60bd6b1b9bb3(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "60bd6b1b9bb3", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("userid").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- PostChatMessageModeration / canSeeChatRoom: moderator checks ----------

func TestWave1Chat_581be541e60b(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "581be541e60b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").
			Count(&dest)
	})
}

func TestWave1Chat_3ee1550efb1a(t *testing.T) {
	var dest int64
	ormharness.AssertGoldenSQL(t, "3ee1550efb1a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").
			Where("userid = ? AND groupid = ? AND role IN (?, ?)", 1, 1, "Moderator", "Owner").
			Count(&dest)
	})
}

// --- getChatMessagesForRoom / getReviewQueue: review-queue room lookups ----

func TestWave1Chat_094f8f3b0e41(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "094f8f3b0e41", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, user1, user2, COALESCE(groupid, 0) AS groupid, chattype").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_9617dfaa6475(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9617dfaa6475", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("groupid").
			Where("userid = ? AND role IN (?, ?)", 1, "Moderator", "Owner").
			Find(&dest)
	})
}

// --- autoApproveModmails / updateMessageCounts: moderation bookkeeping -----

func TestWave1Chat_6f05a9b2bdbb(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "6f05a9b2bdbb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("id").
			Where("chatid = ? AND id > ? AND reviewrequired = 1 AND type = 'ModMail'", 1, 1).
			Find(&dest)
	})
}

func TestWave1Chat_66c473c2545e(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "66c473c2545e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Select("CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END AS valid, COUNT(*) AS count").
			Where("chatid = ?", 1).
			Group("CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END").
			Find(&dest)
	})
}

func TestWave1Chat_e74cc22cc352(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e74cc22cc352", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("chattype").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- rejectChatMessage: duplicate-flood detection ---------------------------

func TestWave1Chat_5e7534201c7c(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "5e7534201c7c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("id, chatid").
			Where("date >= ? AND reviewrequired = 1 AND message = ? AND id != ?", "2026-01-01 00:00:00", "test", 1).
			Find(&dest)
	})
}

// --- holdChatMessage: hold-conflict checks -----------------------------------

func TestWave1Chat_f93f2b62ae60(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f93f2b62ae60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("reviewrequired").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_776feb15b77b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "776feb15b77b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages_held").Select("userid").
			Where("msgid = ?", 1).
			Find(&dest)
	})
}

// --- enrichReviewReason: spam keyword / link whitelist checks ---------------

func TestWave1Chat_839103c648fe(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "839103c648fe", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("concern_keywords").
			Select("keyword AS word, match_mode AS type, action, exclude").
			Where("match_mode IN ('literal', 'regex') AND action IN ('block', 'flag') AND scope = 'global' AND category != 'allowed' AND LENGTH(TRIM(keyword)) > 0").
			Find(&dest)
	})
}

func TestWave1Chat_1bd8efb9de20(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "1bd8efb9de20", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_whitelist_links").
			Select("domain").
			Where("count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%'").
			Find(&dest)
	})
}

// --- GetChatRoom: fallback room lookup for a moderator ----------------------

func TestWave1Chat_52dd73c2cd60(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "52dd73c2cd60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, user1, user2, COALESCE(groupid, 0) AS groupid, chattype").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- PutChatRoom: find-or-create User2Mod and User2User chats --------------

func TestWave1Chat_753fbc262f6b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "753fbc262f6b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("user1 = ? AND chattype = ? AND groupid = ?", 1, "User2Mod", 1).
			Limit(1).
			Find(&dest)
	})
}

func TestWave1Chat_0cc375ba3154(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0cc375ba3154", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("userid").
			Where("groupid = ? AND role IN (?, ?) AND collection = ?", 1, "Owner", "Moderator", "Approved").
			Find(&dest)
	})
}

func TestWave1Chat_8b3ccb082a00(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8b3ccb082a00", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)) AND chattype = ?", 1, 2, 2, 1, "User2User").
			Limit(1).
			Find(&dest)
	})
}

// --- GetOrCreateUser2ModChat: roster seeding --------------------------------
//
// The SELECT ... FOR UPDATE in this same function (site 65fde41159df) is
// deliberately left raw - see the file header comment.

func TestWave1Chat_8df448e1a244(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "8df448e1a244", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Select("userid").
			Where("groupid = ? AND role IN (?, ?)", 1, "Owner", "Moderator").
			Find(&dest)
	})
}

// --- GetOrCreateUser2UserChat: existing-chat lookup -------------------------

func TestWave1Chat_f114a9d3efaf(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f114a9d3efaf", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)) AND chattype = ?", 1, 2, 2, 1, "User2User").
			Limit(1).
			Find(&dest)
	})
}

// --- GetCommonGroups: participant check -------------------------------------

func TestWave1Chat_0ca3c490a3f4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "0ca3c490a3f4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, user1, user2").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- handleNudge: room/last-message checks and dedupe -----------------------

func TestWave1Chat_60cc02526265(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "60cc02526265", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_f467930b5852(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "f467930b5852", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("type, userid").
			Where("chatid = ?", 1).
			Order("id DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestWave1Chat_d6af2e7ded90(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "d6af2e7ded90", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Select("id").
			Where("chatid = ? AND type = ? AND userid = ?", 1, "Nudge", 1).
			Order("id DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- handleTyping: room existence check -------------------------------------

func TestWave1Chat_9cbfea9abfb4(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "9cbfea9abfb4", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- handleRosterUpdate: permission check and roster fetch ------------------

func TestWave1Chat_e9c62ddd84f5(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e9c62ddd84f5", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_727229e201ff(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "727229e201ff", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").Select("userid, status").
			Where("chatid = ?", 1).
			Find(&dest)
	})
}

// --- handleReferToSupport / handleReportNoGroup: membership checks ---------

func TestWave1Chat_e6897171f8cc(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e6897171f8cc", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2, groupid").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_ef385ae58c6f(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ef385ae58c6f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms").Select("id, chattype, user1, user2").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- getChatName: display-name lookups for User2Mod, Mod2Mod and default ---

func TestWave1Chat_ad4461c2eb9b(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "ad4461c2eb9b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("COALESCE(namefull, nameshort)").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_e531967a2538(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "e531967a2538", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_c033fc5fdc76(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "c033fc5fdc76", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("COALESCE(namefull, nameshort)").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_aad80eae279a(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "aad80eae279a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").Select("nameshort").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestWave1Chat_843ffd0c4450(t *testing.T) {
	var dest []map[string]interface{}
	ormharness.AssertGoldenSQL(t, "843ffd0c4450", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("fullname").
			Where("id = ?", 1).
			Find(&dest)
	})
}
