package test

// Parity tests for chat/chatmessage.go's getReviewQueue, sites 5da587b4234d
// (non-wider branch) and 1ff296c8656c (wider-review branch).
//
// These two sites were revisited from Batch C (task: "assess getReviewQueue
// ... and GetLogs") after a first pass left them keep-raw. That pass found
// the two blockers named in the keep-raw reason - recipientExpr being a
// fixed CASE-expression string, and the group-id list being convertible to
// a native bind - were both real but not what actually kept the site raw:
// the query is a large correlated-subquery UNION (nested EXISTS/NOT EXISTS,
// a CASE-derived recipient column reused several times, GROUP BY, two
// different ORDER BY/wrapping shapes for the wider-vs-not cases) that a
// hand-converted GORM chain could get subtly wrong with no cheap way to
// catch it. The recommendation on file was to convert it anyway, but with
// Layer 2 result-parity against fixtures rather than Layer 1 text-match
// alone, given the query's size - this file has both.
//
// The manifest's own extracted goldenSql is unresolved for both sites -
// "{{expr}}{{expr}}" (1ff296c8656c) and "{{expr}} GROUP BY cm.id ORDER BY
// cm.id ASC LIMIT ?" (5da587b4234d) - because baseQuery and widerQuery are
// built across several statements and a conditional branch, which the
// extractor cannot fold into one literal. That does NOT rule out Layer 1:
// AssertGoldenSQL accepts a divergence from goldenSql when an approved diff
// is on file for the site (tools/orm-migration/approved-diffs.json), which
// is exactly the mechanism 62a2f6fa4bdb (this same function's held-by user
// lookup, converted separately) already uses for an identical situation -
// see that entry's reason. Both sites here have an approved-diff entry
// recording the real post-conversion statement text, verified by rendering
// (RenderDryRunSQL) against the production chain and checking it under the
// same normalisation assertRenderedSQL applies
// (ormharness.NormaliseForComparison(ormharness.Canonical(...))) before
// being recorded - not guessed by hand.
//
// The group-id list conversion is a deliberate behaviour change alongside
// the ORM rewrite, not just a mechanical one - same category as 62a2f6fa4bdb
// and 032b7f1b9500 (see either entry's approved-diff reason): the four/five
// splices of "IN (<comma-joined literal ids>)" in baseQuery, and the one in
// widerQuery, become native "IN (?)" binds against groupIDs ([]uint64). The
// rendered statement text changes shape from "IN (1,2,3)" to "IN (?,?,?)" -
// the harness's placeholder-collapsing only normalises runs of BIND
// placeholders against each other, not a literal list against a bind list,
// so this is a real, visible change to the SQL text, recorded explicitly in
// both the approved-diff entries and here rather than left implicit. It is
// also strictly safer (a bind can never need escaping), though groupIDs
// here was always the calling moderator's own memberships, never external
// input, so this was not an exploitable injection in practice. originalSQL
// in the Layer 2 tests below spells out the literal-list form the
// pre-conversion code actually executed, so what Layer 2 proves is that the
// two forms return identical rows for identical inputs.

