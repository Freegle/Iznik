package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The "Replies by <user>" ModTools modal lists the posts a member has expressed interest in.
// It must show one row per post. Rippling gives a post an extra messages_groups row per
// receiving group, each stamped with its own arrival, so a query that selects mg.arrival
// fans out to one row per distinct ripple time - the post appeared up to four times in the
// modal, and the 100-row cap was eaten by the copies.
func TestUserReplies_RippledPostAppearsOnce(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyRipple")

	originGroup := CreateTestGroup(t, prefix+"origin")
	rippleGroup1 := CreateTestGroup(t, prefix+"rip1")
	rippleGroup2 := CreateTestGroup(t, prefix+"rip2")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, modID, originGroup, "Moderator")
	CreateTestMembership(t, replierID, originGroup, "Member")
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, originGroup, "Rippled sofa "+prefix, 55.9533, -3.1883)

	// The ripple lands the post on two more groups, hours apart - exactly what production
	// showed for #121545340 (origin 08:36, then 08:53, 20:38, 08:52 the next day).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 'Approved', 0, 1)", msgID, rippleGroup1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 HOUR), 'Approved', 0, 1)", msgID, rippleGroup2)

	// One reply from the member: a single Interested chat message referencing the post.
	var chatID uint64
	db.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, 'User2User', NOW())",
		replierID, posterID)
	db.Raw("SELECT id FROM chat_rooms WHERE user1 = ? AND user2 = ? ORDER BY id DESC LIMIT 1",
		replierID, posterID).Scan(&chatID)
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, type, refmsgid) "+
		"VALUES (?, ?, 'Is this still available?', NOW(), 'Interested', ?)", chatID, replierID, msgID)

	url := fmt.Sprintf("/api/user/%d/replies?jwt=%s", replierID, token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	body := rsp(resp)
	assert.Equal(t, 200, resp.StatusCode, "Response: %s", string(body))

	var replies []map[string]interface{}
	assert.NoError(t, json.Unmarshal(body, &replies))

	matched := []map[string]interface{}{}
	for _, r := range replies {
		if uint64(r["id"].(float64)) == msgID {
			matched = append(matched, r)
		}
	}
	assert.Len(t, matched, 1,
		"a post rippled to 3 groups but replied to once must appear once. Response: %s", string(body))

	if len(matched) == 1 {
		// The date shown is when the post was made, not when the ripple reached the
		// furthest group, so it is the earliest of the four messages_groups rows.
		var originArrival string
		db.Raw("SELECT DATE_FORMAT(MIN(arrival), '%Y-%m-%dT%H:%i:%sZ') FROM messages_groups WHERE msgid = ?",
			msgID).Scan(&originArrival)
		assert.Equal(t, originArrival, matched[0]["arrival"], "the origin arrival is shown, not a ripple stamp")
	}
}

// A post can carry more than one messages_outcomes row (Taken, then Withdrawn, or a repost
// cycle). Joining that table fans out the same way, and the modal must still show the post
// once, labelled with the most recent outcome.
func TestUserReplies_MultipleOutcomesAppearOnceAndShowLatest(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyOutcome")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, replierID, groupID, "Member")
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, groupID, "Twice outcomed table "+prefix, 55.9533, -3.1883)

	db.Exec("INSERT INTO messages_outcomes (msgid, userid, outcome, timestamp) "+
		"VALUES (?, ?, 'Withdrawn', DATE_SUB(NOW(), INTERVAL 2 HOUR))", msgID, posterID)
	db.Exec("INSERT INTO messages_outcomes (msgid, userid, outcome, timestamp) "+
		"VALUES (?, ?, 'Taken', NOW())", msgID, posterID)

	var chatID uint64
	db.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, 'User2User', NOW())",
		replierID, posterID)
	db.Raw("SELECT id FROM chat_rooms WHERE user1 = ? AND user2 = ? ORDER BY id DESC LIMIT 1",
		replierID, posterID).Scan(&chatID)
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, type, refmsgid) "+
		"VALUES (?, ?, 'Can I collect?', NOW(), 'Interested', ?)", chatID, replierID, msgID)

	url := fmt.Sprintf("/api/user/%d/replies?jwt=%s", replierID, token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	body := rsp(resp)
	assert.Equal(t, 200, resp.StatusCode, "Response: %s", string(body))

	var replies []map[string]interface{}
	assert.NoError(t, json.Unmarshal(body, &replies))

	matched := []map[string]interface{}{}
	for _, r := range replies {
		if uint64(r["id"].(float64)) == msgID {
			matched = append(matched, r)
		}
	}
	assert.Len(t, matched, 1, "a post with two outcomes must appear once. Response: %s", string(body))
	if len(matched) == 1 {
		assert.Equal(t, "Taken", matched[0]["outcome"], "the most recent outcome wins")
	}
}
