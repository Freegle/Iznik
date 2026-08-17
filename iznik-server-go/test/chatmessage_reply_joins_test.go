package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// TestCreateChatMessage_ReplyJoinsGroup verifies the server-side "replying joins the group"
// enforcement. Replying to a post is meant to join the replier to its group, and the Nuxt reply
// flow issues a PUT /memberships to do so - but a stale/racy client isMember check can skip that
// call, leaving a fresh registrant with a chat but NO membership and no location (the "member with
// no groups & no location" a moderator flagged in Discourse #9969; ~2/day in prod). The write path
// now guarantees the join, atomic with the reply, so no client can skip it.
func TestCreateChatMessage_ReplyJoinsGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyjoin")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reply-joins test item", 51.5, -0.1)

	// A brand-new user who is NOT a member of the group replies to the post.
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	var before int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, groupID).Scan(&before)
	assert.Equal(t, 0, before, "the replier starts as a non-member")

	// chat_rooms has a UNIQUE key on (user1, user2, chattype), so we create ONE room and post the
	// reply into it - both the first join and the idempotency re-reply below. (Calling a helper that
	// creates a fresh room for the second reply would hit a duplicate-key 1062 on the same pair.)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)
	post := func() int {
		var payload chat.ChatMessage
		payload.Message = "I'd like this please"
		payload.Refmsgid = &msgID
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		return resp.StatusCode
	}

	assert.Equal(t, fiber.StatusOK, post(), "the reply is accepted")

	// The reply joined them to the post's group, with the NORMAL web-join defaults - an approved
	// Member on daily email frequency (24), NOT the LoveJunk FREQUENCY_NEVER.
	var m struct {
		Cnt            int    `gorm:"column:cnt"`
		Role           string `gorm:"column:role"`
		Collection     string `gorm:"column:collection"`
		Emailfrequency int    `gorm:"column:emailfrequency"`
	}
	db.Raw("SELECT COUNT(*) AS cnt, MAX(role) AS role, MAX(collection) AS collection, "+
		"MAX(emailfrequency) AS emailfrequency FROM memberships WHERE userid = ? AND groupid = ?",
		replierID, groupID).Scan(&m)
	assert.Equal(t, 1, m.Cnt, "replying to a post joins the replier to its group (Discourse #9969)")
	assert.Equal(t, "Member", m.Role)
	assert.Equal(t, "Approved", m.Collection)
	assert.Equal(t, 24, m.Emailfrequency,
		"joined with the normal-join default email frequency (daily), not FREQUENCY_NEVER")

	// A memberships_history row with processingrequired=1 is what drives the Laravel batch to send
	// the group welcome email and run spam/review checks - without it the welcome is silently dropped.
	var histReq int
	db.Raw("SELECT COUNT(*) FROM memberships_history WHERE userid = ? AND groupid = ? AND processingrequired = 1",
		replierID, groupID).Scan(&histReq)
	assert.GreaterOrEqual(t, histReq, 1,
		"a memberships_history processingrequired row is written so the welcome/spam-check cron runs")

	// Idempotent: replying again into the SAME room neither errors nor duplicates the membership.
	assert.Equal(t, fiber.StatusOK, post())
	var after int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, groupID).Scan(&after)
	assert.Equal(t, 1, after, "a second reply does not duplicate the membership")
}

// TestCreateChatMessage_ReplyRespectsBan verifies the join enforcement does not resurrect a banned
// user's membership. A refmsgid reply from someone banned from the group is still accepted as a chat
// message, but must NOT (re)join them - AddMembership refuses banned users.
func TestCreateChatMessage_ReplyRespectsBan(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replybanned")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: reply-banned test item", 51.5, -0.1)

	bannedID := CreateTestUser(t, prefix+"_banned", "User")
	db.Exec("INSERT INTO users_banned (userid, groupid, byuser, date) VALUES (?, ?, ?, NOW())",
		bannedID, groupID, posterID)
	defer db.Exec("DELETE FROM users_banned WHERE userid = ? AND groupid = ?", bannedID, groupID)

	assert.Equal(t, fiber.StatusOK, postInterestedReply(t, bannedID, posterID, msgID, ""),
		"the reply itself is still accepted")

	var cnt int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", bannedID, groupID).Scan(&cnt)
	assert.Equal(t, 0, cnt, "a banned user is not (re)joined to the group by replying")
}

