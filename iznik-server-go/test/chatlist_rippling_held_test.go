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

// listRoomForUser fetches a user's User2User chat list and returns the entry for the
// given room (or nil if the room is absent from the list) plus the full list.
func listRoomForUser(t *testing.T, token string, chatID uint64) (*chat.ChatRoomListEntry, []chat.ChatRoomListEntry) {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat?includeClosed=true&chattypes[]=User2User&jwt=%s", token), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("chat list request failed: %v", err)
	}
	assert.Equal(t, 200, resp.StatusCode)
	var rooms []chat.ChatRoomListEntry
	if err := json.Unmarshal(rsp(resp), &rooms); err != nil {
		t.Fatalf("failed to decode chat list: %v", err)
	}
	for i := range rooms {
		if rooms[i].ID == chatID {
			return &rooms[i], rooms
		}
	}
	return nil, rooms
}

// fetchRoomDirect fetches a single room via GET /api/chat/:id and reports whether it
// was returned (200 with a matching id). Deep links and notification-opened chats use
// this path, which must keep working even while the only message is held.
func fetchRoomDirect(t *testing.T, token string, chatID uint64) bool {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d?jwt=%s", chatID, token), nil)
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("single chat request failed: %v", err)
	}
	if resp.StatusCode != 200 {
		return false
	}
	var room chat.ChatRoomListEntry
	if err := json.Unmarshal(rsp(resp), &room); err != nil {
		t.Fatalf("failed to decode single chat: %v", err)
	}
	return room.ID == chatID
}

// TestListChats_HeldReplyOnlyRoomHiddenFromRecipient verifies that a room whose ONLY
// message is a rippling-held reply (rippling_held_replies, status='held') does not appear
// in the RECIPIENT's (poster's) chat list at all. Previously the held reply was hidden from
// the room's unseen count and snippet, but the room itself still showed as a confusing empty
// chat - the poster would open it, see nothing, and reply into the void. Now the room is
// suppressed for the poster while held, still visible to the REPLIER (who sees their own
// message), still fetchable directly (so notifications/deep links work), and reappears for
// the poster once the hold is released.
func TestListChats_HeldReplyOnlyRoomHiddenFromRecipient(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("listheld")

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

	// The original post being replied to (FK target for rippling_held_replies.msgid).
	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: held reply list test", 51.5, -0.1)

	// Replier initiated the chat with the poster; user1=replier, user2=poster (the recipient).
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	// A fully-processed reply FROM the replier (reviewrequired=0, processingsuccessful=1) - it
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

	// Hold the reply - the replier is outside the post's current reach.
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status) "+
		"VALUES (?, ?, ?, ?, 'held')", chatID, replyMsgID, postMsgID, replierID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid = ?", replyMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", replyMsgID)

	_, posterToken := CreateTestSession(t, posterID)
	_, replierToken := CreateTestSession(t, replierID)

	// While held: the poster must NOT see the room in their list - its only message is
	// hidden from them, so it would be an empty, confusing chat.
	posterRoom, _ := listRoomForUser(t, posterToken, chatID)
	assert.Nil(t, posterRoom, "held-only room must not appear in the poster's chat list")

	// While held: the REPLIER still sees the room (they sent the message) with their own text
	// as the snippet - otherwise they'd think their reply vanished.
	replierRoom, _ := listRoomForUser(t, replierToken, chatID)
	if assert.NotNil(t, replierRoom, "held-only room must still appear in the replier's own chat list") {
		assert.Contains(t, replierRoom.Snippet, replyText, "replier should see their own reply as the snippet")
	}

	// While held: the poster can still open the room directly (e.g. from a notification/deep link).
	assert.True(t, fetchRoomDirect(t, posterToken, chatID), "poster must still be able to fetch the held room directly")

	// Releasing the hold makes the reply deliverable - the room now appears for the poster,
	// counts towards their unseen badge, and previews the reply text.
	db.Exec("UPDATE rippling_held_replies SET status = 'released', releasedat = NOW() WHERE chatmsgid = ?", replyMsgID)
	posterRoom, _ = listRoomForUser(t, posterToken, chatID)
	if assert.NotNil(t, posterRoom, "released reply must make the room appear in the poster's list") {
		assert.Equal(t, uint64(1), posterRoom.Unseen, "released reply must count towards the poster's unseen badge")
		assert.Contains(t, posterRoom.Snippet, replyText, "released reply must appear as the room snippet for the poster")
	}
}

// TestListChats_ReviewRequiredOnlyRoomHiddenFromRecipient verifies the same suppression for a
// reply that is held for volunteer review (reviewrequired=1). Until a moderator releases it,
// the recipient should not see an empty room in their list; the sender still sees their own
// pending message, and the room is directly fetchable. Clearing the review flag reveals it.
func TestListChats_ReviewRequiredOnlyRoomHiddenFromRecipient(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("listreview")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: review-held reply list test", 51.5, -0.1)

	// user1=replier, user2=poster (recipient).
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")

	// Reply held for review: reviewrequired=1 means the recipient cannot see it yet.
	const replyText = "Is this still available? I can collect today."
	db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, 'Default', NOW(), ?, 1, 0, 0, 1)",
		chatID, replierID, replyText, postMsgID,
	)
	var replyMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&replyMsgID)
	if replyMsgID == 0 {
		t.Fatal("failed to create the reply chat message")
	}
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", replyMsgID)

	_, posterToken := CreateTestSession(t, posterID)
	_, replierToken := CreateTestSession(t, replierID)

	// While held for review: hidden from the poster's list.
	posterRoom, _ := listRoomForUser(t, posterToken, chatID)
	assert.Nil(t, posterRoom, "review-held-only room must not appear in the poster's chat list")

	// The replier still sees their own pending message.
	replierRoom, _ := listRoomForUser(t, replierToken, chatID)
	if assert.NotNil(t, replierRoom, "review-held-only room must still appear in the replier's own chat list") {
		assert.Contains(t, replierRoom.Snippet, replyText, "replier should see their own pending reply as the snippet")
	}

	// Still directly fetchable by the poster.
	assert.True(t, fetchRoomDirect(t, posterToken, chatID), "poster must still be able to fetch the review-held room directly")

	// Approving the reply (reviewrequired -> 0) reveals it in the poster's list.
	db.Exec("UPDATE chat_messages SET reviewrequired = 0 WHERE id = ?", replyMsgID)
	posterRoom, _ = listRoomForUser(t, posterToken, chatID)
	if assert.NotNil(t, posterRoom, "approved reply must make the room appear in the poster's list") {
		assert.Equal(t, uint64(1), posterRoom.Unseen, "approved reply must count towards the poster's unseen badge")
		assert.Contains(t, posterRoom.Snippet, replyText, "approved reply must appear as the room snippet for the poster")
	}
}
