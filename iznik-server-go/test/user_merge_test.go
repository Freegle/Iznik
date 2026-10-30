package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

// mergeUsers calls POST /api/user action=Merge with an admin token.
func mergeUsers(t *testing.T, adminToken string, id1, id2 uint64) int {
	t.Helper()
	payload := map[string]interface{}{"action": "Merge", "id1": id1, "id2": id2}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/user?jwt="+adminToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// mergeAdminSetup creates an Admin user and returns (adminID, adminToken).
func mergeAdminSetup(t *testing.T, prefix string) (uint64, string) {
	t.Helper()
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)
	return adminID, token
}

// ── Section A: emails and memberships ────────────────────────────────────────

func TestMergeEmailsTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_email")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ?", id1).Scan(&count)
	assert.Equal(t, int64(1), count)

	status := mergeUsers(t, adminToken, id1, id2)
	assert.Equal(t, 200, status, "merge request should succeed")

	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ?", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(2), "id2 should have its own + id1's email")

	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ?", id1).Scan(&count)
	assert.Equal(t, int64(0), count, "id1 should have no emails after merge")
}

func TestMergeEmailPreferredWinsForId2(t *testing.T) {
	prefix := uniquePrefix("merge_pref")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	db.Exec("UPDATE users_emails SET preferred = 1 WHERE userid = ?", id1)
	db.Exec("UPDATE users_emails SET preferred = 1 WHERE userid = ?", id2)

	var id2PreferredEmail string
	db.Raw("SELECT email FROM users_emails WHERE userid = ? AND preferred = 1", id2).Scan(&id2PreferredEmail)
	assert.NotEmpty(t, id2PreferredEmail, "id2 should have a preferred email before merge")

	status := mergeUsers(t, adminToken, id1, id2)
	assert.Equal(t, 200, status, "merge request should succeed")

	var preferredEmail string
	db.Raw("SELECT email FROM users_emails WHERE userid = ? AND preferred = 1", id2).Scan(&preferredEmail)
	assert.Equal(t, id2PreferredEmail, preferredEmail, "id2's preferred email must remain preferred after merge")
}

func TestMergeMembershipTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_memb")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, id1, groupID, "Member")

	status := mergeUsers(t, adminToken, id1, id2)
	assert.Equal(t, 200, status, "merge request should succeed")

	var role string
	db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&role)
	assert.Equal(t, "Member", role, "id2 should inherit id1's membership")

	var count int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ?", id1).Scan(&count)
	assert.Equal(t, int64(0), count, "id1 should have no memberships after merge")
}

func TestMergeMembershipRoleTakesMax(t *testing.T) {
	prefix := uniquePrefix("merge_role")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, id1, groupID, "Moderator")
	CreateTestMembership(t, id2, groupID, "Member")

	status := mergeUsers(t, adminToken, id1, id2)
	assert.Equal(t, 200, status, "merge request should succeed")

	var role string
	db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&role)
	assert.Equal(t, "Moderator", role, "merged user should have the higher role")
}