import (
	"fmt"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// sanityRow is used only by this file's fixture sanity checks below (not by
// the AssertResultParity calls themselves): a plain "id" column scan, the
// same struct-with-matching-field-name mechanism chatmessage.go's own
// heldUserInfo lookup already relies on for a Raw/Table-built query with no
// Model/Schema attached.
type sanityRow struct {
	ID uint64
}

// baseQueryLiteral reproduces baseQuery exactly as it read before this
// conversion, with groupID spliced in as a literal decimal (matching
// strings.Join(groupIDStr, ",") for a single-group moderator, which is what
// every test fixture below uses) instead of a bind. This is the pre-
// conversion production text, not a reimplementation - it is checked
// character-for-character against the real source in
// chat/chatmessage.go by the conversion's own verification (see the PR
// description), and reproduced here only because AssertResultParity needs
// the ORIGINAL statement text as a Go string it can execute directly.
func baseQueryLiteral(groupID uint64, ctxq string) string {
	inList := fmt.Sprintf("%d", groupID)
	return "SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
		"cm.refmsgid, cm.reportreason, " +
		"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
		"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
		"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
		"COALESCE(cr.groupid, 0) AS room_groupid, " +
		"0 AS widerchatreview, " +
		"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
		"cme.msgid, " +
		"COALESCE((SELECT m1.groupid FROM memberships m1 WHERE m1.userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND m1.groupid IN (" + inList + ") LIMIT 1), 0) AS groupid, " +
		"COALESCE((SELECT m2.groupid FROM memberships m2 WHERE m2.userid = cm.userid AND m2.groupid IN (" + inList + ") LIMIT 1), 0) AS groupidfrom " +
		"FROM chat_messages cm " +
		"INNER JOIN chat_rooms cr ON cr.id = cm.chatid " +
		"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
		"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
		"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
		"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
		"WHERE cm.reviewrequired = 1 AND cm.reviewrejected = 0" + ctxq +
		" AND (" +
		"  (cr.chattype = ? AND cr.groupid IN (" + inList + "))" +
		"  OR (cr.chattype = ? AND EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND groupid IN (" + inList + ")))" +
		"  OR (cr.chattype = ? AND NOT EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND EXISTS (SELECT 1 FROM memberships WHERE userid = cm.userid AND groupid IN (" + inList + ")))" +
		")"
}

// widerQueryLiteral reproduces widerQuery exactly as it read before this
// conversion, for the same single-group case as baseQueryLiteral.
func widerQueryLiteral(groupID uint64, ctxq string) string {
	inList := fmt.Sprintf("%d", groupID)
	recipientExpr := "(CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)"
	return " UNION " +
		"SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
		"cm.refmsgid, cm.reportreason, " +
		"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
		"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
		"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
		"COALESCE(cr.groupid, 0) AS room_groupid, " +
		"1 AS widerchatreview, " +
		"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
		"cme.msgid, " +
		"m1.groupid AS groupid, " +
		"COALESCE(m2.groupid, 0) AS groupidfrom " +
		"FROM chat_messages cm " +
		"INNER JOIN chat_rooms cr ON cr.id = cm.chatid AND cm.reviewrequired = 1 AND cm.reviewrejected = 0 " +
		"INNER JOIN memberships m1 ON m1.userid = " + recipientExpr + " " +
		"INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle' " +
		"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
		"LEFT JOIN memberships m2 ON m2.userid = cm.userid " +
		"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
		"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
		"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
		"WHERE JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 " +
		"AND cmh.id IS NULL " +
		"AND (cm.reportreason IS NULL OR cm.reportreason != 'User') " +
		"AND NOT EXISTS (SELECT 1 FROM memberships m_check WHERE m_check.userid = " + recipientExpr + " AND m_check.groupid IN (" + inList + "))" + ctxq
}

// baseQueryBound and widerQueryBound are the POST-conversion query text -
// "IN (?)" bound placeholders instead of a literal group-id list - matching
// chat/chatmessage.go's baseQuery/widerQuery exactly (for ctxq="", the only
// case exercised below). These are what the replacement closures below
// build against, paired with groupIDs as the bind argument; baseQueryLiteral
// and widerQueryLiteral above are for originalSQL only, and must never be
// mixed with a groupIDs bind - their group-id list is already literal text,
// so there is no "?" there for a bind to land on. (An earlier version of
// this file made exactly that mistake: it built the replacement's SQL text
// from baseQueryLiteral, a call graph with only 3 "?" placeholders,
// AND passed 8 bind args - GORM's rendering under that mismatch produces
// visibly wrong SQL, "(?)"-wrapped clauses in the wrong places, which is
// exactly the kind of bug a text-only glance would miss and only rendering
// the chain caught.)
const baseQueryBound = "SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
	"cm.refmsgid, cm.reportreason, " +
	"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
	"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
	"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
	"COALESCE(cr.groupid, 0) AS room_groupid, " +
	"0 AS widerchatreview, " +
	"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
	"cme.msgid, " +
	"COALESCE((SELECT m1.groupid FROM memberships m1 WHERE m1.userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND m1.groupid IN (?) LIMIT 1), 0) AS groupid, " +
	"COALESCE((SELECT m2.groupid FROM memberships m2 WHERE m2.userid = cm.userid AND m2.groupid IN (?) LIMIT 1), 0) AS groupidfrom " +
	"FROM chat_messages cm " +
	"INNER JOIN chat_rooms cr ON cr.id = cm.chatid " +
	"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
	"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
	"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
	"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
	"WHERE cm.reviewrequired = 1 AND cm.reviewrejected = 0" +
	" AND (" +
	"  (cr.chattype = ? AND cr.groupid IN (?))" +
	"  OR (cr.chattype = ? AND EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND groupid IN (?)))" +
	"  OR (cr.chattype = ? AND NOT EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND EXISTS (SELECT 1 FROM memberships WHERE userid = cm.userid AND groupid IN (?)))" +
	")"

const widerQueryBound = " UNION " +
	"SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
	"cm.refmsgid, cm.reportreason, " +
	"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
	"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
	"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
	"COALESCE(cr.groupid, 0) AS room_groupid, " +
	"1 AS widerchatreview, " +
	"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
	"cme.msgid, " +
	"m1.groupid AS groupid, " +
	"COALESCE(m2.groupid, 0) AS groupidfrom " +
	"FROM chat_messages cm " +
	"INNER JOIN chat_rooms cr ON cr.id = cm.chatid AND cm.reviewrequired = 1 AND cm.reviewrejected = 0 " +
	"INNER JOIN memberships m1 ON m1.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) " +
	"INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle' " +
	"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
	"LEFT JOIN memberships m2 ON m2.userid = cm.userid " +
	"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
	"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
	"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
	"WHERE JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 " +
	"AND cmh.id IS NULL " +
	"AND (cm.reportreason IS NULL OR cm.reportreason != 'User') " +
	"AND NOT EXISTS (SELECT 1 FROM memberships m_check WHERE m_check.userid = (CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND m_check.groupid IN (?))"

// insertReviewMessage inserts a chat_messages row with reviewrequired=1 the
// way production traffic creates one, bypassing the API (these tests are
// about the review-queue read, not the write path).
func insertReviewMessage(t *testing.T, chatID uint64, userID uint64, message string) uint64 {
	db := database.DBConn
	result := db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, reviewrejected, processingsuccessful) "+
		"VALUES (?, ?, ?, NOW(), 1, 0, 1)", chatID, userID, message)
	if result.Error != nil {
		t.Fatalf("ERROR: failed to insert review message: %v", result.Error)
	}
	var msgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND userid = ? ORDER BY id DESC LIMIT 1", chatID, userID).Scan(&msgID)
	return msgID
}

