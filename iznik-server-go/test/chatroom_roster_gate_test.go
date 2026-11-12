package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
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
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)

	// A User2Mod chat: user1 is the member corresponding with the group's mods.
	roomID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")

	body := fmt.Sprintf(`{"id":%d}`, roomID)

	// An unrelated ordinary user (not a participant, not a system mod) is denied.
	attackerID := CreateTestUser(t, prefix+"_attacker", utils.SYSTEMROLE_USER)
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

// postRoster posts to /chatrooms as the given user and returns the status code.
func postRoster(t *testing.T, userID uint64, body string) int {
	_, token := CreateTestSession(t, userID)
	req := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// A moderator's access to a moderator chat is scoped to the chat's own group. A platform moderator
// of group A must not be able to write the roster of group B's mod-team chat or of a member's
// chat with group B's moderators: a roster row for a Mod2Mod chat is what makes its mail reach them.
func TestChatRosterUpdateScopedToChatsOwnGroup(t *testing.T) {
	prefix := uniquePrefix("RosterScope")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	modAID := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_MODERATOR)
	modBID := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_MODERATOR)

	CreateTestMembership(t, memberID, groupB, utils.ROLE_MEMBER)
	CreateTestMembership(t, modAID, groupA, utils.ROLE_MODERATOR)
	CreateTestMembership(t, modBID, groupB, utils.ROLE_MODERATOR)

	mod2modB := CreateTestChatRoom(t, 0, nil, &groupB, "Mod2Mod")
	user2modB := CreateTestChatRoom(t, memberID, nil, &groupB, "User2Mod")

	rosterRows := func(chatID uint64, userID uint64) int64 {
		var n int64
		db.Table("chat_roster").Where("chatid = ? AND userid = ?", chatID, userID).Count(&n)
		return n
	}

	// The moderator of group A is refused on both of group B's chats, and no roster row appears.
	assert.Equal(t, fiber.StatusForbidden, postRoster(t, modAID, fmt.Sprintf(`{"id":%d}`, mod2modB)))
	assert.Equal(t, int64(0), rosterRows(mod2modB, modAID), "roster row forged on another group's mod-team chat")

	assert.Equal(t, fiber.StatusForbidden, postRoster(t, modAID, fmt.Sprintf(`{"id":%d,"status":"Blocked"}`, user2modB)))
	assert.Equal(t, int64(0), rosterRows(user2modB, modAID), "roster row forged on another group's modmail chat")

	// The moderator of group B is allowed on both.
	assert.Equal(t, fiber.StatusOK, postRoster(t, modBID, fmt.Sprintf(`{"id":%d}`, mod2modB)))
	assert.Equal(t, int64(1), rosterRows(mod2modB, modBID))
	assert.Equal(t, fiber.StatusOK, postRoster(t, modBID, fmt.Sprintf(`{"id":%d}`, user2modB)))
}

// A group moderator whose platform role is plain User still moderates their own group, so marking
// a member's chat with that group's moderators read must work for them.
func TestChatRosterUpdateAllowsGroupModeratorWithUserSystemRole(t *testing.T) {
	prefix := uniquePrefix("RosterGroupMod")
	groupID := CreateTestGroup(t, prefix)
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

	CreateTestMembership(t, memberID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)

	chatID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")

	assert.Equal(t, fiber.StatusOK, postRoster(t, modID, fmt.Sprintf(`{"id":%d}`, chatID)))
}