func TestMergeMembershipConflictTakesOlderDate(t *testing.T) {
	prefix := uniquePrefix("merge_date")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	db.Exec(fmt.Sprintf("INSERT INTO memberships (userid, groupid, role, added) VALUES (%d, %d, 'Member', '2013-01-01 00:00:00')", id1, groupID))
	db.Exec(fmt.Sprintf("INSERT INTO memberships (userid, groupid, role, added) VALUES (%d, %d, 'Member', NOW())", id2, groupID))

	status := mergeUsers(t, adminToken, id1, id2)
	assert.Equal(t, 200, status, "merge request should succeed")

	var added string
	db.Raw("SELECT DATE_FORMAT(added, '%Y-%m-%d') FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&added)
	assert.Equal(t, "2013-01-01", added, "merged user should have the older joined date")
}

// added_origin says how the row was created, so it follows the row whose added date is kept.
// The merged membership is implicit only if both were: either account having chosen the group
// is enough.
func TestMergeMembershipConflictTakesTheOlderOriginAndTheLowerImplicit(t *testing.T) {
	prefix := uniquePrefix("merge_origin")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', '2013-01-01 00:00:00', 'manual', 0, 0)", id1, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', NOW(), 'rippled', 1, 1)", id2, groupID)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var origin string
	var implicit, rippled int
	db.Raw("SELECT added_origin, implicit, rippled FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).
		Row().Scan(&origin, &implicit, &rippled)
	assert.Equal(t, "manual", origin, "the older row's origin goes with its added date")
	assert.Equal(t, 0, implicit)
	assert.Equal(t, 0, rippled)
	assert.Equal(t, int64(1), becameOrdinaryLogs(t, id2, groupID, "rippled"), "the ripple join became ordinary")
}

// A TrashNothing placement merged with a newer membership the member chose keeps its origin, as
// the older row, and becomes ordinary. The log says so, as every other clear does, and says it
// was a TrashNothing placement.
func TestMergeMembershipConflictLogsAPlacementMadeOrdinary(t *testing.T) {
	prefix := uniquePrefix("merge_origin_log")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', '2013-01-01 00:00:00', 'tn_unaddressed', 1, 0)", id1, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', NOW(), 'manual', 0, 0)", id2, groupID)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var origin string
	var implicit int
	db.Raw("SELECT added_origin, implicit FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).
		Row().Scan(&origin, &implicit)
	assert.Equal(t, "tn_unaddressed", origin)
	assert.Equal(t, 0, implicit)
	assert.Equal(t, int64(1), becameOrdinaryLogs(t, id2, groupID, "tn_unaddressed"),
		"the log says it was a TrashNothing placement")
}

// becameOrdinaryLogs counts the merge's "became ordinary" rows for a placement made with origin.
func becameOrdinaryLogs(t *testing.T, userid, groupid uint64, origin string) int64 {
	t.Helper()
	var n int64
	database.DBConn.Table("logs").
		Where("user = ? AND groupid = ? AND type = 'Group' AND subtype = 'Joined' AND text = ?",
			userid, groupid, rippling.BecameOrdinaryText(origin, rippling.ReasonMerged)).
		Count(&n)
	return n
}

// An older ripple join and a newer TN placement merge to a TN placement: a state ingestion
// writes. The older row's origin with the lower rippled would be a ripple-origin row with
// rippled = 0, which no writer makes and which ripple clean-up would delete.
func TestMergeMembershipConflictOfARippleJoinAndATNPlacementIsATNPlacement(t *testing.T) {
	prefix := uniquePrefix("merge_origin_keep")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', NOW(), 'tn_unaddressed', 1, 0)", id1, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', '2013-01-01 00:00:00', 'rippled', 1, 1)", id2, groupID)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var origin string
	var implicit, rippled int
	db.Raw("SELECT added_origin, implicit, rippled FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).
		Row().Scan(&origin, &implicit, &rippled)
	assert.Equal(t, "tn_unaddressed", origin)
	assert.Equal(t, 1, implicit, "both were implicit, so the merged row still is")
	assert.Equal(t, 0, rippled)
}

// Two ripple joins stay a ripple join.
func TestMergeMembershipConflictOfTwoRippleJoinsIsARippleJoin(t *testing.T) {
	prefix := uniquePrefix("merge_origin_ripples")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', NOW(), 'rippled', 1, 1)", id1, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, added, added_origin, implicit, rippled) "+
		"VALUES (?, ?, 'Member', '2013-01-01 00:00:00', 'rippled', 1, 1)", id2, groupID)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var origin string
	var implicit, rippled int
	db.Raw("SELECT added_origin, implicit, rippled FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).
		Row().Scan(&origin, &implicit, &rippled)
	assert.Equal(t, "rippled", origin)
	assert.Equal(t, 1, implicit)
	assert.Equal(t, 1, rippled)
	assert.Equal(t, int64(0), becameOrdinaryLogs(t, id2, groupID, "rippled"), "nothing became ordinary")
	assert.Equal(t, int64(0), becameOrdinaryLogs(t, id2, groupID, "tn_unaddressed"))
}

// ── Section B: messages, history, chat rooms, sessions, logins ───────────────

func TestMergeMessagesTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_msg")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, id1, groupID, "Test offer", 55.9533, -3.1883)

	mergeUsers(t, adminToken, id1, id2)

	var fromuser uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", msgID).Scan(&fromuser)
	assert.Equal(t, id2, fromuser, "message should now belong to id2")
}

func TestMergeMessagesHistoryTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_msghist")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	db.Exec("INSERT INTO messages_history (fromuser, msgid, arrival) VALUES (?, NULL, NOW())", id1)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_history WHERE fromuser = ?", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(1), "messages_history should transfer to id2")

	db.Raw("SELECT COUNT(*) FROM messages_history WHERE fromuser = ?", id1).Scan(&count)
	assert.Equal(t, int64(0), count, "id1 should have no messages_history rows")
}

func TestMergeLogsTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_logs")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	db.Exec("INSERT INTO logs (user, byuser, type, subtype, timestamp) VALUES (?, ?, 'User', 'Created', NOW())", id1, id1)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE user = ? AND subtype != 'Merged'", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(1), "logs about id1 should transfer to id2")

	db.Raw("SELECT COUNT(*) FROM logs WHERE byuser = ? AND subtype != 'Merged'", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(1), "logs by id1 should transfer to id2")
}

func TestMergeChatRoomSimpleTransfer(t *testing.T) {
	// id1 has a room with user3; id2 has no room with user3 → room transfers to id2.
	prefix := uniquePrefix("merge_chat")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	user3 := CreateTestUser(t, prefix+"_u3", "User")

	roomID := CreateTestChatRoom(t, id1, &user3, nil, "User2User")

	mergeUsers(t, adminToken, id1, id2)

	var user1 uint64
	db.Raw("SELECT user1 FROM chat_rooms WHERE id = ?", roomID).Scan(&user1)
	assert.Equal(t, id2, user1, "chat room should now belong to id2")
}

func TestMergeChatRoomDeduplicated(t *testing.T) {
	// Both id1 and id2 have User2User rooms with user3 → merge into one room.
	prefix := uniquePrefix("merge_dedup")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	user3 := CreateTestUser(t, prefix+"_u3", "User")

	room1 := CreateTestChatRoom(t, id1, &user3, nil, "User2User")
	CreateTestChatMessage(t, room1, id1, "Hello from id1 a")
	CreateTestChatMessage(t, room1, id1, "Hello from id1 b")

	room2 := CreateTestChatRoom(t, id2, &user3, nil, "User2User")
	CreateTestChatMessage(t, room2, id2, "Hello from id2")

	mergeUsers(t, adminToken, id1, id2)

	var roomCount int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
		id2, user3, user3, id2).Scan(&roomCount)
	assert.Equal(t, int64(1), roomCount, "should be exactly one room between id2 and user3")

	var msgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", room2).Scan(&msgCount)
	assert.Equal(t, int64(3), msgCount, "all 3 messages should be in the surviving room")
}

func TestMergeChatRosterTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_roster")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	user3 := CreateTestUser(t, prefix+"_u3", "User")
	room := CreateTestChatRoom(t, id1, &user3, nil, "User2User")
	db.Exec("INSERT INTO chat_roster (chatid, userid, lastmsgseen) VALUES (?, ?, 0)", room, id1)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_roster WHERE userid = ?", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(1), "chat_roster should transfer to id2")
}

func TestMergeSessionsTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_sess")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	id1SessionID, _ := CreateTestSession(t, id1)

	mergeUsers(t, adminToken, id1, id2)

	var userid uint64
	db.Raw("SELECT userid FROM sessions WHERE id = ?", id1SessionID).Scan(&userid)
	assert.Equal(t, id2, userid, "id1's session should be reassigned to id2")
}

func TestMergeLoginsTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_login")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("INSERT INTO users_logins (userid, type, uid) VALUES (?, 'Facebook', ?)", id1, prefix+"_fb")

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_logins WHERE userid = ? AND type = 'Facebook'", id2).Scan(&count)
	assert.Equal(t, int64(1), count, "id1's Facebook login should transfer to id2")
}

// ── Section C: user attributes, simple tables, bans, giftaid, log entries ────

func TestMergeUserAttributeNameFromId1(t *testing.T) {
	// id2 has no fullname; id1's fullname should be taken.
	prefix := uniquePrefix("merge_name")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("UPDATE users SET fullname = 'Alice Smith', firstname = 'Alice', lastname = 'Smith' WHERE id = ?", id1)
	db.Exec("UPDATE users SET fullname = NULL, firstname = NULL, lastname = NULL WHERE id = ?", id2)

	mergeUsers(t, adminToken, id1, id2)

	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", id2).Scan(&fullname)
	assert.Equal(t, "Alice Smith", fullname, "id2 should inherit id1's fullname when id2 has none")

	var firstname string
	db.Raw("SELECT firstname FROM users WHERE id = ?", id2).Scan(&firstname)
	assert.Equal(t, "Alice", firstname, "id2 should inherit id1's firstname when id2 has none")
}

func TestMergeUserAttributeId2NamePreserved(t *testing.T) {
	// id2 already has a fullname; it should NOT be overwritten.
	prefix := uniquePrefix("merge_keep")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("UPDATE users SET fullname = 'Old Name' WHERE id = ?", id1)
	db.Exec("UPDATE users SET fullname = 'Alice Smith' WHERE id = ?", id2)

	mergeUsers(t, adminToken, id1, id2)

	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", id2).Scan(&fullname)
	assert.Equal(t, "Alice Smith", fullname, "id2's existing fullname should not be overwritten")
}

func TestMergeSystemroleTakesMax(t *testing.T) {
	prefix := uniquePrefix("merge_sysrole")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "Support")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	mergeUsers(t, adminToken, id1, id2)

	var role string
	db.Raw("SELECT systemrole FROM users WHERE id = ?", id2).Scan(&role)
	assert.Equal(t, "Support", role, "id2 should have the higher systemrole after merge")
}

func TestMergeAddedDateTakesOldest(t *testing.T) {
	prefix := uniquePrefix("merge_added")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("UPDATE users SET added = '2010-01-01 00:00:00' WHERE id = ?", id1)
	db.Exec("UPDATE users SET added = '2020-01-01 00:00:00' WHERE id = ?", id2)

	mergeUsers(t, adminToken, id1, id2)

	var added string
	db.Raw("SELECT DATE_FORMAT(added, '%Y-%m-%d') FROM users WHERE id = ?", id2).Scan(&added)
	assert.Equal(t, "2010-01-01", added, "id2 should have the oldest added date")
}

func TestMergeDonationsTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_donate")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("INSERT INTO users_donations (userid, type, Payer, PayerDisplayName, GrossAmount, source) VALUES (?, 'Stripe', 'Test', 'Test', 10.00, 'Stripe')", id1)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_donations WHERE userid = ?", id2).Scan(&count)
	assert.GreaterOrEqual(t, count, int64(1), "id1's donation should transfer to id2")

	db.Raw("SELECT COUNT(*) FROM users_donations WHERE userid = ?", id1).Scan(&count)
	assert.Equal(t, int64(0), count, "no donations should remain on id1")
}

