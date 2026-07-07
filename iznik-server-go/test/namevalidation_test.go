package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestSuspiciousNameRewrittenInUserFetch covers the display-time rewrite
// for non-moderator users with misleading display names (Discourse #9587).
func TestSuspiciousNameRewrittenInUserFetch(t *testing.T) {
	prefix := uniquePrefix("suspname")
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn
	db.Exec("UPDATE users SET fullname = ? WHERE id = ?",
		"iLovefreegle Support", userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var got map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&got)

	displayname, _ := got["displayname"].(string)
	assert.NotEqual(t, "iLovefreegle Support", displayname,
		"non-mod user's suspicious fullname must be rewritten on display")
	assert.NotEmpty(t, displayname)
}

// TestSuspiciousNameKeptForModerator verifies that platform moderators are
// exempt — their chosen name is shown as-is, because many volunteers use
// Freegle-themed emails and display names legitimately.
func TestSuspiciousNameKeptForModerator(t *testing.T) {
	prefix := uniquePrefix("suspmod")
	userID := CreateTestUser(t, prefix, "Moderator")

	db := database.DBConn
	db.Exec("UPDATE users SET fullname = ? WHERE id = ?",
		"Freegle Aberdeen Volunteer", userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var got map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&got)

	displayname, _ := got["displayname"].(string)
	assert.Equal(t, "Freegle Aberdeen Volunteer", displayname,
		"Moderator systemrole must be exempt from name rewrite")
}

// TestSuspiciousNameKeptForGroupMod verifies group-level owners/moderators
// are exempt — a user with systemrole User but Owner/Moderator role on any
// group should keep their name.
func TestSuspiciousNameKeptForGroupMod(t *testing.T) {
	prefix := uniquePrefix("suspgrpmod")
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)

	db := database.DBConn
	db.Exec("UPDATE users SET fullname = ? WHERE id = ?",
		"Freegle Aberdeen", userID)
	db.Exec("INSERT INTO memberships (userid, groupid, role, collection) VALUES (?, ?, 'Owner', 'Approved')",
		userID, groupID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+fmt.Sprint(userID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var got map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&got)

	displayname, _ := got["displayname"].(string)
	assert.Equal(t, "Freegle Aberdeen", displayname,
		"group Owner/Moderator must be exempt from name rewrite")
}
