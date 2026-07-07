package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// These tests cover paths missed by team_test.go: the unauthorized gate on
// POST/PATCH/DELETE, the 400 missing-field branches, getUserProfile (all three
// return paths), the name-based GetTeam lookup, and the populated getVolunteers
// path. See `go tool cover -func` — each function below climbs by >=15pp.

func TestTeamPostUnauthorized(t *testing.T) {
	// No JWT — auth gate returns 401 before hasTeamsPermission runs.
	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/team", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestTeamPostMissingName(t *testing.T) {
	// Admin but neither body, form nor query carries `name` — 400.
	prefix := uniquePrefix("TeamPostMiss")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/team?jwt="+token, nil))
	assert.Equal(t, 400, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	assert.Equal(t, float64(2), body["ret"])
}

func TestTeamPatchUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("PATCH", "/api/team", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestTeamPatchForbidden(t *testing.T) {
	// Regular user (systemrole=User) hits the 403 branch.
	prefix := uniquePrefix("TeamPatchForbid")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("PATCH",
		"/api/team?jwt="+token+"&id=1", nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestTeamPatchMissingID(t *testing.T) {
	// Admin but no id — the `if req.ID == 0` branch returns 400.
	prefix := uniquePrefix("TeamPatchNoID")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("PATCH", "/api/team?jwt="+token, nil))
	assert.Equal(t, 400, resp.StatusCode)
}

func TestTeamPatchAddMissingUserid(t *testing.T) {
	// Action=Add with no userid — nested 400.
	prefix := uniquePrefix("TeamPatchAddNU")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	body := `{"id":1,"action":"Add"}`
	req := httptest.NewRequest("PATCH", "/api/team?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestTeamPatchRemoveMissingUserid(t *testing.T) {
	// Action=Remove with no userid — nested 400.
	prefix := uniquePrefix("TeamPatchRmNU")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	body := `{"id":1,"action":"Remove"}`
	req := httptest.NewRequest("PATCH", "/api/team?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestTeamPatchUpdateAttributes(t *testing.T) {
	// Default switch branch — hits all four UPDATE statements (name, description,
	// email, wikiurl). This is the PatchTeam path with no `action` key.
	prefix := uniquePrefix("TeamPatchUpd")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	origName := prefix + "_orig"
	db.Exec("INSERT INTO teams (name) VALUES (?)", origName)
	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = ?", origName).Scan(&teamID)
	assert.NotZero(t, teamID)

	newName := prefix + "_new"
	body := fmt.Sprintf(
		`{"id":%d,"name":"%s","description":"new-desc","email":"team@example.test","wikiurl":"https://wiki.example.test"}`,
		teamID, newName)
	req := httptest.NewRequest("PATCH", "/api/team?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var got struct {
		Name        string
		Description *string
		Email       *string
		Wikiurl     *string
	}
	db.Raw("SELECT name, description, email, wikiurl FROM teams WHERE id = ?", teamID).Scan(&got)
	assert.Equal(t, newName, got.Name)
	assert.NotNil(t, got.Description)
	assert.Equal(t, "new-desc", *got.Description)
	assert.NotNil(t, got.Email)
	assert.Equal(t, "team@example.test", *got.Email)
	assert.NotNil(t, got.Wikiurl)
	assert.Equal(t, "https://wiki.example.test", *got.Wikiurl)
}

func TestTeamDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/team", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestTeamDeleteMissingID(t *testing.T) {
	prefix := uniquePrefix("TeamDelNoID")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/team?jwt="+token, nil))
	assert.Equal(t, 400, resp.StatusCode)
}

func TestTeamGetByName(t *testing.T) {
	// name= lookup resolves to id and falls through to the single-team branch.
	prefix := uniquePrefix("TeamByName")
	teamName := prefix + "_named"
	database.DBConn.Exec("INSERT INTO teams (name) VALUES (?)", teamName)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/team?name="+teamName, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	assert.Equal(t, float64(0), body["ret"])
	team, ok := body["team"].(map[string]interface{})
	assert.True(t, ok)
	assert.Equal(t, teamName, team["name"])
}

func TestTeamGetByNameNotFound(t *testing.T) {
	// name= for a non-existent team — ret:2 (not a 404; GetTeam treats
	// name-lookup misses as search misses).
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/team?name=__team_does_not_exist__", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	assert.Equal(t, float64(2), body["ret"])
}

func TestTeamGetByIDNotFound(t *testing.T) {
	// Numeric id that doesn't match any row — ret:2 (GetTeam's `if t.ID == 0`
	// branch after scanning).
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/team?id=999999999", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	assert.Equal(t, float64(2), body["ret"])
}

func TestTeamGetMembersImageOverride(t *testing.T) {
	// imageoverride on teams_members sends getUserProfile down the early-return
	// path — profile.url/turl must be the override verbatim, default=false.
	prefix := uniquePrefix("TeamImgOvr")
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn
	teamName := prefix + "_team"
	db.Exec("INSERT INTO teams (name) VALUES (?)", teamName)
	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = ?", teamName).Scan(&teamID)

	override := "https://example.test/custom.jpg"
	db.Exec("INSERT INTO teams_members (userid, teamid, imageoverride) VALUES (?, ?, ?)",
		userID, teamID, override)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/team?id=%d", teamID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	team := body["team"].(map[string]interface{})
	members := team["members"].([]interface{})
	assert.NotEmpty(t, members)
	profile := members[0].(map[string]interface{})["profile"].(map[string]interface{})
	assert.Equal(t, override, profile["url"])
	assert.Equal(t, override, profile["turl"])
	assert.Equal(t, false, profile["default"])
}

func TestTeamGetMembersUsersImage(t *testing.T) {
	// No imageoverride but a users_images row — getUserProfile returns the
	// CDN uimg_/tuimg_ URLs with default=false.
	prefix := uniquePrefix("TeamUserImg")
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn
	teamName := prefix + "_team"
	db.Exec("INSERT INTO teams (name) VALUES (?)", teamName)
	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = ?", teamName).Scan(&teamID)

	db.Exec("INSERT INTO teams_members (userid, teamid) VALUES (?, ?)", userID, teamID)
	db.Exec("INSERT INTO users_images (userid, url, contenttype) VALUES (?, 'https://example.test/u.jpg', 'image/jpeg')", userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/team?id=%d", teamID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	team := body["team"].(map[string]interface{})
	members := team["members"].([]interface{})
	assert.NotEmpty(t, members)
	profile := members[0].(map[string]interface{})["profile"].(map[string]interface{})
	assert.Contains(t, profile["url"], "/uimg_")
	assert.Contains(t, profile["turl"], "/tuimg_")
	assert.Equal(t, false, profile["default"])
}

func TestTeamGetMembersGravatarDefault(t *testing.T) {
	// No imageoverride, no users_images — getUserProfile returns the gravatar
	// default URL with default=true.
	prefix := uniquePrefix("TeamGravatar")
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn
	teamName := prefix + "_team"
	db.Exec("INSERT INTO teams (name) VALUES (?)", teamName)
	var teamID uint64
	db.Raw("SELECT id FROM teams WHERE name = ?", teamName).Scan(&teamID)
	db.Exec("INSERT INTO teams_members (userid, teamid) VALUES (?, ?)", userID, teamID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/team?id=%d", teamID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	team := body["team"].(map[string]interface{})
	members := team["members"].([]interface{})
	assert.NotEmpty(t, members)
	profile := members[0].(map[string]interface{})["profile"].(map[string]interface{})
	assert.Contains(t, profile["url"], "gravatar.com")
	assert.Equal(t, true, profile["default"])
}

func TestTeamGetVolunteersWithMember(t *testing.T) {
	// Populate the Volunteers pseudo-team: a moderator membership on a
	// Freegle-type group whose user has settings.showmod=true. Covers the
	// loop body and displayname/profile construction in getVolunteers.
	prefix := uniquePrefix("TeamVols")
	groupID := CreateTestGroup(t, prefix)

	db := database.DBConn
	// Create a user with showmod=true directly so the settings string is exact.
	fullname := "Vol " + prefix
	db.Exec("INSERT INTO users (firstname, lastname, fullname, systemrole, settings) "+
		"VALUES ('Vol', ?, ?, 'User', '{\"showmod\":true}')", prefix, fullname)
	var userID uint64
	db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", fullname).Scan(&userID)
	assert.NotZero(t, userID)

	CreateTestMembership(t, userID, groupID, "Moderator")

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/team?name=Volunteers", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	team := body["team"].(map[string]interface{})
	members, ok := team["members"].([]interface{})
	assert.True(t, ok)

	found := false
	for _, m := range members {
		entry := m.(map[string]interface{})
		if uint64(entry["userid"].(float64)) == userID {
			found = true
			assert.Equal(t, fullname, entry["displayname"])
			_, pok := entry["profile"].(map[string]interface{})
			assert.True(t, pok, "profile must be an object")
			break
		}
	}
	assert.True(t, found, "our opt-in moderator must appear in Volunteers")
}