func TestMergeBansHandled(t *testing.T) {
	prefix := uniquePrefix("merge_bans")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)
	bannerID := CreateTestUser(t, prefix+"_banner", "Admin")

	db.Exec("INSERT INTO users_banned (userid, groupid, byuser) VALUES (?, ?, ?)", id1, groupID, bannerID)
	CreateTestMembership(t, id2, groupID, "Member")

	mergeUsers(t, adminToken, id1, id2)

	var banCount int64
	db.Raw("SELECT COUNT(*) FROM users_banned WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&banCount)
	assert.GreaterOrEqual(t, banCount, int64(1), "id2 should be banned after inheriting id1's ban")

	var membCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&membCount)
	assert.Equal(t, int64(0), membCount, "id2's membership should be removed for banned group")
}

// users_banned.date updates itself on any change. A merge must move bans without re-stamping
// them, on both sides: bans of the merged member, and bans the merged moderator made.
func TestMergeKeepsBanDates(t *testing.T) {
	prefix := uniquePrefix("merge_bandates")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	other := CreateTestUser(t, prefix+"_other", "User")
	groupID := CreateTestGroup(t, prefix)
	group2ID := CreateTestGroup(t, prefix+"_g2")
	bannerID := CreateTestUser(t, prefix+"_banner", "Admin")

	db.Exec("INSERT INTO users_banned (userid, groupid, byuser, date) VALUES (?, ?, ?, '2020-03-04 05:06:07')", id1, groupID, bannerID)
	db.Exec("INSERT INTO users_banned (userid, groupid, byuser, date) VALUES (?, ?, ?, '2021-08-09 10:11:12')", other, group2ID, id1)

	mergeUsers(t, adminToken, id1, id2)

	var banned string
	db.Raw("SELECT DATE_FORMAT(date, '%Y-%m-%d %H:%i:%s') FROM users_banned WHERE userid = ? AND groupid = ?", id2, groupID).Scan(&banned)
	assert.Equal(t, "2020-03-04 05:06:07", banned, "the merged member's ban keeps its date")

	var made string
	db.Raw("SELECT DATE_FORMAT(date, '%Y-%m-%d %H:%i:%s') FROM users_banned WHERE userid = ? AND groupid = ? AND byuser = ?", other, group2ID, id2).Scan(&made)
	assert.Equal(t, "2021-08-09 10:11:12", made, "a ban the merged moderator made keeps its date")
}

func TestMergeGiftaidBestPeriodKept(t *testing.T) {
	prefix := uniquePrefix("merge_giftaid")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	db.Exec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'Past4YearsAndFuture', 'Alice', '1 Main St')", id1)
	db.Exec("INSERT INTO giftaid (userid, period, fullname, homeaddress) VALUES (?, 'Declined', 'Alice', '1 Main St')", id2)

	mergeUsers(t, adminToken, id1, id2)

	var count int64
	db.Raw("SELECT COUNT(*) FROM giftaid WHERE userid = ?", id2).Scan(&count)
	assert.Equal(t, int64(1), count, "only one giftaid row should remain")

	var period string
	db.Raw("SELECT period FROM giftaid WHERE userid = ?", id2).Scan(&period)
	assert.Equal(t, "Past4YearsAndFuture", period, "the best period should be kept")
}

func TestMergeMergeLogEntries(t *testing.T) {
	prefix := uniquePrefix("merge_logentry")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	mergeUsers(t, adminToken, id1, id2)

	entry1 := findLog(db, "User", "Merged", id1)
	assert.NotNil(t, entry1, "should have a Merged log for the discarded user (id1)")

	entry2 := findLog(db, "User", "Merged", id2)
	assert.NotNil(t, entry2, "should have a Merged log for the kept user (id2)")
}

// ── TrashNothing accounts ────────────────────────────────────────────────────

// stampTN gives a test user a TrashNothing id, releasing it from any user an earlier run
// left holding it (tnuserid is UNIQUE).
func stampTN(id uint64, tnuserid uint64) {
	db := database.DBConn
	db.Exec("UPDATE users SET tnuserid = NULL WHERE tnuserid = ?", tnuserid)
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", tnuserid, id)
}