// TestCreateChatMessage_ReportDoesNotJoinGroup verifies the join enforcement is scoped to replies.
// A report is a User2Mod chat message that also carries refmsgid (to link the reported post), so it
// is typed CHAT_MESSAGE_INTERESTED - the same type as a reply. But reporting is not replying: the
// reporter must NOT be joined to the reported post's group (mirrors the reach-gate scoping, #9852).
func TestCreateChatMessage_ReportDoesNotJoinGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reportnojoin")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: report-no-join test item", 51.5, -0.1)

	reporterID := CreateTestUser(t, prefix+"_reporter", "User")
	chatID := CreateTestChatRoom(t, reporterID, nil, &groupID, "User2Mod")
	_, token := CreateTestSession(t, reporterID)

	var payload chat.ChatMessage
	payload.Message = "I'm reporting this post as inappropriate."
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the report is accepted")

	var cnt int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", reporterID, groupID).Scan(&cnt)
	assert.Equal(t, 0, cnt, "reporting a post (User2Mod) must NOT join the reporter to the group")
}

// A replier who is ALREADY in one of the post's groups must not be joined to another one.
// The join picks the post's lowest group id, which after rippling is usually a copy the
// replier has no connection to: a Leeds member replied to a Leeds post that had rippled
// into Bradford minutes earlier, and because Bradford's id sorts first she was signed up
// to Bradford, unsubscribed, and complained on ChitChat (2026-08-17).
func TestCreateChatMessage_ReplyDoesNotJoinWhenAlreadySharingAGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replynojoin")

	homeGroup := CreateTestGroup(t, prefix+"_home")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	// The post's other group must sort FIRST, so the old code would have picked it.
	if rippledGroup > homeGroup {
		homeGroup, rippledGroup = rippledGroup, homeGroup
	}

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, homeGroup, "Member")
	msgID := CreateTestMessage(t, posterID, homeGroup, "OFFER: already-a-member test item", 51.5, -0.1)
	// The post also sits on the lower-numbered group, as a rippled-in copy would.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in) VALUES (?, ?, 'Approved', NOW(), 1)",
		msgID, rippledGroup)

	// The replier is already a member of the group the post is native to.
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, replierID, homeGroup, "Member")

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)
	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the reply is accepted")

	var joinedElsewhere int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, rippledGroup).Scan(&joinedElsewhere)
	assert.Equal(t, 0, joinedElsewhere,
		"a replier already in one of the post's groups must not be joined to another")

	// ...and their existing membership is untouched.
	var stillHome int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, homeGroup).Scan(&stillHome)
	assert.Equal(t, 1, stillHome, "their existing membership is left alone")
}

// When a join IS needed, it goes to the group NEAREST the replier - not the post's
// lowest group id. After rippling a post sits on several groups, and the lowest id
// is a lottery: picking it is how a Leeds member ended up in Bradford.
func TestCreateChatMessage_ReplyJoinsNearestGroup(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replynearest")

	// Two groups the post will sit on: one far from the replier, one on their doorstep.
	farGroup := CreateTestGroup(t, prefix+"_far")
	nearGroup := CreateTestGroup(t, prefix+"_near")
	// Make the FAR group sort first, so lowest-id would have picked the wrong one.
	if nearGroup < farGroup {
		farGroup, nearGroup = nearGroup, farGroup
	}
	db.Exec("UPDATE `groups` SET lat = 55.9533, lng = -3.1883, polyindex = ST_GeomFromText('POINT(-3.1883 55.9533)', ?) WHERE id = ?", utils.SRID, farGroup)
	db.Exec("UPDATE `groups` SET lat = 51.5, lng = -0.1, polyindex = ST_GeomFromText('POINT(-0.1 51.5)', ?) WHERE id = ?", utils.SRID, nearGroup)

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, farGroup, "Member")
	msgID := CreateTestMessage(t, posterID, farGroup, "OFFER: nearest-group test item", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, rippled_in) VALUES (?, ?, 'Approved', NOW(), 1)",
		msgID, nearGroup)

	// The replier lives beside the NEAR group and is a member of neither.
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.mylocation.lat', 51.5, '$.mylocation.lng', -0.1) WHERE id = ?", replierID)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)
	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the reply is accepted")

	var inNear, inFar int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, nearGroup).Scan(&inNear)
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", replierID, farGroup).Scan(&inFar)
	assert.Equal(t, 1, inNear, "the replier is joined to the group nearest them")
	assert.Equal(t, 0, inFar, "not the lower-numbered group on the other side of the country")
}
