package test

// Gate tests for chat/chatmessage.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md): chatmessages moderation "mods" gate.
// Approve stays allowed - there is no subject/body field on ModerationRequest to carry a
// composed message, so it is inherently the basic button. ApproveAllFuture, Reject, Hold,
// Release and Redact are refused outright.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so these
// tests never write a real "lockdowns" row and cannot race the other packages' test
// binaries that `go test ./...` runs concurrently against the same test database.

import (
	"net/http"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestLockdownDoesNotRefuseApproveChatMessage(t *testing.T) {
	_, _, _, _, msgID, modToken := setupModerationData(t)
	db := database.DBConn

	restore := modsHeld()
	defer restore()

	resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{
		"id":     msgID,
		"action": "Approve",
	}, modToken)
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	var reviewRequired int
	db.Raw("SELECT reviewrequired FROM chat_messages WHERE id = ?", msgID).Scan(&reviewRequired)
	assert.Equal(t, 0, reviewRequired, "the basic Approve button must still work while mods is held")
}

func TestLockdownRefusesChatMessageModerationActions(t *testing.T) {
	for _, action := range []string{"ApproveAllFuture", "Reject", "Hold", "Release", "Redact"} {
		t.Run(action, func(t *testing.T) {
			_, _, _, _, msgID, modToken := setupModerationData(t)

			restore := modsHeld()
			defer restore()

			resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{
				"id":     msgID,
				"action": action,
			}, modToken)
			assertLockdownRefused(t, resp)
		})
	}
}
