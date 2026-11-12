package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// ModTools says that when "Moderated members can edit?" is No, moderated members cannot edit.
// A moderated member's edit of their live post is then refused and the post is unchanged.
func TestPatchMessageRefusedWhenGroupStopsModeratedMembersEditing(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("allowedits_mod")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_MEMBER)
	_, ownerToken := CreateTestSession(t, ownerID)
	db.Exec("UPDATE memberships SET ourPostingStatus = 'MODERATED' WHERE userid = ? AND groupid = ?", ownerID, groupID)
	db.Exec("UPDATE `groups` SET settings = JSON_OBJECT('allowedits', JSON_OBJECT('moderated', 0, 'group', 1)) WHERE id = ?", groupID)

	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Table "+prefix, 55.0, -1.0)
	db.Exec("UPDATE messages SET textbody = 'original text' WHERE id = ?", msgID)

	body := fmt.Sprintf(`{"id":%d,"textbody":"edited text"}`, msgID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode, "the group has switched editing off for moderated members")

	var text string
	db.Raw("SELECT textbody FROM messages WHERE id = ?", msgID).Scan(&text)
	assert.Equal(t, "original text", text, "the post is unchanged")
}
