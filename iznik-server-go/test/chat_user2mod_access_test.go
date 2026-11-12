package test

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A member's chat with the moderators of group B is visible to a moderator of any group the member belongs
// to, which quicker chat review needs. A moderator with no tie to the member or to group B cannot read it.
func TestUser2ModChatVisibleToModeratorOfAnotherSharedGroup(t *testing.T) {
	prefix := uniquePrefix("u2mshared")

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	groupC := CreateTestGroup(t, prefix+"_c")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	modAID := CreateTestUser(t, prefix+"_moda", utils.SYSTEMROLE_USER)
	modBID := CreateTestUser(t, prefix+"_modb", utils.SYSTEMROLE_USER)
	modCID := CreateTestUser(t, prefix+"_modc", utils.SYSTEMROLE_USER)

	CreateTestMembership(t, memberID, groupA, utils.ROLE_MEMBER)
	CreateTestMembership(t, memberID, groupB, utils.ROLE_MEMBER)
	CreateTestMembership(t, modAID, groupA, utils.ROLE_MODERATOR)
	CreateTestMembership(t, modBID, groupB, utils.ROLE_MODERATOR)
	CreateTestMembership(t, modCID, groupC, utils.ROLE_MODERATOR)

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

	// So can the moderator of group A, who shares the member with it.
	assert.Equal(t, http.StatusOK, get(modAID, ""))
	assert.Equal(t, http.StatusOK, get(modAID, "/message"))

	// A moderator of a group the member is not in cannot.
	assert.Equal(t, http.StatusNotFound, get(modCID, ""), "chat room visible to an unconnected moderator")
	assert.Equal(t, http.StatusNotFound, get(modCID, "/message"), "chat messages visible to an unconnected moderator")
}
