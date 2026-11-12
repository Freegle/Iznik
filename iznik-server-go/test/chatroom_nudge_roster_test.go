package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A nudge is a chat message from the sender, so the sender is on the roster after it, as after any other message. The
// chat mailer only emails people on the roster, so a sender with no row never hears the reply to their nudge.
func TestNudgeGivesTheSenderARosterRow(t *testing.T) {
	prefix := uniquePrefix("nudge_roster")
	sender := CreateTestUser(t, prefix+"_sender", utils.SYSTEMROLE_USER)
	other := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, sender, &other, nil, "User2User")
	CreateTestChatRoster(t, chatID, other)
	_, token := CreateTestSession(t, sender)
	require.Equal(t, int64(0), rosterRowCount(chatID, sender))

	status, _ := postChatroomAction(t, map[string]interface{}{"id": chatID, "action": "Nudge"}, token)
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, int64(1), rosterRowCount(chatID, sender),
		"a member who nudges must be on the roster to be emailed the reply")
	assert.Equal(t, int64(1), rosterRowCount(chatID, other), "the other side's row is left alone")
}
