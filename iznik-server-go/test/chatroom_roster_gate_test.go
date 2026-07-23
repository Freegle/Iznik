package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// TestChatRosterUpdateRequiresParticipantOrMod covers the fix for the mod-chat roster IDOR (H2):
// a logged-in user who is neither a participant of a User2Mod/Mod2Mod chat nor a system moderator
// must not be able to read or forge its roster via POST /chatrooms. The room's own participant is
// still allowed.
func TestChatRosterUpdateRequiresParticipantOrMod(t *testing.T) {
	prefix := uniquePrefix("RosterGate")
	groupID := CreateTestGroup(t, prefix)
	memberID := CreateTestUser(t, prefix+"_member", "User")

	// A User2Mod chat: user1 is the member corresponding with the group's mods.
	roomID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")

	body := fmt.Sprintf(`{"id":%d}`, roomID)

	// An unrelated ordinary user (not a participant, not a system mod) is denied.
	attackerID := CreateTestUser(t, prefix+"_attacker", "User")
	_, attackerToken := CreateTestSession(t, attackerID)
	req := httptest.NewRequest("POST", "/api/chatrooms?jwt="+attackerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)

	// The room's participant is allowed.
	_, memberToken := CreateTestSession(t, memberID)
	req2 := httptest.NewRequest("POST", "/api/chatrooms?jwt="+memberToken, strings.NewReader(body))
	req2.Header.Set("Content-Type", "application/json")
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, fiber.StatusOK, resp2.StatusCode)
}
