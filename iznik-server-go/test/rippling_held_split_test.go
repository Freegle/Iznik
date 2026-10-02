package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The sysadmin "% of replies held (waiting for reach)" figure lumps together two situations
// that cost the offerer very different things. Holding the ONLY reply a post has leaves them
// looking at silence. Holding a LATER one merely defers a choice they can already make.
//
// Since first replies started going through, the held count should be almost entirely
// "additional"; a stubborn "first" count means first replies are still being held up. The
// dashboard can only show that if the endpoint splits them.
//
// A small square around (51.5, -0.1) in EPSG:3857, used only for the outer_bound
// envelope - the analytics under test read scalar columns, never a grid.
const heldSplitTick = "POLYGON((-11150 6712000,-11050 6712000,-11050 6712100,-11150 6712100,-11150 6712000))"

// seedHeldSplitPost creates a rippled-out Offer inside the analytics window and returns its id.
func seedHeldSplitPost(t *testing.T, posterID, groupID uint64, subject string) uint64 {
	t.Helper()
	db := database.DBConn

	msgID := CreateTestMessage(t, posterID, groupID, subject, 51.5, -0.1)

	// The analytics query only counts holds on posts that actually rippled out.
	db.Exec("UPDATE messages_groups SET rippled_in = 1, deleted = 0 WHERE msgid = ?", msgID)

	// total_freeglers must be > 0 to join, and < 1700 to fall in the 'rural' stratum the
	// test asks for — keeping the fixture away from whatever else lives in the window.
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	res := db.Exec("INSERT INTO rippling_reach "+
		"(msgid, lat, lng, outer_bound, arrival, mode, tick, total_ticks, total_freeglers, "+
		" max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "+
		"VALUES (?, 51.5, -0.1, ST_Envelope(ST_GeomFromText(?, 3857)), "+
		" NOW(), 'drive', 1, 3, 100, 30, NULL, NOW(), 'expanding', NOW(), NOW())",
		msgID, heldSplitTick)
	if res.Error != nil {
		t.Fatalf("could not seed reach: %v", res.Error)
	}
	return msgID
}

// replyInRoom inserts an 'Interested' reply on msgID from userID, offset minutes before now
// (0 = now), and returns the chat message id. Every step is checked: a silently failed fixture
// shows up as an unexplained zero in the KPI, which says nothing about what actually broke.
func replyInRoom(t *testing.T, chatID, msgID, userID uint64, minutesAgo int) uint64 {
	t.Helper()
	db := database.DBConn

	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Can I collect this please?', 'Interested', NOW() - INTERVAL ? MINUTE, ?, 0, 0, 0, 1)",
		chatID, userID, minutesAgo, msgID,
	)
	if res.Error != nil {
		t.Fatalf("could not create reply chat message: %v", res.Error)
	}
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)
	if chatMsgID == 0 {
		t.Fatal("reply chat message created but id not found")
	}
	return chatMsgID
}

// heldMinutesAgo backdates the held reply. The analytics window ends at time.Now() formatted
// to WHOLE SECONDS and the bound is exclusive, so a fixture stamped in the same second as the
// request falls outside it and the KPI reads zero — indistinguishable from "nothing held".
// Five minutes is also closer to life: a hold is never simultaneous with someone opening the
// dashboard.
const heldMinutesAgo = 5

// holdReply files a held reply from replierID against msgID and returns the chat message id.
// Pass an existing chatID to reuse a room — chat_rooms is unique on (user1, user2, chattype),
// so a second CreateTestChatRoom for the same pair fails.
func holdReply(t *testing.T, chatID, msgID, replierID uint64) uint64 {
	t.Helper()
	db := database.DBConn

	chatMsgID := replyInRoom(t, chatID, msgID, replierID, heldMinutesAgo)

	res := db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status, created_at) "+
		"VALUES (?, ?, ?, ?, 'held', NOW() - INTERVAL ? MINUTE)",
		chatID, chatMsgID, msgID, replierID, heldMinutesAgo)
	if res.Error != nil {
		t.Fatalf("could not file the held reply: %v", res.Error)
	}

	// Prove the fixture is visible to the analytics query's own predicates — INCLUDING the
	// window — before trusting any number that comes back from it. Leaving the window out
	// of this check is what let a windowing mismatch masquerade as a zero KPI.
	var visible int
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies hr "+
		"JOIN rippling_reach rr ON rr.msgid = hr.msgid AND rr.total_freeglers > 0 AND rr.total_freeglers < 1700 "+
		"JOIN messages m ON m.id = hr.msgid AND m.type = 'Offer' "+
		"WHERE hr.chatmsgid = ? AND hr.created_at >= NOW() - INTERVAL 30 DAY AND hr.created_at < NOW() "+
		"AND EXISTS(SELECT 1 FROM messages_groups mgr "+
		"WHERE mgr.msgid = hr.msgid AND mgr.rippled_in = 1 AND mgr.deleted = 0)", chatMsgID).Scan(&visible)
	if visible != 1 {
		t.Fatalf("held reply %d is not visible to the analytics predicates (got %d) — fixture setup is wrong, "+
			"so any KPI assertion below would be meaningless", chatMsgID, visible)
	}
	return chatMsgID
}

