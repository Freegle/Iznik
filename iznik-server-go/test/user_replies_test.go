package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A post can carry more than one messages_outcomes row (Taken, then Withdrawn, or a repost
// cycle). Joining that table fans out the same way, and the modal must still show the post
// once, labelled with the most recent outcome.
func TestUserReplies_MultipleOutcomesAppearOnceAndShowLatest(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("replyOutcome")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	PromoteTestUserToModerator(t, modID)
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, "Twice outcomed table "+prefix, 55.9533, -3.1883)

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