// TestGolden_5da587b4234d proves the else-branch chain renders the
// approved-diff text recorded in tools/orm-migration/approved-diffs.json
// (site 5da587b4234d) - Layer 1. groupIDs's content does not matter for a
// shape proof, only its being a []uint64 slice bind: GORM renders "IN (?)"
// (a single placeholder) here regardless of length, and
// assertRenderedSQL's approved-diff comparison collapses any run of bind
// placeholders to that same canonical form before comparing, so a
// one-element slice below proves the same statement shape a real
// multi-group moderator's longer slice would render.
func TestGolden_5da587b4234d(t *testing.T) {
	groupIDs := []uint64{1}
	ormharness.AssertGoldenSQL(t, "5da587b4234d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		qtx := tx.Table("chat_messages").Select(
			strings.TrimPrefix(baseQueryBound, "SELECT ")+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?",
			groupIDs, groupIDs,
			utils.CHAT_TYPE_USER2MOD, groupIDs,
			utils.CHAT_TYPE_USER2USER, groupIDs,
			utils.CHAT_TYPE_USER2USER, groupIDs,
			100)
		qtx.Statement.BuildClauses = []string{"SELECT"}
		return qtx.Find(&dest)
	})
}

// TestGolden_1ff296c8656c is TestGolden_5da587b4234d's wider-branch twin,
// proving the derived-table-wrapped UNION chain against the approved-diff
// text recorded for site 1ff296c8656c.
func TestGolden_1ff296c8656c(t *testing.T) {
	groupIDs := []uint64{1}
	ormharness.AssertGoldenSQL(t, "1ff296c8656c", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		qtx := tx.Table("chat_messages").Select(
			"* FROM ("+baseQueryBound+widerQueryBound+") combined GROUP BY id ORDER BY widerchatreview ASC, id ASC LIMIT ?",
			groupIDs, groupIDs,
			utils.CHAT_TYPE_USER2MOD, groupIDs,
			utils.CHAT_TYPE_USER2USER, groupIDs,
			utils.CHAT_TYPE_USER2USER, groupIDs,
			groupIDs,
			100)
		qtx.Statement.BuildClauses = []string{"SELECT"}
		return qtx.Find(&dest)
	})
}

