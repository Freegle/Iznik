package test

// Tier 4 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): sites blocked not by
// a genuine GORM limitation but by one missing piece of harness each -
// REPLACE INTO, INSERT ... SELECT, the ON DUPLICATE KEY UPDATE ...
// id = LAST_INSERT_ID(id) idiom, and the MAX_EXECUTION_TIME optimizer hint.
//
// REPLACE INTO (11 sites): clause.Insert{Modifier: "REPLACE"} alone renders
// "INSERT REPLACE INTO ...", not "REPLACE INTO ..." - the literal "INSERT" is
// written by clause.Clause.Build from c.Name, which AddClause hardcodes from
// clause.Insert.Name() regardless of Modifier. The fix is a
// db.ClauseBuilders["INSERT"] override, registered once at db-construction
// time (database.RegisterCustomClauseBuilders, wired into both
// InitDatabase() for production and ormharness's dry-run db for these
// tests) - see database/clausebuilders.go and
// ormharness/updatejoin_replace_test.go for the proof, including the
// negative case ("INSERT REPLACE INTO" from Modifier alone is not valid
// SQL).
//
// Two of the 11 (25b7b92e33fd, 6f1d6543e5c0) needed one more fix: their
// recorded goldenSql had a literal, unresolved "%d" where utils.SRID should
// be, because the extractor cannot const-fold a Go constant spliced in via
// fmt.Sprintf across packages. The manifest has been hand-corrected to
// "3857" (utils.SRID's actual value) for those two; see
// ormharness/normalise_test.go's TestNormaliseColumnOrder_ReplaceInto for
// the other REPLACE-INTO-specific harness fix this category needed
// (insertPrefixPattern previously matched only "insert"/"insert ignore", so
// GORM's alphabetical column ordering was never reconciled against these
// hand-written, non-alphabetical goldens).

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- REPLACE INTO ------------------------------------------------------

// message/helper.go: helperResolveProposal.
func TestTier4_8d359d691beb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8d359d691beb", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_promises").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "userid": 2})
	})
}

// newsfeed/newsfeed.go: Post, "Seen" case.
func TestTier4_09730933af57(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "09730933af57", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_users").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"userid": 1, "newsfeedid": 2})
	})
}

// newsfeed/newsfeed.go: Post, "Unfollow" case.
func TestTier4_1ff179ae0e2c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1ff179ae0e2c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("newsfeed_unfollow").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"userid": 1, "newsfeedid": 2})
	})
}

// team/team.go: PatchTeam, "Add" case.
func TestTier4_f3a8b6237a60(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f3a8b6237a60", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("teams_members").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"userid": 1, "teamid": 2, "description": "a description"})
	})
}

// chat/chatmessage.go: holdChatMessage.
func TestTier4_67871ee12b8f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "67871ee12b8f", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages_held").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "userid": 2})
	})
}

// message/message.go: handleApprove.
func TestTier4_db4ec8586401(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "db4ec8586401", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spamham").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "spamham": gorm.Expr("'Ham'")})
	})
}

// message/message.go: handleSpam. utils.COLLECTION_SPAM == "Spam", bound as
// an ordinary value (not a literal like handleApprove's 'Ham' above).
func TestTier4_049bc3f964cd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "049bc3f964cd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spamham").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "spamham": "Spam"})
	})
}

// message/message.go: handlePromise. Same table/columns as 8d359d691beb
// above (a different call site producing the identical statement shape).
func TestTier4_7efd04588fb2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7efd04588fb2", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_promises").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "userid": 2})
	})
}

// user/user.go: handleRate.
func TestTier4_33201edc2a80(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "33201edc2a80", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("ratings").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"rater": 1, "ratee": 2, "rating": "Up", "reason": "reason text", "text": "free text",
				"timestamp": gorm.Expr("NOW()"), "reviewrequired": false,
			})
	})
}

// location/location.go: CreateLocation. utils.SRID (3857) is spliced into
// the gorm.Expr SQL text via fmt.Sprintf, matching exactly what the original
// raw code did - see this file's header comment for why the golden's "%d"
// was hand-resolved to "3857" rather than left as GORM's own bind.
func TestTier4_25b7b92e33fd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "25b7b92e33fd", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations_spatial").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"locationid": 1,
				"geometry":   gorm.Expr("ST_GeomFromText(?, 3857)", "POLYGON((0 0,1 0,1 1,0 1,0 0))"),
			})
	})
}

// location/location.go: UpdateLocation. Same statement shape as
// 25b7b92e33fd above, a different call site.
func TestTier4_6f1d6543e5c0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6f1d6543e5c0", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations_spatial").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"locationid": 1,
				"geometry":   gorm.Expr("ST_GeomFromText(?, 3857)", "POLYGON((0 0,1 0,1 1,0 1,0 0))"),
			})
	})
}