func userExists(id uint64) bool {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM users WHERE id = ?", id).Scan(&n)
	return n > 0
}

// A Freegle account and a TrashNothing account are never merged, even by id: ModTools only
// refused when a TrashNothing address was typed in.
func TestMergeRefusesFreegleWithTrashNothingByID(t *testing.T) {
	prefix := uniquePrefix("merge_fd_tn")
	_, adminToken := mergeAdminSetup(t, prefix)

	fd := CreateTestUser(t, prefix+"_fd", "User")
	tn := CreateTestUser(t, prefix+"_tn", "User")
	stampTN(tn, 991001)

	assert.Equal(t, 400, mergeUsers(t, adminToken, fd, tn))
	assert.Equal(t, 400, mergeUsers(t, adminToken, tn, fd), "refused in either direction")
	assert.True(t, userExists(fd))
	assert.True(t, userExists(tn))
}

// A TrashNothing address as the preferred email makes an account a TrashNothing member even
// without a tnuserid stamp.
func TestMergeRefusesFreegleWithTrashNothingPreferredEmail(t *testing.T) {
	prefix := uniquePrefix("merge_fd_tnmail")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	fd := CreateTestUser(t, prefix+"_fd", "User")
	tn := CreateTestUser(t, prefix+"_tn", "User")
	db.Exec("UPDATE users_emails SET preferred = 0 WHERE userid = ?", tn)
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 1)", tn, prefix+"-g1@user.trashnothing.com")

	assert.Equal(t, 400, mergeUsers(t, adminToken, fd, tn))
	assert.True(t, userExists(fd))
}

// Two different TrashNothing ids are two TrashNothing members: merging them would drop one id.
func TestMergeRefusesTwoDifferentTrashNothingMembers(t *testing.T) {
	prefix := uniquePrefix("merge_tn_tn")
	_, adminToken := mergeAdminSetup(t, prefix)

	a := CreateTestUser(t, prefix+"_a", "User")
	b := CreateTestUser(t, prefix+"_b", "User")
	stampTN(a, 991002)
	stampTN(b, 991003)

	assert.Equal(t, 400, mergeUsers(t, adminToken, a, b))
	assert.True(t, userExists(a))
	assert.True(t, userExists(b))

	// The shared merge refuses it too, so the TN divergence heal cannot do it either.
	assert.Error(t, user.MergeUsersTx(database.DBConn, a, b, b))
	assert.True(t, userExists(a))

	var tnA uint64
	database.DBConn.Raw("SELECT COALESCE(tnuserid, 0) FROM users WHERE id = ?", a).Scan(&tnA)
	assert.Equal(t, uint64(991002), tnA, "both ids kept")
}

// Twin repair stays possible: one account stamped, its twin not, both TrashNothing.
func TestMergeAllowsTrashNothingTwinWithoutStamp(t *testing.T) {
	prefix := uniquePrefix("merge_tn_twin")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	stamped := CreateTestUser(t, prefix+"_s", "User")
	twin := CreateTestUser(t, prefix+"_t", "User")
	stampTN(stamped, 991004)
	db.Exec("UPDATE users_emails SET preferred = 0 WHERE userid = ?", twin)
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 1)", twin, prefix+"-g2@user.trashnothing.com")

	assert.Equal(t, 200, mergeUsers(t, adminToken, twin, stamped))
	assert.False(t, userExists(twin))

	var tn uint64
	db.Raw("SELECT COALESCE(tnuserid, 0) FROM users WHERE id = ?", stamped).Scan(&tn)
	assert.Equal(t, uint64(991004), tn)
}