// TestLayer2_5da587b4234d covers the non-wider branch: a moderator of one
// group, with a User2Mod message direct to that group and a User2User
// message whose recipient is a member of that group both queued for
// review, a reviewrequired=0 message that must NOT appear, and a
// reviewrequired=1 message in an unrelated group the moderator does not
// moderate that must also NOT appear (proves the group scoping, not just
// the reviewrequired flag).
func TestLayer2_5da587b4234d(t *testing.T) {
	prefix := uniquePrefix("reviewq_else")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)

	// User2Mod case: member posts straight to the group's mod chat.
	member1 := CreateTestUser(t, prefix+"_m1", "User")
	CreateTestMembership(t, member1, groupID, "Member")
	chatMod := CreateTestChatRoom(t, member1, nil, &groupID, "User2Mod")
	wantMsg1 := insertReviewMessage(t, chatMod, member1, "user2mod pending review")

	// User2User case 1: an outsider (no memberships anywhere) messages a
	// group member - the member is the "recipient" the base query looks for.
	outsider1 := CreateTestUser(t, prefix+"_out1", "User")
	member2 := CreateTestUser(t, prefix+"_m2", "User")
	CreateTestMembership(t, member2, groupID, "Member")
	chatU2U := CreateTestChatRoom(t, outsider1, &member2, nil, "User2User")
	wantMsg2 := insertReviewMessage(t, chatU2U, outsider1, "user2user to a group member")

	// Negative control: not review-required - must be excluded regardless of
	// group/chattype matching.
	notRequired := CreateTestChatRoom(t, member1, nil, &groupID, "Mod2Mod")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, reviewrejected, processingsuccessful) "+
		"VALUES (?, ?, ?, NOW(), 0, 0, 1)", notRequired, member1, "not review required")

	// Negative control: review-required, but in a group this moderator does
	// not moderate - proves the group scoping, not just the flag.
	otherGroup := CreateTestGroup(t, prefix+"_other")
	otherMember := CreateTestUser(t, prefix+"_otherm", "User")
	CreateTestMembership(t, otherMember, otherGroup, "Member")
	chatOther := CreateTestChatRoom(t, otherMember, nil, &otherGroup, "User2Mod")
	insertReviewMessage(t, chatOther, otherMember, "review required in an unrelated group")

	limit := 1000
	groupIDs := []uint64{groupID}

	ormharness.AssertResultParityForSite(t, "5da587b4234d", database.DBConn,
		baseQueryLiteral(groupID, "")+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?",
		[]any{utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit},
		func(tx *gorm.DB) *gorm.DB {
			qtx := tx.Table("chat_messages").Select(
				strings.TrimPrefix(baseQueryBound, "SELECT ")+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?",
				groupIDs, groupIDs,
				utils.CHAT_TYPE_USER2MOD, groupIDs,
				utils.CHAT_TYPE_USER2USER, groupIDs,
				utils.CHAT_TYPE_USER2USER, groupIDs,
				limit)
			qtx.Statement.BuildClauses = []string{"SELECT"}
			return qtx
		})

	// Sanity check the fixture actually produced the two rows this test is
	// about, so a passing AssertResultParity above two empty result sets
	// could never be mistaken for proof.
	var gotRows []sanityRow
	db.Raw(baseQueryLiteral(groupID, "")+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?",
		utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit).
		Scan(&gotRows)
	foundMsg1, foundMsg2 := false, false
	for _, row := range gotRows {
		if row.ID == wantMsg1 {
			foundMsg1 = true
		}
		if row.ID == wantMsg2 {
			foundMsg2 = true
		}
	}
	if !foundMsg1 || !foundMsg2 {
		t.Fatalf("fixture sanity check failed: expected message ids %d and %d in %v", wantMsg1, wantMsg2, gotRows)
	}
	if len(gotRows) != 2 {
		t.Fatalf("fixture sanity check failed: expected exactly 2 rows (the negative controls should be excluded), got %v", gotRows)
	}
}

