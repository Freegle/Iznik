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
// a logged-in user who is neither a participant of a User2Mod/Mod2Mod chat nor a moderator of its
// community must not be able to read or forge its roster via POST /chatrooms. The room's own participant is
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

func postRosterUpdate(t *testing.T, userID, chatID uint64) int {
	_, token := CreateTestSession(t, userID)
	req := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, strings.NewReader(fmt.Sprintf(`{"id":%d}`, chatID)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// A roster update on a User2Mod or Mod2Mod chat is for that community's own active moderators. A
// platform moderator (system role Moderator) of a different community must not read its roster or
// get a roster row in it, and a group moderator whose system role is plain User must be able to
// mark their own community's chat read.
func TestChatRosterUpdateIsScopedToTheRoomsCommunity(t *testing.T) {
	prefix := uniquePrefix("RosterScope")
	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")

	modA := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modA, groupA, utils.ROLE_MODERATOR)

	modB := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modB, groupB, utils.ROLE_MODERATOR)

	memberB := CreateTestUser(t, prefix+"_memberb", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, memberB, groupB, utils.ROLE_MEMBER)

	mod2modB := CreateTestChatRoom(t, 0, nil, &groupB, "Mod2Mod")
	user2modB := CreateTestChatRoom(t, memberB, nil, &groupB, "User2Mod")

	for _, chatID := range []uint64{mod2modB, user2modB} {
		assert.Equal(t, fiber.StatusForbidden, postRosterUpdate(t, modA, chatID),
			"a moderator of another community must be refused")

		var rows int64
		database.DBConn.Table("chat_roster").Where("chatid = ? AND userid = ?", chatID, modA).Count(&rows)
		assert.Equal(t, int64(0), rows, "a refused roster update must not create a roster row")

		assert.Equal(t, fiber.StatusOK, postRosterUpdate(t, modB, chatID),
			"a moderator of the room's own community may mark it read")
	}
}

// A member who belongs to two communities has a modmail chat with one of them. A moderator of only
// the other community shares that member but not the room's community, so they must not read the
// roster, mark the chat read or signal typing in it.
func TestChatPresenceRefusedForModeratorOfAnotherCommunityTheMemberIsIn(t *testing.T) {
	prefix := uniquePrefix("RosterShared")
	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")

	member := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, member, groupA, utils.ROLE_MEMBER)
	CreateTestMembership(t, member, groupB, utils.ROLE_MEMBER)

	modB := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modB, groupB, utils.ROLE_MODERATOR)

	chatID := CreateTestChatRoom(t, member, nil, &groupA, "User2Mod")

	assert.Equal(t, fiber.StatusForbidden, postRosterUpdate(t, modB, chatID),
		"a moderator of a different community must be refused a roster update")

	var rows int64
	database.DBConn.Table("chat_roster").Where("chatid = ? AND userid = ?", chatID, modB).Count(&rows)
	assert.Equal(t, int64(0), rows, "a refused roster update must not create a roster row")

	_, token := CreateTestSession(t, modB)
	req := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, strings.NewReader(fmt.Sprintf(`{"id":%d,"action":"Typing"}`, chatID)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode, "a moderator of a different community must be refused typing")
}
