package test

// Wave 5 triage, first batch (plan section 7.3+/7.4): chat/chatmessage.go,
// message/message.go, emailtracking/emailtracking.go, user/user.go,
// chat/chatroom.go, location/location.go. Wave 5 sites arrive either
// dynamic (built from Go control flow, not a single fixed statement) or
// static but shaped in ways earlier waves didn't need to handle, so each
// site was triaged individually into convert-with-a-test or keep-raw with an
// id-pinned reason in tools/orm-migration/keep-raw.json - see that file for
// the sites NOT here.
//
// New patterns in this batch, each established by reading the GORM source
// referenced (not assumed):
//
//   - .Select(text, args...) and .Where(text, args...) both accept bind args
//     the same way (chainable_api.go Select(); confirmed by
//     TestJoin_BindArgsInJoinCondition's Where/Joins precedent). Used for
//     aggregate expressions like "COUNT(*) AS reach_rows, COALESCE(MAX(?), ...)".
//
//   - .Order(value) takes exactly ONE argument and never binds (chainable_api.go:
//     only clause.OrderBy / clause.OrderByColumn / string are handled; there is
//     no variadic-args case). An ORDER BY needing a bind - e.g.
//     ST_Distance_Sphere(POINT(...), POINT(?, ?)) - goes through
//     clause.OrderBy{Expression: gorm.Expr(sql, args...)} instead: OrderBy has
//     an Expression field (clause/order_by.go) that Order()'s first switch case
//     passes straight to AddClause, and gorm.Expr's clause.Expr implements
//     clause.Expression.
//
//   - .Table(name, args...) takes the RAW-EXPRESSION path (TableExpr, no
//     identifier quoting at all) whenever name contains a space or a backtick
//     (chainable_api.go: `strings.Contains(name, " ") || strings.Contains(name,
//     "`") || len(args) > 0`). That's what already lets "users u" survive
//     unquoted (join_test.go's TestJoin_TableAliasSurvives), and it turns out
//     to cover a parenthesised derived table too: .Table("(SELECT ...) t", id)
//     renders the subquery verbatim as the FROM source, with its own bind.
//     Confirmed by reading the exact same code path, not assumed from the
//     alias case working.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- chat/chatmessage.go: CreateChatMessage (in-reach check, legacy branch) --

func TestWave5A_f31ae2ffe181(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f31ae2ffe181", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach").
			Select("COUNT(*) AS reach_rows, COALESCE(MAX(ST_Contains(polygon, ST_SRID(POINT(?, ?), ?))), 0) AS in_reach",
				-0.1, 51.5, 3857).
			Where("msgid = ?", 1).
			Find(&dest)
	})
}

// --- chat/chatmessage.go: CreateChatMessage (reopen a closed chat) ----------

func TestWave5A_739b42667028(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "739b42667028", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Where("chatid = ? AND status = ? AND EXISTS (SELECT 1 FROM chat_rooms WHERE id = ? AND chattype IN (?, ?))",
				1, "Closed", 1, "User2User", "User2Mod").
			Update("status", "Offline")
	})
}

// --- chat/chatroom.go: handleTyping ------------------------------------------

func TestWave5A_d6518a523f9c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d6518a523f9c", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Where("chatid = ? AND TIMESTAMPDIFF(SECOND, chat_messages.date, NOW()) < 30 AND mailedtoall = 0", 1).
			Update("date", gorm.Expr("NOW()"))
	})
}

// --- chat/chatroom.go: handleRosterUpdate (seenbyall) ------------------------

func TestWave5A_36b33902c122(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "36b33902c122", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Where("chatid = ? AND id <= ? AND seenbyall = 0 AND NOT EXISTS ( "+
				"SELECT 1 FROM chat_roster "+
				"WHERE chatid = ? AND (lastmsgseen IS NULL OR lastmsgseen < chat_messages.id) "+
				"AND userid != chat_messages.userid "+
				")", 1, 2, 1).
			Update("seenbyall", gorm.Expr("1"))
	})
}

// --- chat/chatroom.go: handleRosterUpdate (unseen count) ---------------------

func TestWave5A_2984f464c081(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2984f464c081", func(tx *gorm.DB) *gorm.DB {
		var unseen int64
		return tx.Table("chat_messages").
			Where("chatid = ? AND userid != ? "+
				"AND id > COALESCE((SELECT lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ?), 0) "+
				"AND chat_messages.date >= ? "+
				"AND reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 "+
				"AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = chat_messages.id AND rhr.status <> 'released')",
				1, 2, 1, 2, "2026-01-01").
			Count(&unseen)
	})
}

// --- chat/chatroom.go: handleAllSeen (FD participant branch) -----------------