// --- INSERT ... SELECT ---------------------------------------------------
//
// database.InsertSelect (database/clausebuilders.go) keeps each of these a
// single atomic statement - see that file's doc comment, and
// ormharness/insertselect_test.go for the mechanism proof (including the
// negative case: the same call on a *gorm.DB without
// RegisterCustomClauseBuilders renders GORM's ordinary VALUES clause with
// the placeholder column instead, not the SELECT).

// modconfig/modconfig.go: PostModConfig, copy-from-existing-config branch.
func TestTier4_6b9a23982cc9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6b9a23982cc9", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "mod_configs",
			"(name, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, "+
				"ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen, "+
				"fromname, subjreg, messageorder) "+
				"SELECT ?, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, "+
				"ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen, "+
				"fromname, subjreg, messageorder "+
				"FROM mod_configs WHERE id = ?", "new name", 5)
	})
}

// modconfig/modconfig.go: PostModConfig, copy-bulkops branch. A different
// category (INSERT-id) previously ruled this GENUINELY-RAW on the same
// clause-vocabulary reasoning a sibling category's reviewer disproved for
// the 5 other INSERT ... SELECT sites - see the adversarial review §2.
func TestTier4_e137c396bd13(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e137c396bd13", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "mod_bulkops",
			"(title, configid, `set`, criterion, runevery, action, bouncingfor) "+
				"SELECT title, ?, `set`, criterion, runevery, action, bouncingfor "+
				"FROM mod_bulkops WHERE configid = ?", 7, 5)
	})
}

// newsfeed/newsfeed.go: Post, "ConvertedToPost" case - carries the photo
// across when a ChitChat post is converted to a real post.
func TestTier4_08d12a748d01(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "08d12a748d01", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "messages_attachments",
			"(msgid, contenttype, archived, hash, externaluid, externalmods, identification, `primary`) "+
				"SELECT ?, ni.contenttype, ni.archived, ni.hash, ni.externaluid, ni.externalmods, ni.identification, 1 "+
				"FROM newsfeed n INNER JOIN newsfeed_images ni ON ni.id = n.imageid "+
				"WHERE n.id = ? AND ni.externaluid IS NOT NULL "+
				"AND NOT EXISTS (SELECT 1 FROM messages_attachments ma WHERE ma.msgid = ?)",
			42, 7, 42)
	})
}

// message/bulkEdit.go: recordBulkOutcomeIfComplete. No FROM at all - a bare
// "SELECT ?, ? WHERE NOT EXISTS (...)" - proving the mechanism does not
// assume a FROM clause is present in the caller-supplied SELECT text.
func TestTier4_85f3246d11af(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "85f3246d11af", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "messages_outcomes",
			"(msgid, outcome) "+
				"SELECT ?, ? WHERE NOT EXISTS "+
				"(SELECT 1 FROM messages_outcomes WHERE msgid = ? AND outcome IN (?, ?))",
			42, "Taken", 42, "Taken", "Received")
	})
}

// session/session.go: PatchSession, deletion-reinstatement audit log.
func TestTier4_56c15677ea5b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "56c15677ea5b", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "logs",
			"(timestamp, type, subtype, user, byuser) "+
				"SELECT NOW(), ?, ?, id, id FROM users WHERE id = ? AND deleted IS NOT NULL",
			"User", "Restored", 42)
	})
}

// chat/chatroom.go: handleAllSeen, FD branch - seeds a chat_roster row for
// every chat the user participates in that doesn't already have one.
func TestTier4_4f9d823a53a7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4f9d823a53a7", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "chat_roster",
			"(chatid, userid, lastmsgseen, date) "+
				"SELECT cr.id, ?, COALESCE((SELECT MAX(id) FROM chat_messages WHERE chatid = cr.id), 0), NOW() "+
				"FROM chat_rooms cr "+
				"WHERE (cr.user1 = ? OR cr.user2 = ?) AND cr.chattype IN (?, ?) "+
				"AND NOT EXISTS (SELECT 1 FROM chat_roster r2 WHERE r2.chatid = cr.id AND r2.userid = ?)",
			42, 42, 42, "User2User", "User2Mod", 42)
	})
}