// TestLayer2_1ff296c8656c covers the wider-review branch. The moderator
// moderates groupA, which has widerchatreview enabled (so
// user.HasWiderReview(myid) is true). groupB is a second, unrelated
// widerchatreview group the moderator does NOT moderate; a member of groupB
// receives a reviewrequired message from an outsider - not caught by the
// base query (the recipient is not in the moderator's own groups) but
// caught by the wider arm (the recipient's own group has widerchatreview
// enabled). A second groupB member's message is HELD, and must be excluded
// from the wider arm's results (cmh.id IS NULL) even though it would
// otherwise qualify - this is the one condition unique to the wider arm
// that the non-wider test above cannot exercise.
func TestLayer2_1ff296c8656c(t *testing.T) {
	prefix := uniquePrefix("reviewq_wider")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	db.Exec("UPDATE `groups` SET settings = ? WHERE id = ?", `{"widerchatreview": 1}`, groupA)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupA, utils.ROLE_MODERATOR)

	groupB := CreateTestGroup(t, prefix+"_b")
	db.Exec("UPDATE `groups` SET settings = ? WHERE id = ?", `{"widerchatreview": 1}`, groupB)

	// Wider-arm case: recipient is a groupB member, not a member of groupA
	// (the moderator's own group) - the base query cannot see this, only
	// the wider arm can.
	memberB := CreateTestUser(t, prefix+"_mb", "User")
	CreateTestMembership(t, memberB, groupB, "Member")
	outsider1 := CreateTestUser(t, prefix+"_out1", "User")
	chatWider := CreateTestChatRoom(t, outsider1, &memberB, nil, "User2User")
	wantMsg := insertReviewMessage(t, chatWider, outsider1, "wider-review candidate")

	// Held negative control: would otherwise qualify for the wider arm
	// (recipient is a different groupB member, message is reviewrequired),
	// but a hold record excludes it.
	memberB2 := CreateTestUser(t, prefix+"_mb2", "User")
	CreateTestMembership(t, memberB2, groupB, "Member")
	outsider2 := CreateTestUser(t, prefix+"_out2", "User")
	chatHeld := CreateTestChatRoom(t, outsider2, &memberB2, nil, "User2User")
	heldMsg := insertReviewMessage(t, chatHeld, outsider2, "held, must not appear")
	db.Exec("INSERT INTO chat_messages_held (msgid, userid) VALUES (?, ?)", heldMsg, modID)

	// Base-arm case, so the combined result spans both arms: a message
	// straight to the moderator's own group (groupA).
	memberA := CreateTestUser(t, prefix+"_ma", "User")
	CreateTestMembership(t, memberA, groupA, "Member")
	chatBase := CreateTestChatRoom(t, memberA, nil, &groupA, "User2Mod")
	wantBaseMsg := insertReviewMessage(t, chatBase, memberA, "base-arm candidate")

	limit := 1000
	groupIDs := []uint64{groupA}

	ormharness.AssertResultParityForSite(t, "1ff296c8656c", database.DBConn,
		"SELECT * FROM ("+baseQueryLiteral(groupA, "")+widerQueryLiteral(groupA, "")+") combined GROUP BY id ORDER BY widerchatreview ASC, id ASC LIMIT ?",
		[]any{utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit},
		func(tx *gorm.DB) *gorm.DB {
			qtx := tx.Table("chat_messages").Select(
				"* FROM ("+baseQueryBound+widerQueryBound+") combined GROUP BY id ORDER BY widerchatreview ASC, id ASC LIMIT ?",
				groupIDs, groupIDs,
				utils.CHAT_TYPE_USER2MOD, groupIDs,
				utils.CHAT_TYPE_USER2USER, groupIDs,
				utils.CHAT_TYPE_USER2USER, groupIDs,
				groupIDs,
				limit)
			qtx.Statement.BuildClauses = []string{"SELECT"}
			return qtx
		})

	// Fixture sanity check, same reasoning as the else-branch test above.
	var gotRows []sanityRow
	db.Raw("SELECT * FROM ("+baseQueryLiteral(groupA, "")+widerQueryLiteral(groupA, "")+") combined GROUP BY id ORDER BY widerchatreview ASC, id ASC LIMIT ?",
		utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit).
		Scan(&gotRows)
	foundWider, foundBase, foundHeld := false, false, false
	for _, row := range gotRows {
		if row.ID == wantMsg {
			foundWider = true
		}
		if row.ID == wantBaseMsg {
			foundBase = true
		}
		if row.ID == heldMsg {
			foundHeld = true
		}
	}
	if !foundWider || !foundBase {
		t.Fatalf("fixture sanity check failed: expected message ids %d (wider) and %d (base) in %v", wantMsg, wantBaseMsg, gotRows)
	}
	if foundHeld {
		t.Fatalf("fixture sanity check failed: held message %d must not appear in %v", heldMsg, gotRows)
	}
	// Deliberately no exact row-count assertion. An earlier version required
	// exactly 2 rows and failed against the seeded database, having found 6.
	// That was the assertion being wrong, not the query: the wider-review arm
	// joins memberships and groups on the RECIPIENT and filters on
	// widerchatreview, with no restriction to the moderator's own groups -
	// which is the entire point of wider review, letting mods on other Freegle
	// groups see the message. So any message whose recipient belongs to any
	// such group legitimately appears, and in a shared test database that is
	// however many the seed and the other tests happen to have created.
	//
	// Pinning a count here would make this test fail whenever unrelated
	// fixtures changed, which trains people to loosen it rather than read it.
	// What matters is checked above and is stable regardless of neighbours:
	// the wider and base fixtures appear, and the held one does not. The real
	// proof is the result-parity assertion that follows, which compares the
	// old and new statements against each other on the SAME database, so any
	// extra rows are present on both sides and cancel out.
}