func TestRipplingAnalytics_HeldRepliesSplitFirstVsAdditional(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("heldsplit")

	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierA := CreateTestUser(t, prefix+"_replierA", "User")
	replierB := CreateTestUser(t, prefix+"_replierB", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	for _, u := range []uint64{posterID, replierA, replierB, otherID} {
		CreateTestMembership(t, u, groupID, "Member")
	}

	// Post 1: its held reply is the only reply on the post — a FIRST reply held.
	lonely := seedHeldSplitPost(t, posterID, groupID, "OFFER: lonely held reply")
	roomA := CreateTestChatRoom(t, replierA, &posterID, nil, "User2User")
	lonelyMsg := holdReply(t, roomA, lonely, replierA)

	// Post 2: someone else already replied an hour earlier — an ADDITIONAL reply held.
	crowded := seedHeldSplitPost(t, posterID, groupID, "OFFER: crowded held reply")
	roomOther := CreateTestChatRoom(t, otherID, &posterID, nil, "User2User")
	replyInRoom(t, roomOther, crowded, otherID, heldMinutesAgo+60)
	roomB := CreateTestChatRoom(t, replierB, &posterID, nil, "User2User")
	crowdedMsg := holdReply(t, roomB, crowded, replierB)

	defer func() {
		db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid IN (?, ?)", lonelyMsg, crowdedMsg)
		db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", lonely, crowded)
	}()

	url := fmt.Sprintf("/api/rippling/analytics?jwt=%s&stratum=rural", token)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), 30000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json.Unmarshal(rsp(resp), &body)
	s1, ok := body["section1"].(map[string]interface{})
	assert.True(t, ok, "section1 KPI block present")

	total := s1["held_replies"]
	first := s1["held_replies_first"]
	additional := s1["held_replies_additional"]
	assert.NotNil(t, total, "held_replies still reported")
	assert.NotNil(t, first, "held replies split out a first-reply count")
	assert.NotNil(t, additional, "held replies split out an additional-reply count")

	// Other fixtures may share the window, so assert the relationships rather than
	// exact totals: both of ours are counted, each on the correct side, and the two
	// halves always reconstruct the headline number.
	assert.Equal(t, total, first.(float64)+additional.(float64),
		"first + additional must reconstruct the headline held count")
	assert.GreaterOrEqual(t, first.(float64), float64(1), "the lonely hold counts as a first reply")
	assert.GreaterOrEqual(t, additional.(float64), float64(1), "the crowded hold counts as additional")

	// And the percentages must be reported on the same basis as the headline one.
	assert.NotNil(t, s1["held_replies_first_pct"], "first-reply share reported")
	assert.NotNil(t, s1["held_replies_additional_pct"], "additional-reply share reported")
}

// A reply from the SAME member does not make their own held reply "additional" — a person
// replying twice is still a post with only one interested party, which is the thing the
// split is trying to measure.
func TestRipplingAnalytics_OwnEarlierReplyIsNotCompany(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("heldsplitown")

	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	msgID := seedHeldSplitPost(t, posterID, groupID, "OFFER: same replier twice")
	// Both replies are from the SAME member, so they share one chat room.
	room := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	replyInRoom(t, room, msgID, replierID, heldMinutesAgo+60)
	heldMsg := holdReply(t, room, msgID, replierID)

	defer func() {
		db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", heldMsg)
		db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	}()

	url := fmt.Sprintf("/api/rippling/analytics?jwt=%s&stratum=rural", token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 30000)
	var body map[string]interface{}
	json.Unmarshal(rsp(resp), &body)
	s1 := body["section1"].(map[string]interface{})

	assert.GreaterOrEqual(t, s1["held_replies_first"].(float64), float64(1),
		"a member's own earlier reply must not count as another reply")
}