// A member's answers to the UX questions move with them on a merge. Where both accounts answered
// the same question the target keeps its own answer, because a member has one vote per question.
func TestMergeUxPromptResponsesTransferred(t *testing.T) {
	prefix := uniquePrefix("merge_uxresp")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)
	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	cleanup := func() {
		db.Exec("DELETE r FROM ux_prompt_responses r INNER JOIN ux_prompts p ON p.id = r.prompt_id WHERE p.question LIKE ?", prefix+"%")
		db.Exec("DELETE FROM ux_prompts WHERE question LIKE ?", prefix+"%")
	}
	cleanup()
	t.Cleanup(cleanup)

	var ids [2]uint64
	for i := range ids {
		db.Exec("INSERT INTO ux_prompts (kind, question, body, options, images, status, max_responses, created_at, updated_at) VALUES ('open', ?, '', '[]', '[]', 'open', 100, NOW(), NOW())", fmt.Sprintf("%s q%d", prefix, i))
		db.Raw("SELECT id FROM ux_prompts WHERE question = ?", fmt.Sprintf("%s q%d", prefix, i)).Scan(&ids[i])
	}
	// id1 answered both questions; id2 answered the first as well.
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, '{\"text\":\"from one\"}')", ids[0], id1)
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, '{\"text\":\"from one too\"}')", ids[1], id1)
	db.Exec("INSERT INTO ux_prompt_responses (prompt_id, userid, answer) VALUES (?, ?, '{\"text\":\"from two\"}')", ids[0], id2)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var moved int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE userid = ? AND prompt_id IN (?, ?)", id2, ids[0], ids[1]).Scan(&moved)
	assert.Equal(t, int64(2), moved, "the second question moved across and the first was not duplicated")
	var kept string
	db.Raw("SELECT JSON_UNQUOTE(JSON_EXTRACT(answer, '$.text')) FROM ux_prompt_responses WHERE userid = ? AND prompt_id = ?", id2, ids[0]).Scan(&kept)
	assert.Equal(t, "from two", kept, "the target's own answer wins")
	var orphaned int64
	db.Raw("SELECT COUNT(*) FROM ux_prompt_responses WHERE userid = ?", id1).Scan(&orphaned)
	assert.Equal(t, int64(0), orphaned, "nothing is left behind for the user who no longer exists")
}

// ── Chat rooms: the one merge must do what the email-verify merge learned ────

// Both accounts have a User2Mod room with the same community: one room survives, holding
// both histories.
func TestMergeChatRoomUser2ModDeduplicated(t *testing.T) {
	prefix := uniquePrefix("merge_u2m")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix)

	room1 := CreateTestChatRoom(t, id1, nil, &groupID, "User2Mod")
	CreateTestChatMessage(t, room1, id1, "to the mods from id1")
	room2 := CreateTestChatRoom(t, id2, nil, &groupID, "User2Mod")
	CreateTestChatMessage(t, room2, id2, "to the mods from id2")

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var roomCount int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = 'User2Mod'",
		id2, groupID).Scan(&roomCount)
	assert.Equal(t, int64(1), roomCount, "exactly one User2Mod room should remain for id2 on the group")

	var msgCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", room2).Scan(&msgCount)
	assert.Equal(t, int64(2), msgCount, "both messages should sit in the surviving room")

	var stranded int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 = ? OR user2 = ?", id1, id1).Scan(&stranded)
	assert.Equal(t, int64(0), stranded, "no room may be left on the discarded account")
}

// A room between the two accounts being merged would become a chat with oneself. It goes,
// with its messages, rather than surviving as a self-chat.
func TestMergeChatRoomBetweenThePairIsDeleted(t *testing.T) {
	prefix := uniquePrefix("merge_pair")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")

	direct := CreateTestChatRoom(t, id1, &id2, nil, "User2User")
	CreateTestChatMessage(t, direct, id1, "talking to my other account")

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE id = ?", direct).Scan(&count)
	assert.Equal(t, int64(0), count, "the room between the two accounts should be deleted")
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 = ? AND user2 = ?", id2, id2).Scan(&count)
	assert.Equal(t, int64(0), count, "no self-chat may be created")
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", direct).Scan(&count)
	assert.Equal(t, int64(0), count, "its messages go with it")
}

