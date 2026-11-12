package test

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A member's chat with the moderators of group B is for B's moderators (and Support and Admin).
// A moderator of group A must not read it just because the member also belongs to A.
func TestUser2ModChatNotVisibleToModeratorOfAnotherSharedGroup(t *testing.T) {
	prefix := uniquePrefix("u2mshared")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	modAID := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_USER)
	modBID := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)

	CreateTestMembership(t, memberID, groupA, utils.ROLE_MEMBER)
	CreateTestMembership(t, memberID, groupB, utils.ROLE_MEMBER)
	CreateTestMembership(t, modAID, groupA, utils.ROLE_MODERATOR)
	CreateTestMembership(t, modBID, groupB, utils.ROLE_MODERATOR)

	chatID := CreateTestChatRoom(t, memberID, nil, &groupB, "User2Mod")
	CreateTestChatMessage(t, chatID, memberID, "Private message for group B volunteers")

	get := func(userID uint64, suffix string) int {
		_, token := CreateTestSession(t, userID)
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d%s?jwt=%s", chatID, suffix, token), nil)
		resp, _ := getApp().Test(req, -1)
		return resp.StatusCode
	}

	// The moderator of group B can read it.
	assert.Equal(t, http.StatusOK, get(modBID, ""))
	assert.Equal(t, http.StatusOK, get(modBID, "/message"))

	// The moderator of group A, who shares only the member with it, cannot.
	assert.Equal(t, http.StatusNotFound, get(modAID, ""), "chat room visible to a moderator of another group")
	assert.Equal(t, http.StatusNotFound, get(modAID, "/message"), "chat messages visible to a moderator of another group")

	// The same holds when the member's membership of group A is still pending.
	db.Exec("UPDATE memberships SET collection = 'Pending' WHERE userid = ? AND groupid = ?", memberID, groupA)
	assert.Equal(t, http.StatusNotFound, get(modAID, ""), "chat room visible via a pending membership")
	assert.Equal(t, http.StatusNotFound, get(modAID, "/message"), "chat messages visible via a pending membership")
}