func TestWave5A_491a9ebdf3f8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "491a9ebdf3f8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Where("userid = ? AND chatid IN (SELECT id FROM chat_rooms WHERE (user1 = ? OR user2 = ?) AND chattype IN (?, ?))",
				1, 1, 1, "User2User", "User2Mod").
			Update("lastmsgseen", gorm.Expr("(SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE chatid = chat_roster.chatid)"))
	})
}

// --- location/location.go: CreateLocation (cache centroid, geometry) ---------

func TestWave5A_13cbb1ba0653(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "13cbb1ba0653", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Where("id = ?", 1).Updates(map[string]interface{}{
			"maxdimension": gorm.Expr("GetMaxDimension(geometry)"),
			"lat":          gorm.Expr("ST_Y(ST_Centroid(geometry))"),
			"lng":          gorm.Expr("ST_X(ST_Centroid(geometry))"),
		})
	})
}

// --- location/location.go: UpdateLocation (cache centroid, ourgeometry) ------

func TestWave5A_7d5f2f96661e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7d5f2f96661e", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").Where("id = ?", 1).Updates(map[string]interface{}{
			"maxdimension": gorm.Expr("GetMaxDimension(ourgeometry)"),
			"lat":          gorm.Expr("ST_Y(ST_Centroid(ourgeometry))"),
			"lng":          gorm.Expr("ST_X(ST_Centroid(ourgeometry))"),
		})
	})
}

// --- location/location.go: queueExcludeRemap ---------------------------------

func TestWave5A_5e7eab0bd83d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5e7eab0bd83d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations").
			Select("ST_AsText(COALESCE(ourgeometry, geometry))").
			Where("id = ?", 1).
			Find(&dest)
	})
}

// --- message/message.go: GetMessagesByIds (messageReply) ---------------------

func TestWave5A_826841109881(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "826841109881", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_messages").
			Select("DISTINCT chat_messages.id, refmsgid, chat_messages.date, userid, fromuser, "+
				"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname").
			Joins("INNER JOIN messages ON messages.id = chat_messages.refmsgid").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("INNER JOIN users ON users.id = chat_messages.userid").
			Where("refmsgid = ? AND chat_messages.type = ? AND (messages.fromuser != ? OR chat_messages.userid != ?) "+
				"AND reviewrequired = 0 AND reviewrejected = 0 "+
				"AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = chat_messages.id AND rhr.status <> 'released') "+
				"AND DATEDIFF(chat_messages.date, messages_groups.arrival) < ?",
				1, "Interested", 2, 2, 30).
			Group("userid").
			Find(&dest)
	})
}

// --- message/message.go: addApprovedMessageToSpatialIndex (feeder SELECT) ----

func TestWave5A_f7deed72f131(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f7deed72f131", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages").
			Select("messages.lat AS lat, messages.lng AS lng, messages.type AS msgtype, "+
				"messages_groups.groupid AS groupid, "+
				"DATE_FORMAT(messages_groups.arrival, '%Y-%m-%d %H:%i:%s') AS arrival").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Where("messages.id = ? AND messages_groups.collection = ? "+
				"AND messages_groups.deleted = 0 AND messages.deleted IS NULL "+
				"AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL "+
				"AND messages_outcomes.id IS NULL",
				1, "Approved").
			Find(&dest)
	})
}

// --- message/message.go: applyPatchMessageCore (spatial point update) --------

func TestWave5A_c5c08e284c66(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c5c08e284c66", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spatial").
			Where("msgid = ?", 1).
			Update("point", gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)", -0.1, 51.5))
	})
}

// --- message/message.go: ResolveOnBehalfPosting (nearest membership) ---------

func TestWave5A_ecaf3f90bee2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ecaf3f90bee2", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships m").
			Select("m.groupid").
			Joins("INNER JOIN `groups` g ON g.id = m.groupid").
			Where("m.userid = ? AND m.collection = ?", 1, "Approved").
			Order(clause.OrderBy{Expression: gorm.Expr("ST_Distance_Sphere(POINT(g.lng, g.lat), POINT(?, ?))", -0.1, 51.5)}).
			Limit(1).
			Find(&dest)
	})
}

// --- message/message.go: handleMove (INSERT with a scalar subquery value) ----

func TestWave5A_f1d697db5654(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f1d697db5654", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Create(map[string]interface{}{
			"msgid":      1,
			"groupid":    2,
			"collection": "Pending",
			"arrival":    gorm.Expr("NOW()"),
			"msgtype":    gorm.Expr("(SELECT type FROM messages WHERE id = ?)", 1),
		})
	})
}

// --- user/user.go: GetExpectedReplies -----------------------------------------

func TestWave5A_ba4ff665bb3c(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ba4ff665bb3c", func(tx *gorm.DB) *gorm.DB {
		var expectedReplies []uint64
		return tx.Table("users_expected").
			Select("DISTINCT(chatid)").
			Joins("INNER JOIN users ON users.id = users_expected.expectee").
			Joins("INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid").
			Where("expectee = ? AND chat_messages.date >= ? AND replyexpected = 1 AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ?",
				1, "2026-01-01", 30).
			Find(&expectedReplies)
	})
}