// chat/chatroom.go: handleAllSeen, ModTools branch - the twin of
// 4f9d823a53a7 above, scoped to a moderator's chat_rooms.id list instead of
// the user1/user2 pair. Previously kept raw with an unresolved "{{expr}}" in
// its golden, because the id list was spliced into the SQL text as a
// literal string (joinIDs) rather than bound; the conversion binds
// modChatIDs directly as a []uint64 slice instead (see chat/chatroom.go),
// so "IN (?)" now expands the same way any other IN-list conversion in this
// codebase does, and the golden has a real, fixed, comparable text.
func TestTier4_1d98111ea90d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1d98111ea90d", func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "chat_roster",
			"(chatid, userid, lastmsgseen, date) "+
				"SELECT cr.id, ?, COALESCE((SELECT MAX(id) FROM chat_messages WHERE chatid = cr.id), 0), NOW() "+
				"FROM chat_rooms cr "+
				"WHERE cr.id IN (?) "+
				"AND NOT EXISTS (SELECT 1 FROM chat_roster r2 WHERE r2.chatid = cr.id AND r2.userid = ?)",
			42, []uint64{10, 20, 30}, 42)
	})
}

// --- ON DUPLICATE KEY UPDATE ... id = LAST_INSERT_ID(id) --------------------
//
// This convention was already solved before Tier 4 - see
// test/orm_insertid_test.go's TestInsertID_WithResultBeatsTheRowsAffectedZeroTrap
// - and just needed applying to these 10 sites. Clauses(gorm.WithResult())
// reads the id from the driver's raw sql.Result, which has no RowsAffected
// condition anywhere near it, unlike GORM's own "@id" map writeback (skipped
// whenever RowsAffected is 0 - guaranteed on every no-op duplicate-key hit,
// which for several of these 10 is the COMMON case, not an edge case).
// tools/orm-migration/check-lastinsertid.sh enforces the convention was
// actually used: it greps each converted site's own file for "WithResult()"
// and fails if a LAST_INSERT_ID(id) site was converted without it.

// message/helper.go: ensureBatchRow.
func TestTier4_7e3e5f4d0b36(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e3e5f4d0b36", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("helper_batches").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"msgid": 1, "offereruserid": 2, "status": gorm.Expr("'active'")})
	})
}

// message/helper.go: helperUpsertReplier.
func TestTier4_4aef5392a01f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4aef5392a01f", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("helper_repliers").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"batchid": 1, "userid": 2, "state": gorm.Expr("'NEW'")})
	})
}

// message/helper.go: helperSetItemState.
func TestTier4_dfc3d2fdb2ba(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "dfc3d2fdb2ba", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("helper_item_states").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"replierid": 1, "bulkitemid": 2, "state": gorm.Expr("'NEW'")})
	})
}

// message/helper.go: helperSendAction. chatid = VALUES(chatid) needs the
// clause.Column{Table: "excluded", ...} reference, not a plain bind - see
// ormharness/upsert_test.go's TestUpsert_ValuesReferenceUsesExcludedTable.
func TestTier4_123512413259(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "123512413259", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("helper_repliers").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "chatid"}, Value: clause.Column{Table: "excluded", Name: "chatid"}},
			},
		}).Create(map[string]interface{}{"batchid": 1, "userid": 2, "chatid": 3, "state": gorm.Expr("'NEW'")})
	})
}

// donations/giftaid.go: SetGiftAid. Every non-id field is re-asserted with
// its bound value on conflict (not VALUES(col)) plus deleted = NULL, in the
// exact order the original hand-written SQL used.
func TestTier4_258437a60f5d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "258437a60f5d", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("giftaid").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "period"}, Value: gorm.Expr("?", "2026")},
				{Column: clause.Column{Name: "fullname"}, Value: gorm.Expr("?", "A Name")},
				{Column: clause.Column{Name: "firstname"}, Value: gorm.Expr("?", "A")},
				{Column: clause.Column{Name: "lastname"}, Value: gorm.Expr("?", "Name")},
				{Column: clause.Column{Name: "homeaddress"}, Value: gorm.Expr("?", "1 Street")},
				{Column: clause.Column{Name: "deleted"}, Value: gorm.Expr("NULL")},
			},
		}).Create(map[string]interface{}{
			"userid": 1, "period": "2026", "fullname": "A Name",
			"firstname": "A", "lastname": "Name", "homeaddress": "1 Street",
		})
	})
}

// message/message.go: createSystemChatMessage. latestmessage = NOW() (a
// fixed literal), not a bound value or a VALUES() reference - distinct from
// the three chat_rooms sites below, which all bind and reference it.
func TestTier4_26c94565517d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "26c94565517d", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "user2": 2, "chattype": "User2User", "latestmessage": gorm.Expr("NOW()"),
		})
	})
}

// chat/chatroom.go: GetOrCreateUser2UserChat. latestmessage is a bound Go
// time.Time value, referenced back via VALUES(latestmessage) on conflict -
// the "?" positions differ from 26c94565517d above only because that site's
// original SQL wrote NOW() and this one wrote a bound value; both were
// converted to match their own original text exactly.
func TestTier4_3efeb225c001(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3efeb225c001", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: clause.Column{Table: "excluded", Name: "latestmessage"}},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "user2": 2, "chattype": "User2User", "latestmessage": "2026-01-01 00:00:00",
		})
	})
}