// When two rooms with the same counterparty are folded into one, everything that pointed at
// the discarded room follows: the counterparty's roster row (so they keep being emailed) and
// any message that refers to that room.
func TestMergeChatRoomDuplicateCarriesRosterAndReferences(t *testing.T) {
	prefix := uniquePrefix("merge_carry")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	user3 := CreateTestUser(t, prefix+"_u3", "User")
	groupID := CreateTestGroup(t, prefix)

	room1 := CreateTestChatRoom(t, id1, &user3, nil, "User2User")
	room2 := CreateTestChatRoom(t, id2, &user3, nil, "User2User")
	// user3 sits in the room that will be discarded, and only there.
	db.Exec("INSERT INTO chat_roster (chatid, userid) VALUES (?, ?)", room1, user3)
	// A message elsewhere refers to the room that will be discarded.
	modRoom := CreateTestChatRoom(t, user3, nil, &groupID, "User2Mod")
	refMsg := CreateTestChatMessage(t, modRoom, user3, "about that chat")
	db.Exec("UPDATE chat_messages SET refchatid = ? WHERE id = ?", room1, refMsg)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE id = ?", room1).Scan(&count)
	assert.Equal(t, int64(0), count, "the discarded account's duplicate room should be deleted")
	db.Raw("SELECT COUNT(*) FROM chat_roster WHERE chatid = ? AND userid = ?", room2, user3).Scan(&count)
	assert.Equal(t, int64(1), count, "the counterparty's roster row should move to the surviving room")

	var refchatid uint64
	db.Raw("SELECT COALESCE(refchatid, 0) FROM chat_messages WHERE id = ?", refMsg).Scan(&refchatid)
	assert.Equal(t, room2, refchatid, "a reference to the discarded room should point at the surviving room")
}

// ── Parity with the PHP merge in iznik-batch ─────────────────────────────────

// A member can set canmerge to false in their settings. The PHP merge refuses; so must this,
// whichever side of the merge they are on.
func TestMergeRefusedWhenEitherAccountOptsOut(t *testing.T) {
	prefix := uniquePrefix("merge_optout")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.canmerge', false) WHERE id = ?", id2)

	assert.Equal(t, 400, mergeUsers(t, adminToken, id1, id2))
	assert.True(t, userExists(id1), "a refused merge must leave the discarded account in place")
	assert.True(t, userExists(id2))

	// The other way round too.
	id3 := CreateTestUser(t, prefix+"_u3", "User")
	assert.Equal(t, 400, mergeUsers(t, adminToken, id2, id3))
	assert.True(t, userExists(id2))
}

// yahooid is unique. The kept account can only take the discarded account's id once the
// discarded account has let go of it.
func TestMergeYahooidFillsBlankOnKeptAccount(t *testing.T) {
	prefix := uniquePrefix("merge_yahoo")
	db := database.DBConn
	_, adminToken := mergeAdminSetup(t, prefix)

	id1 := CreateTestUser(t, prefix+"_u1", "User")
	id2 := CreateTestUser(t, prefix+"_u2", "User")
	yahooid := fmt.Sprintf("y%d", id1)
	db.Exec("UPDATE users SET yahooid = ? WHERE id = ?", yahooid, id1)
	db.Exec("UPDATE users SET yahooid = NULL WHERE id = ?", id2)

	assert.Equal(t, 200, mergeUsers(t, adminToken, id1, id2))

	var got string
	db.Raw("SELECT COALESCE(yahooid, '') FROM users WHERE id = ?", id2).Scan(&got)
	assert.Equal(t, yahooid, got, "the kept account should take the discarded account's yahooid")
}
