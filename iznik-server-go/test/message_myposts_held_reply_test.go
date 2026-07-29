package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// fetchMessage fetches GET /api/message/:id as the given token and decodes it. This is the
// endpoint that backs the "My Posts" page - its `replies` + `replycount` list who has
// expressed Interest in the poster's own post.
func fetchMessage(t *testing.T, token string, msgID uint64) message.Message {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil)
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var m message.Message
	require.NoError(t, json.Unmarshal(rsp(resp), &m))
	return m
}

// TestMessageReplies_HeldReplyHiddenFromPoster verifies that a rippling-held reply
// (rippling_held_replies, status='held') to the poster's own post does NOT appear in the
// message `replies` list or inflate `replycount` on GET /api/message/:id - the endpoint that
// backs "My Posts". This own-posts reply path had no held-reply gate, so the poster saw the
// held reply there (replier name + count) while every delivery channel (chat list, poster
// email, push - and the chat-list badge/snippet/roster fixed by PR #927) correctly withheld
// it. The reply must stay hidden while held and reappear once the hold is released.
func TestMessageReplies_HeldReplyHiddenFromPoster(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mypostsheld")

	// rippling_held_replies ships with the reach engine; stand it up so this test is self-sufficient.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL,
		chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL,
		replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	// The poster's own post (FK target for rippling_held_replies.msgid and refmsgid).
	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: myposts held reply test", 51.5, -0.1)

	// Replier expressed Interest via a chat room. type='Interested' + refmsgid = this post is
	// exactly what the message `replies` query selects.
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	const replyText = "Interested please, can I collect?"
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, 'Interested', NOW(), ?, 0, 0, 0, 1)",
		chatID, replierID, replyText, postMsgID,
	)
	var replyMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&replyMsgID)
	require.NotZero(t, replyMsgID, "failed to create the Interested reply chat message")
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", replyMsgID)

	_, posterToken := CreateTestSession(t, posterID)

	// Baseline: with no hold, the poster sees the Interested reply in My Posts.
	m := fetchMessage(t, posterToken, postMsgID)
	require.Equal(t, 1, m.Replycount, "sanity: an un-held Interested reply should show in My Posts")
	require.Len(t, m.MessageReply, 1)

	// Hold the reply - the replier is outside the post's current reach.
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status) "+
		"VALUES (?, ?, ?, ?, 'held')", chatID, replyMsgID, postMsgID, replierID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", replyMsgID)

	// While held: the poster must NOT see it in My Posts - no replier, no count.
	m = fetchMessage(t, posterToken, postMsgID)
	assert.Equal(t, 0, m.Replycount, "held reply must not be counted in the My Posts replycount")
	assert.Empty(t, m.MessageReply, "held reply must not appear in the My Posts replies list")

	// Releasing the hold makes the reply deliverable - it reappears in My Posts with the
	// replier's identity (the viewer is the poster of this post).
	db.Exec("UPDATE rippling_held_replies SET status = 'released', releasedat = NOW() WHERE chatmsgid = ?", replyMsgID)
	m = fetchMessage(t, posterToken, postMsgID)
	if assert.Equal(t, 1, m.Replycount, "released reply must be counted again") && assert.Len(t, m.MessageReply, 1) {
		assert.Equal(t, replierID, m.MessageReply[0].Userid, "poster sees the replier's identity on their own post")
	}
}
