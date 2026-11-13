package test

import (
	"net/http"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A moderator may act only on chat messages in their review scope: a chat for their own group, or between
// members where the recipient is on their group. A moderator of group A must not be able to approve, reject,
// hold, release or redact a message in a chat between two members of group B, or to switch off chat
// moderation for the sender.
func TestChatMessageModerationRefusedOutsideModeratorsScope(t *testing.T) {
	db := database.DBConn

	// reviewMessageOfGroupB makes a message waiting for review in a chat between two members who are on
	// group B only, and returns the message id, its sender and a moderator of group B (who may hold it).
	reviewMessageOfGroupB := func(t *testing.T, name string) (msgID uint64, senderID uint64, modBID uint64) {
		prefix := uniquePrefix("cmscope_" + name)
		groupB := CreateTestGroup(t, prefix+"_b")
		senderID = CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
		recipientID := CreateTestUser(t, prefix+"_recipient", utils.SYSTEMROLE_USER)
		modBID = CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)
		CreateTestMembership(t, senderID, groupB, utils.ROLE_MEMBER)
		CreateTestMembership(t, recipientID, groupB, utils.ROLE_MEMBER)
		CreateTestMembership(t, modBID, groupB, utils.ROLE_MODERATOR)

		chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Write to me at someone@example.com', NOW(), 1, 1)", chatID, senderID)
		db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&msgID)
		if msgID == 0 {
			t.Fatal("Failed to create the review message")
		}

		return msgID, senderID, modBID
	}

	// moderatorOfGroupA is a moderator of a group the chat has nothing to do with.
	moderatorOfGroupA := func(t *testing.T, name string) string {
		prefix := uniquePrefix("cmscope_" + name)
		groupA := CreateTestGroup(t, prefix+"_a")
		modAID := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_USER)
		CreateTestMembership(t, modAID, groupA, utils.ROLE_MODERATOR)
		_, token := CreateTestSession(t, modAID)

		return token
	}

	type messageState struct {
		Reviewrequired int
		Reviewrejected int
		Message        string
	}
	stateOf := func(msgID uint64) (s messageState) {
		db.Raw("SELECT reviewrequired, reviewrejected, message FROM chat_messages WHERE id = ?", msgID).Scan(&s)

		return s
	}
	heldCount := func(msgID uint64) (n int64) {
		db.Raw("SELECT COUNT(*) FROM chat_messages_held WHERE msgid = ?", msgID).Scan(&n)

		return n
	}
	chatmodstatus := func(userID uint64) (s string) {
		db.Raw("SELECT COALESCE(chatmodstatus, '') FROM users WHERE id = ?", userID).Scan(&s)

		return s
	}

	for _, action := range []string{"Approve", "ApproveAllFuture", "Reject", "Hold", "Release", "Redact"} {
		action := action
		t.Run(action, func(t *testing.T) {
			msgID, senderID, modBID := reviewMessageOfGroupB(t, action)
			modAToken := moderatorOfGroupA(t, action)

			// Release needs a hold to remove, and the holder is group B's moderator.
			if action == "Release" {
				db.Exec("INSERT INTO chat_messages_held (msgid, userid) VALUES (?, ?)", msgID, modBID)
			}
			before := stateOf(msgID)
			statusBefore := chatmodstatus(senderID)

			resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{
				"id":     msgID,
				"action": action,
			}, modAToken)

			assert.Contains(t, []int{http.StatusForbidden, http.StatusNotFound}, resp.StatusCode,
				"a moderator of another group may not %s a message in a chat outside their review scope", action)
			assert.Equal(t, before, stateOf(msgID), "the message must be unchanged")
			assert.Equal(t, statusBefore, chatmodstatus(senderID), "the sender's chat moderation status must be unchanged")

			if action == "Release" {
				assert.EqualValues(t, 1, heldCount(msgID), "the hold must stay")
			} else {
				assert.EqualValues(t, 0, heldCount(msgID), "no hold may be taken")
			}
		})
	}
}
