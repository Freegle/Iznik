package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestListChats_HeldReplyNotCountedOrPreviewed verifies that a rippling-held reply
// (rippling_held_replies, status='held') is invisible to the RECIPIENT (the poster) in the
// chat LIST: it must not inflate the room's unseen count and must not appear as the room's
// snippet/preview. The message-list fetch (FetchChatMessages) already hides held replies from
// the poster; the sibling count/snippet queries in listChats did not, so the poster saw an
// unread badge and preview text for a reply they are not yet allowed to see. Releasing the
// hold (status='released') reveals it in both surfaces, matching normal delivery.
func TestListChats_HeldReplyNotCountedOrPreviewed(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("listheld")

	// rippling_held_replies ships with the reach engine; stand it up so this test is self-sufficient.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL,
		chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL,
		replieruserid BIGINT UNSIGNED NOT NULL,
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

	// The original post being replied to (FK target for rippling_held_replies.msgid).
	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: held reply list test", 51.5, -0.1)

	// Replier initiated the chat with the poster; user1=replier, user2=poster (the recipient).
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	// A fully-processed reply FROM the replier (reviewrequired=0, processingsuccessful=1) — it
	// passes the normal "deliverable" predicate, so only the rippling gate can hide it.
	const replyText = "I would love this please, can I collect?"
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, 'Default', NOW(), ?, 0, 0, 0, 1)",
		chatID, replierID, replyText, postMsgID,
	)
	var replyMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&replyMsgID)
	if replyMsgID == 0 {
		t.Fatal("failed to create the reply chat message")
	}

	// Hold the reply — the replier is outside the post's current reach.
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status) "+
		"VALUES (?, ?, ?, ?, 'held')", chatID, replyMsgID, postMsgID, replierID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", replyMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", replyMsgID)

	_, posterToken := CreateTestSession(t, posterID)

	// Fetch the poster's chat list and return this room's unseen count + snippet.
	roomState := func() (uint64, string) {
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat?includeClosed=true&chattypes[]=User2User&jwt=%s", posterToken), nil)
		resp, err := getApp().Test(req, -1)
		if err != nil {
			t.Fatalf("chat list request failed: %v", err)
		}
		assert.Equal(t, 200, resp.StatusCode)
		var rooms []chat.ChatRoomListEntry
		if err := json.Unmarshal(rsp(resp), &rooms); err != nil {
			t.Fatalf("failed to decode chat list: %v", err)
		}
		for _, r := range rooms {
			if r.ID == chatID {
				return r.Unseen, r.Snippet
			}
		}
		t.Fatalf("chat room %d not found in poster's list", chatID)
		return 0, ""
	}

	// While held: the poster must NOT see the reply as unread, nor as the room preview.
	unseen, snippet := roomState()
	assert.Equal(t, uint64(0), unseen, "held reply must not count towards the poster's unseen badge")
	assert.NotContains(t, snippet, replyText, "held reply text must not appear as the room snippet for the poster")

	// Releasing the hold makes the reply deliverable — it now counts and previews normally.
	db.Exec("UPDATE rippling_held_replies SET status = 'released', releasedat = NOW() WHERE chatmsgid = ?", replyMsgID)
	unseen, snippet = roomState()
	assert.Equal(t, uint64(1), unseen, "released reply must count towards the poster's unseen badge")
	assert.Contains(t, snippet, replyText, "released reply must appear as the room snippet for the poster")
}
