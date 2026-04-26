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

// Tests for POST /api/group (CreateGroup). This endpoint was previously untested.
// Coverage targets: unauthorized gate, missing-name validation, permission check
// (plain user vs moderator vs admin), default group type, custom group type,
// owner membership auto-creation, and admin lat/lng setting.

func TestCreateGroupUnauthorized(t *testing.T) {
	// No JWT — should return 401.
	body := `{"name":"ShouldFail"}`
	req := httptest.NewRequest("POST", "/api/group", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCreateGroupMissingName(t *testing.T) {
	// Logged-in moderator but missing name field — 400.
	prefix := uniquePrefix("CGrpNoName")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	body := `{"name":""}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestCreateGroupRegularUserForbidden(t *testing.T) {
	// A plain user with no moderator/owner membership must get 403.
	prefix := uniquePrefix("CGrpRegUser")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"name":"%s_group"}`, prefix)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestCreateGroupByModerator(t *testing.T) {
	// A moderator of any existing group can create a new one.
	prefix := uniquePrefix("CGrpMod")
	existingGroupID := CreateTestGroup(t, prefix+"_existing")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, existingGroupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	newName := prefix + "_newgrp"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	idVal, _ := result["id"].(float64)
	newID := uint64(idVal)
	assert.Greater(t, newID, uint64(0))

	// Verify group exists in DB.
	db := database.DBConn
	var nameshort string
	db.Raw("SELECT nameshort FROM `groups` WHERE id = ?", newID).Scan(&nameshort)
	assert.Equal(t, newName, nameshort)

	// Clean up created group.
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupByOwner(t *testing.T) {
	// An Owner of any existing group can also create a new one.
	prefix := uniquePrefix("CGrpOwner")
	existingGroupID := CreateTestGroup(t, prefix+"_existing")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, existingGroupID, "Owner")
	_, token := CreateTestSession(t, ownerID)

	newName := prefix + "_newgrp"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupByAdmin(t *testing.T) {
	// Admin can create a group without needing existing group membership.
	prefix := uniquePrefix("CGrpAdmin")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	newName := prefix + "_admingrp"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupDefaultType(t *testing.T) {
	// When grouptype is omitted, type should default to "Freegle".
	prefix := uniquePrefix("CGrpDefType")
	existingGroupID := CreateTestGroup(t, prefix+"_base")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, existingGroupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	newName := prefix + "_deftype"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	var groupType string
	db.Raw("SELECT type FROM `groups` WHERE id = ?", newID).Scan(&groupType)
	assert.Equal(t, "Freegle", groupType, "grouptype should default to Freegle")

	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupCustomType(t *testing.T) {
	// A custom grouptype should be stored as-is.
	prefix := uniquePrefix("CGrpCustomType")
	existingGroupID := CreateTestGroup(t, prefix+"_base")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, existingGroupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	newName := prefix + "_customtype"
	body := fmt.Sprintf(`{"name":"%s","grouptype":"Other"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	var groupType string
	db.Raw("SELECT type FROM `groups` WHERE id = ?", newID).Scan(&groupType)
	assert.Equal(t, "Other", groupType, "custom grouptype should be stored")

	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupCreatorBecomesOwner(t *testing.T) {
	// The creator should automatically become an Owner member of the new group.
	prefix := uniquePrefix("CGrpOwnerRole")
	existingGroupID := CreateTestGroup(t, prefix+"_base")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, existingGroupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	newName := prefix + "_ownrole"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	var role string
	db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?", modID, newID).Scan(&role)
	assert.Equal(t, "Owner", role, "creator should have Owner role in the new group")

	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupAdminSetsLatLng(t *testing.T) {
	// An admin can set lat/lng on the new group.
	prefix := uniquePrefix("CGrpLatLng")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	newName := prefix + "_latlng"
	lat := 51.5074
	lng := -0.1278
	body := fmt.Sprintf(`{"name":"%s","lat":%f,"lng":%f}`, newName, lat, lng)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	var storedLat float64
	var storedLng float64
	db.Raw("SELECT COALESCE(lat, 0) FROM `groups` WHERE id = ?", newID).Scan(&storedLat)
	db.Raw("SELECT COALESCE(lng, 0) FROM `groups` WHERE id = ?", newID).Scan(&storedLng)
	assert.InDelta(t, lat, storedLat, 0.001, "lat should be stored")
	assert.InDelta(t, lng, storedLng, 0.001, "lng should be stored")

	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupModeratorLatLngIgnored(t *testing.T) {
	// A non-admin moderator providing lat/lng — the lat/lng should NOT be stored
	// (the handler only applies lat/lng for admins/support).
	prefix := uniquePrefix("CGrpModLatLng")
	existingGroupID := CreateTestGroup(t, prefix+"_base")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, existingGroupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	newName := prefix + "_modlatlng"
	body := fmt.Sprintf(`{"name":"%s","lat":51.5074,"lng":-0.1278}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	// lat/lng should be NULL (not set) for a non-admin creator.
	db := database.DBConn
	var latIsNull int64
	db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ? AND lat IS NULL", newID).Scan(&latIsNull)
	assert.Equal(t, int64(1), latIsNull, "non-admin lat should remain NULL")

	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupV2Path(t *testing.T) {
	// Verify the /apiv2 path also works (same handler registered on both prefixes).
	prefix := uniquePrefix("CGrpV2")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	newName := prefix + "_v2grp"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/apiv2/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupSupportCanCreate(t *testing.T) {
	// Support role (like Admin) can create groups without existing group membership.
	prefix := uniquePrefix("CGrpSupport")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, token := CreateTestSession(t, supportID)

	newName := prefix + "_suppgrp"
	body := fmt.Sprintf(`{"name":"%s"}`, newName)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	idFloat, _ := result["id"].(float64)
	newID := uint64(idFloat)
	assert.Greater(t, newID, uint64(0))

	db := database.DBConn
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}

func TestCreateGroupReturnsNonZeroID(t *testing.T) {
	// Basic smoke test: the returned id must be non-zero.
	prefix := uniquePrefix("CGrpID")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"name":"%s_smoke"}`, prefix)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	id, ok := result["id"].(float64)
	assert.True(t, ok, "response must contain numeric id")
	assert.Greater(t, id, float64(0), "returned id must be > 0")

	db := database.DBConn
	newID := uint64(id)
	db.Exec("DELETE FROM memberships WHERE groupid = ?", newID)
	db.Exec("DELETE FROM `groups` WHERE id = ?", newID)
}