// --- user/user.go: GetMemberships ---------------------------------------------

func TestWave5A_b06cd1b62344(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b06cd1b62344", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships").
			Select("memberships.id, added, role, groupid, emailfrequency, eventsallowed, volunteeringallowed, ourPostingStatus, microvolunteering AS microvolunteeringallowed, nameshort, namefull, groups.type, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Joins("INNER JOIN `groups` ON groups.id = memberships.groupid").
			Where("userid = ? AND collection = ?", 1, "Approved").
			Find(&dest)
	})
}

// --- user/user.go: GetUserMessageHistory --------------------------------------

func TestWave5A_d7c9963bd974(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d7c9963bd974", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages m").
			Select("m.id, m.subject, m.type, "+
				"COALESCE("+
				"(SELECT MAX(mp.date) FROM messages_postings mp WHERE mp.msgid = m.id AND mp.groupid = mg.groupid), "+
				"m.arrival) AS arrival, "+
				"mg.groupid, mg.collection, "+
				"(SELECT outcome FROM messages_outcomes WHERE messages_outcomes.msgid = m.id ORDER BY timestamp DESC LIMIT 1) AS outcome").
			Joins("INNER JOIN messages_groups mg ON m.id = mg.msgid").
			Where("m.fromuser = ? AND mg.deleted = 0 AND mg.rippled_in = 0 AND m.deleted IS NULL AND mg.collection IN (?, ?)",
				1, "Approved", "Pending").
			Order("arrival DESC").
			Find(&dest)
	})
}

// --- user/user.go: GetUserById (supporter status) -----------------------------

func TestWave5A_286f80414bd5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "286f80414bd5", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("(CASE WHEN "+
				"((users.systemrole != ? OR "+
				"EXISTS(SELECT id FROM users_donations WHERE userid = ? AND users_donations.timestamp >= ?) OR "+
				"EXISTS(SELECT id FROM microactions WHERE userid = ? AND microactions.timestamp >= ?)) AND "+
				"(CASE WHEN JSON_EXTRACT(users.settings, '$.hidesupporter') IS NULL THEN 0 ELSE JSON_EXTRACT(users.settings, '$.hidesupporter') END) = 0) "+
				"THEN 1 ELSE 0 END) "+
				"AS supporter, "+
				"(SELECT MAX(timestamp) FROM users_donations WHERE userid = ?) AS donated, "+
				"(SELECT type FROM users_donations WHERE userid = ? ORDER BY timestamp DESC LIMIT 1) AS donatedtype",
				"User", 1, "2026-01-01", 1, "2026-01-01", 1, 1).
			Where("users.id = ?", 1).
			Find(&dest)
	})
}

// --- user/user.go: GetSearchesForUser ------------------------------------------

func TestWave5A_06d02e94fa4a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "06d02e94fa4a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("(SELECT * FROM users_searches WHERE userid = ? AND deleted = 0 ORDER BY id desc LIMIT 100) t", 1).
			Select("*").
			Group("t.term").
			Order("t.id DESC").
			Limit(10).
			Find(&dest)
	})
}

// --- user/user.go: SearchUsers --------------------------------------------------

func TestWave5A_0c9e859752d0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0c9e859752d0", func(tx *gorm.DB) *gorm.DB {
		var userIDs []uint64
		return tx.Table("("+
			"(SELECT userid FROM users_emails WHERE email LIKE ? OR canon LIKE ? OR backwards LIKE ?) "+
			"UNION "+
			"(SELECT id AS userid FROM users WHERE fullname LIKE ?) "+
			"UNION "+
			"(SELECT id AS userid FROM users WHERE yahooid LIKE ?) "+
			"UNION "+
			"(SELECT id AS userid FROM users WHERE id = ?) "+
			"UNION "+
			"(SELECT userid FROM users_logins WHERE uid LIKE ?) "+
			") t",
			"%jo%", "jo%", "oj%", "jo%", "jo%", 1, "jo%").
			Select("DISTINCT userid").
			Order("userid ASC").
			Limit(100).
			Find(&userIDs)
	})
}

// --- user/user.go: GetUserBans --------------------------------------------------

func TestWave5A_6a225df4a259(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6a225df4a259", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_banned ub").
			Select("ub.groupid, "+
				"COALESCE(g.namefull, g.nameshort) AS `group`, "+
				"ub.date, ub.byuser, "+
				"(SELECT ue.email FROM users_emails ue WHERE ue.userid = ub.byuser AND ue.preferred = 1 LIMIT 1) AS byemail").
			Joins("LEFT JOIN `groups` g ON g.id = ub.groupid").
			Where("ub.userid = ?", 1).
			Order("ub.date DESC").
			Find(&dest)
	})
}