// chat/chatroom.go: PutChatRoom, User2Mod branch.
func TestTier4_65574169749a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "65574169749a", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: clause.Column{Table: "excluded", Name: "latestmessage"}},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "chattype": "User2Mod", "groupid": 5, "latestmessage": "2026-01-01 00:00:00",
		})
	})
}

// chat/chatroom.go: PutChatRoom, User2User branch. Same statement shape as
// GetOrCreateUser2UserChat (3efeb225c001) - a different call site.
func TestTier4_659e00792d43(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "659e00792d43", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: clause.Column{Table: "excluded", Name: "latestmessage"}},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "user2": 2, "chattype": "User2User", "latestmessage": "2026-01-01 00:00:00",
		})
	})
}

// message/bulkItem.go: findOrCreateUser2UserRoom. latestmessage = NOW(), the
// same fixed-literal shape as 26c94565517d above.
func TestTier4_050e3574aa6f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "050e3574aa6f", func(tx *gorm.DB) *gorm.DB {
		res := gorm.WithResult()
		return tx.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"user1": 1, "user2": 2, "chattype": "User2User", "latestmessage": gorm.Expr("NOW()"),
		})
	})
}

// --- MAX_EXECUTION_TIME optimizer hint --------------------------------------
//
// Each test asserts twice: AssertGoldenSQL proves the statement's shape, and
// AssertHintSurvivesInRawSQL (ormharness/maxexecutiontime_test.go) proves the
// hint itself survived, which AssertGoldenSQL's Canonical()-based comparison
// cannot see (Canonical strips every /* */ comment from both sides before
// comparing). See that file for the negative-case proof that the second
// assertion actually catches a dropped hint.

const maxExecutionTimeHint = "/*+ MAX_EXECUTION_TIME(10000) */"

// recommendations/stats.go: Stats, per-day impressions/clicks funnel.
func TestTier4_22036e3caf64(t *testing.T) {
	build := func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_likes").
			Select(maxExecutionTimeHint + " DATE(timestamp) d, source, COUNT(*) impressions, SUM(pageview = 1) clicks").
			Where("type = 'View' AND source IN ? AND timestamp >= ?", []string{"similar_posts", "wanted_match"}, "2026-01-01").
			Group("d, source").
			Find(&dest)
	}
	ormharness.AssertGoldenSQL(t, "22036e3caf64", build)
	ormharness.AssertHintSurvivesInRawSQL(t, build, maxExecutionTimeHint)
}

// recommendations/stats.go: Stats, per-day attributed-replies funnel. The
// JOIN lives inside .Table(...) text, not a .Joins() call - see
// recommendations/stats.go's own comment for why.
func TestTier4_eba122e9abad(t *testing.T) {
	build := func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table(`messages_likes ml
	        JOIN chat_messages cm ON cm.refmsgid = ml.msgid AND cm.userid = ml.userid
	             AND cm.type = 'Interested'
	             AND cm.date BETWEEN ml.timestamp AND ml.timestamp + INTERVAL 7 DAY`).
			Select(maxExecutionTimeHint + " DATE(ml.timestamp) d, ml.source, COUNT(DISTINCT cm.id) replies").
			Where("ml.source IN ? AND ml.pageview = 1 AND ml.timestamp >= ?", []string{"similar_posts", "wanted_match"}, "2026-01-01").
			Group("d, ml.source").
			Find(&dest)
	}
	ormharness.AssertGoldenSQL(t, "eba122e9abad", build)
	ormharness.AssertHintSurvivesInRawSQL(t, build, maxExecutionTimeHint)
}

// recommendations/stats.go: Stats, holdout cohort comparison. Two derived
// tables and their LEFT JOIN are verbatim .Table(...) text with their own
// binds passed as that call's args.
func TestTier4_c9e06d962d78(t *testing.T) {
	build := func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table(`(SELECT userid, MAX(source = ?) = 0 holdout FROM messages_likes
	              WHERE source IN (?, ?) AND timestamp >= ?
	              GROUP BY userid) u
	        LEFT JOIN (SELECT userid, COUNT(*) replies FROM chat_messages
	                   WHERE type = 'Interested' AND date >= ?
	                   GROUP BY userid) r ON r.userid = u.userid`,
			"similar_posts", "similar_posts", "similar_posts_holdout", "2026-01-01", "2026-01-01").
			Select(maxExecutionTimeHint + " u.holdout, COUNT(*) users, COALESCE(SUM(r.replies), 0) replies").
			Group("u.holdout").
			Find(&dest)
	}
	ormharness.AssertGoldenSQL(t, "c9e06d962d78", build)
	ormharness.AssertHintSurvivesInRawSQL(t, build, maxExecutionTimeHint)
}
