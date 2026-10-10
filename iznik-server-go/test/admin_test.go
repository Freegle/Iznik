package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// createTestAdmin creates an admin record for testing and returns its ID.
func createTestAdmin(t *testing.T, createdby uint64, groupid uint64, subject string) uint64 {
	db := database.DBConn
	db.Exec("INSERT INTO admins (createdby, groupid, subject, text, created) VALUES (?, ?, ?, 'Test admin text', NOW())",
		createdby, groupid, subject)

	var id uint64
	db.Raw("SELECT id FROM admins WHERE createdby = ? AND subject = ? ORDER BY id DESC LIMIT 1",
		createdby, subject).Scan(&id)
	assert.Greater(t, id, uint64(0))
	return id
}

func TestListAdmins(t *testing.T) {
	prefix := uniquePrefix("adm_list")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Test Admin "+prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?jwt=%s", modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.GreaterOrEqual(t, len(result), 1)

	// Verify our admin is in the list.
	found := false
	for _, a := range result {
		if a["id"] == float64(adminID) {
			found = true
			break
		}
	}
	assert.True(t, found, "Created admin should be in the list")

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestListAdminsNotMod(t *testing.T) {
	prefix := uniquePrefix("adm_listnm")
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	createTestAdmin(t, userID, groupID, "Admin "+prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	// Non-mod gets empty list (the INNER JOIN on memberships with mod role filters them out).
	assert.Equal(t, 0, len(result))

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE subject = ?", "Admin "+prefix)
}

func TestListAdminsSystemAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_sysadm")
	// System Admin user with no group membership.
	adminUserID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminUserID)

	// Create a group and admin that the system admin is NOT a member of.
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	adminID := createTestAdmin(t, modID, groupID, "SysAdmin Test "+prefix)

	// Discourse 9816: with NO group requested, even a system admin must NOT see other
	// groups' admins - the unscoped sweep leaked admins for groups the user isn't on into
	// the Pending tab. The default listing is scoped to the caller's own active mod groups.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?jwt=%s", adminToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	leaked := false
	for _, a := range result {
		if a["id"] == float64(adminID) {
			leaked = true
			break
		}
	}
	assert.False(t, leaked, "System admin should NOT see other groups' admins when no group is requested")

	// But when a specific group IS requested, a system admin may view that group's admin
	// history even without a membership (e.g. to look up a sent admin for a mod).
	req = httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?groupid=%d&jwt=%s", groupID, adminToken), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &result)
	found := false
	for _, a := range result {
		if a["id"] == float64(adminID) {
			found = true
			break
		}
	}
	assert.True(t, found, "System admin should see a specific group's admins when that group is requested")

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestListAdminsIncludesCompleted(t *testing.T) {
	// Discourse 9816: the ModTools "Previous" tab is the archive of *sent* admins, which have
	// `complete` set. The V2 listing previously filtered `complete IS NULL`, hiding every sent
	// admin and leaving only stale, approved-but-never-sent ones (Derek saw a 3-year-old
	// Christmas admin as the newest). The listing must include completed admins.
	prefix := uniquePrefix("adm_done")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// A sent (completed) admin.
	completedID := createTestAdmin(t, modID, groupID, "Sent Admin "+prefix)
	db := database.DBConn
	db.Exec("UPDATE admins SET complete = NOW(), pending = 0 WHERE id = ?", completedID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?groupid=%d&jwt=%s", groupID, modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	found := false
	for _, a := range result {
		if a["id"] == float64(completedID) {
			found = true
			break
		}
	}
	assert.True(t, found, "Completed (sent) admins must appear in the listing for the Previous tab")

	// Cleanup
	db.Exec("DELETE FROM admins WHERE id = ?", completedID)
}

func TestCreateAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_create")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"groupid":%d,"subject":"Test Subject %s","text":"Test text"}`, groupID, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Greater(t, result["id"], float64(0))

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE id = ?", int(result["id"].(float64)))
}

func TestCreateAdminUnauthorized(t *testing.T) {
	body := `{"groupid":1,"subject":"Test","text":"Test"}`
	req := httptest.NewRequest("POST", "/api/modtools/admin", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestGetAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_get")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Get Test "+prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(adminID), result["id"])
	assert.Equal(t, "Get Test "+prefix, result["subject"])

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestUpdateAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_upd")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Update Test "+prefix)

	body := fmt.Sprintf(`{"id":%d,"subject":"Updated Subject %s","text":"Updated text"}`, adminID, prefix)
	req := httptest.NewRequest("PATCH", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify update.
	db := database.DBConn
	var subject string
	db.Raw("SELECT subject FROM admins WHERE id = ?", adminID).Scan(&subject)
	assert.Equal(t, "Updated Subject "+prefix, subject)

	// Verify edit tracking: editedat should be set, editedby should be the mod.
	var editedat *time.Time
	db.Raw("SELECT editedat FROM admins WHERE id = ?", adminID).Scan(&editedat)
	assert.NotNil(t, editedat, "editedat should be set after PATCH")

	var editedby *uint64
	db.Raw("SELECT editedby FROM admins WHERE id = ?", adminID).Scan(&editedby)
	assert.NotNil(t, editedby, "editedby should be set after PATCH")
	assert.Equal(t, modID, *editedby, "editedby should equal the mod's user ID")

	// Cleanup
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestDeleteAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_del")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Delete Test "+prefix)

	body := fmt.Sprintf(`{"id":%d}`, adminID)
	req := httptest.NewRequest("DELETE", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify deleted.
	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM admins WHERE id = ?", adminID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestHoldAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_hold")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Hold Test "+prefix)

	body := fmt.Sprintf(`{"id":%d,"action":"Hold"}`, adminID)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is set.
	db := database.DBConn
	var heldby *uint64
	db.Raw("SELECT heldby FROM admins WHERE id = ?", adminID).Scan(&heldby)
	assert.NotNil(t, heldby)
	assert.Equal(t, modID, *heldby)

	// Cleanup
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestReleaseAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_rel")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Release Test "+prefix)

	// Hold first.
	db := database.DBConn
	db.Exec("UPDATE admins SET heldby = ? WHERE id = ?", modID, adminID)

	// Release.
	body := fmt.Sprintf(`{"id":%d,"action":"Release"}`, adminID)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is cleared.
	var heldby *uint64
	db.Raw("SELECT heldby FROM admins WHERE id = ?", adminID).Scan(&heldby)
	assert.Nil(t, heldby)

	// Cleanup
	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestAdminsOnlyForActiveModGroups(t *testing.T) {
	// admins listing should only show admins for groups where
	// the user is an active moderator (settings.active != 0).
	prefix := uniquePrefix("AdminActive")
	db := database.DBConn

	activeGroupID := CreateTestGroup(t, prefix+"_active")
	inactiveGroupID := CreateTestGroup(t, prefix+"_inactive")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, activeGroupID, "Moderator")
	CreateTestMembership(t, modID, inactiveGroupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Mark the mod as inactive on one group.
	db.Exec("UPDATE memberships SET settings = ? WHERE userid = ? AND groupid = ?",
		`{"active":0}`, modID, inactiveGroupID)

	// Create admins for both groups.
	db.Exec("INSERT INTO admins (groupid, subject, text, createdby, pending) VALUES (?, ?, ?, ?, 0)",
		activeGroupID, prefix+"_active_admin", "text", modID)
	var activeAdminID uint64
	db.Raw("SELECT id FROM admins WHERE groupid = ? AND subject = ? ORDER BY id DESC LIMIT 1",
		activeGroupID, prefix+"_active_admin").Scan(&activeAdminID)

	db.Exec("INSERT INTO admins (groupid, subject, text, createdby, pending) VALUES (?, ?, ?, ?, 0)",
		inactiveGroupID, prefix+"_inactive_admin", "text", modID)
	var inactiveAdminID uint64
	db.Raw("SELECT id FROM admins WHERE groupid = ? AND subject = ? ORDER BY id DESC LIMIT 1",
		inactiveGroupID, prefix+"_inactive_admin").Scan(&inactiveAdminID)

	// Listing should only include the active group's admin.
	req := httptest.NewRequest("GET", "/api/modtools/admin?jwt="+modToken, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var admins []map[string]interface{}
	json2.Unmarshal(rsp(resp), &admins)

	foundActive := false
	foundInactive := false
	for _, a := range admins {
		id := uint64(a["id"].(float64))
		if id == activeAdminID {
			foundActive = true
		}
		if id == inactiveAdminID {
			foundInactive = true
		}
	}
	assert.True(t, foundActive, "Should see admin for active group")
	assert.False(t, foundInactive, "Should NOT see admin for inactive group")

	// Cleanup.
	db.Exec("DELETE FROM admins WHERE id IN (?, ?)", activeAdminID, inactiveAdminID)
}

func TestPostAdminCreateWithSendAfter(t *testing.T) {
	// V1 parity: V1 Admin::create() accepts sendafter (nullable datetime) for scheduled
	// admin message sending. The V2 Go INSERT was missing this column.
	// V1 usage: Admin.php line 26 includes sendafter in INSERT, line 115 filters by
	// (sendafter IS NULL OR NOW() > sendafter) when selecting admins to send.
	db := database.DBConn
	prefix := uniquePrefix("admin_sendafter")

	modID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Send a future sendafter datetime.
	sendAfter := time.Now().Add(24 * time.Hour).Format("2006-01-02T15:04:05Z")
	body := fmt.Sprintf(`{"groupid":%d,"subject":"test sendafter","text":"body","sendafter":"%s"}`, groupID, sendAfter)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	respBody := rsp(resp)
	if resp.StatusCode != 200 {
		t.Logf("Admin POST returned %d: %s", resp.StatusCode, string(respBody))
	}
	assert.Equal(t, 200, resp.StatusCode)
	if resp.StatusCode != 200 {
		t.FailNow()
	}

	var result map[string]interface{}
	json2.Unmarshal(respBody, &result)
	id := uint64(result["id"].(float64))
	assert.Greater(t, id, uint64(0), "Should return a new admin ID")

	// Verify sendafter was persisted in the DB.
	var sendAfterDB *string
	db.Raw("SELECT sendafter FROM admins WHERE id = ?", id).Scan(&sendAfterDB)
	assert.NotNil(t, sendAfterDB, "sendafter should be stored in the DB")

	// Cleanup.
	db.Exec("DELETE FROM admins WHERE id = ?", id)
}

// Guidance for local moderators lives in admins.modguidance, separate from subject and text.

func adminGuidanceRow(t *testing.T, id uint64) (string, string, *string) {
	db := database.DBConn
	var row struct {
		Subject     string
		Text        string
		Modguidance *string
	}
	db.Raw("SELECT subject, text, modguidance FROM admins WHERE id = ?", id).Scan(&row)
	return row.Subject, row.Text, row.Modguidance
}

func TestCreateSystemWideAdminStoresGuidanceSeparately(t *testing.T) {
	prefix := uniquePrefix("adm_guid_new")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, token := CreateTestSession(t, supportID)

	guidance := "GUIDANCE-" + prefix + " add your own sign-off"
	body := fmt.Sprintf(`{"subject":"Sys %s","text":"Body for members","modguidance":%q}`, prefix, guidance)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	id := uint64(result["id"].(float64))
	assert.Greater(t, id, uint64(0))

	subject, text, stored := adminGuidanceRow(t, id)
	assert.NotNil(t, stored)
	assert.Equal(t, guidance, *stored)
	assert.Equal(t, "Body for members", text, "guidance must not be folded into the body")
	assert.Equal(t, "Sys "+prefix, subject)
	assert.NotContains(t, text, "GUIDANCE-")
	assert.NotContains(t, subject, "GUIDANCE-")

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id)
}

func TestCreateGroupAdminIgnoresGuidance(t *testing.T) {
	prefix := uniquePrefix("adm_guid_grp")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"groupid":%d,"subject":"Grp %s","text":"Body","modguidance":"nobody reads this"}`, groupID, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	id := uint64(result["id"].(float64))

	_, _, stored := adminGuidanceRow(t, id)
	assert.Nil(t, stored, "guidance only applies to a system-wide admin")

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id)
}

func TestModeratorSeesGuidanceOnTheirCopy(t *testing.T) {
	prefix := uniquePrefix("adm_guid_get")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Copy "+prefix)
	guidance := "GUIDANCE-" + prefix
	database.DBConn.Exec("UPDATE admins SET modguidance = ? WHERE id = ?", guidance, adminID)

	// Single admin.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var single map[string]interface{}
	json2.Unmarshal(rsp(resp), &single)
	assert.Equal(t, guidance, single["modguidance"])
	assert.Equal(t, "Test admin text", single["text"], "guidance must not be folded into the body")

	// List.
	req = httptest.NewRequest("GET", "/api/modtools/admin?jwt="+modToken, nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var list []map[string]interface{}
	json2.Unmarshal(rsp(resp), &list)
	found := false
	for _, a := range list {
		if a["id"] == float64(adminID) {
			found = true
			assert.Equal(t, guidance, a["modguidance"])
		}
	}
	assert.True(t, found)

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestNonModeratorNeverGetsGuidance(t *testing.T) {
	prefix := uniquePrefix("adm_guid_nomod")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	adminID := createTestAdmin(t, modID, groupID, "Hidden "+prefix)
	database.DBConn.Exec("UPDATE admins SET modguidance = ? WHERE id = ?", "GUIDANCE-"+prefix, adminID)

	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, memberToken := CreateTestSession(t, memberID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, memberToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
	assert.NotContains(t, string(rsp(resp)), "GUIDANCE-")

	req = httptest.NewRequest("GET", "/api/modtools/admin?jwt="+memberToken, nil)
	resp, _ = getApp().Test(req)
	assert.NotContains(t, string(rsp(resp)), "GUIDANCE-")

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestEditingCopyLeavesGuidanceAlone(t *testing.T) {
	prefix := uniquePrefix("adm_guid_edit")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, groupID, "Edit "+prefix)
	guidance := "GUIDANCE-" + prefix
	database.DBConn.Exec("UPDATE admins SET modguidance = ? WHERE id = ?", guidance, adminID)

	// A PATCH that tries to change guidance has no effect on it, and edits to the text do not
	// pick it up.
	body := fmt.Sprintf(`{"id":%d,"subject":"New subject","text":"New body","modguidance":"overwritten"}`, adminID)
	req := httptest.NewRequest("PATCH", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	subject, text, stored := adminGuidanceRow(t, adminID)
	assert.Equal(t, "New subject", subject)
	assert.Equal(t, "New body", text)
	assert.NotContains(t, text, "GUIDANCE-")
	assert.NotNil(t, stored)
	assert.Equal(t, guidance, *stored)

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

// V1 parity: Admin::getPublic returned parentid, heldat, activeonly, sendafter and createdby as
// a user object. ModAdmin needs parentid for its "copy of a suggested ADMIN" notice and
// createdby.displayname for "Created by".

func TestAdminReturnsV1Fields(t *testing.T) {
	prefix := uniquePrefix("adm_v1")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	parentID := createTestAdmin(t, modID, groupID, "Parent "+prefix)
	adminID := createTestAdmin(t, modID, groupID, "Child "+prefix)
	db := database.DBConn
	db.Exec("UPDATE admins SET parentid = ?, activeonly = 1, heldby = ?, heldat = NOW() WHERE id = ?", parentID, modID, adminID)

	check := func(a map[string]interface{}) {
		assert.Equal(t, float64(parentID), a["parentid"])
		assert.Equal(t, true, a["activeonly"])
		assert.NotNil(t, a["heldat"])
		assert.Contains(t, a, "sendafter")
		cb, ok := a["createdby"].(map[string]interface{})
		assert.True(t, ok, "createdby must be a user object, as in V1")
		assert.Equal(t, float64(modID), cb["id"])
		assert.NotEmpty(t, cb["displayname"])
	}

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var single map[string]interface{}
	json2.Unmarshal(rsp(resp), &single)
	check(single)

	req = httptest.NewRequest("GET", "/api/modtools/admin?jwt="+modToken, nil)
	resp, _ = getApp().Test(req)
	var list []map[string]interface{}
	json2.Unmarshal(rsp(resp), &list)
	found := false
	for _, a := range list {
		if a["id"] == float64(adminID) {
			found = true
			check(a)
		}
	}
	assert.True(t, found)

	db.Exec("DELETE FROM admins WHERE id IN (?, ?)", adminID, parentID)
}

func TestHoldAdminRecordsWhen(t *testing.T) {
	prefix := uniquePrefix("adm_holdat")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	adminID := createTestAdmin(t, modID, groupID, "Hold "+prefix)

	body := fmt.Sprintf(`{"id":%d,"action":"Hold"}`, adminID)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var withHeldat int64
	database.DBConn.Raw("SELECT COUNT(*) FROM admins WHERE id = ? AND heldat IS NOT NULL", adminID).Scan(&withHeldat)
	assert.Equal(t, int64(1), withHeldat, "a hold records when it was taken, as in V1")

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestAnyGroupModeratorCanGetAnAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_anymod")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, ownerID, groupID, "Moderator")
	adminID := createTestAdmin(t, ownerID, groupID, "Anymod "+prefix)

	// A moderator of a different group, with no system role, may read it.
	otherGroup := CreateTestGroup(t, prefix+"_other")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, otherGroup, "Owner")
	_, modToken := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// A plain member of a group still may not.
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, memberToken := CreateTestSession(t, memberID)
	req = httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, memberToken), nil)
	resp, _ = getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func patchAdmin(t *testing.T, token string, body string) int {
	req := httptest.NewRequest("PATCH", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

func TestPatchAdminSetsAndClearsSendAfter(t *testing.T) {
	prefix := uniquePrefix("adm_sendafter")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)
	adminID := createTestAdmin(t, modID, groupID, "Sendafter "+prefix)
	db := database.DBConn

	count := func(where string) int64 {
		var n int64
		db.Raw("SELECT COUNT(*) FROM admins WHERE id = ? AND "+where, adminID).Scan(&n)
		return n
	}

	assert.Equal(t, 200, patchAdmin(t, token, fmt.Sprintf(`{"id":%d,"sendafter":"2030-05-06T07:08:00Z"}`, adminID)))
	assert.Equal(t, int64(1), count("sendafter = '2030-05-06 07:08:00'"))

	// A browser datetime-local value without seconds or zone is accepted too.
	assert.Equal(t, 200, patchAdmin(t, token, fmt.Sprintf(`{"id":%d,"sendafter":"2030-06-07T08:09"}`, adminID)))
	assert.Equal(t, int64(1), count("sendafter = '2030-06-07 08:09:00'"))

	// An edit that does not mention sendafter leaves it alone.
	assert.Equal(t, 200, patchAdmin(t, token, fmt.Sprintf(`{"id":%d,"subject":"Other"}`, adminID)))
	assert.Equal(t, int64(1), count("sendafter = '2030-06-07 08:09:00'"))

	// Garbage is refused and changes nothing, including other fields in the same request.
	assert.Equal(t, 400, patchAdmin(t, token, fmt.Sprintf(`{"id":%d,"subject":"Nope","sendafter":"next tuesday"}`, adminID)))
	assert.Equal(t, int64(1), count("sendafter = '2030-06-07 08:09:00' AND subject = 'Other'"))

	// null clears it.
	assert.Equal(t, 200, patchAdmin(t, token, fmt.Sprintf(`{"id":%d,"sendafter":null}`, adminID)))
	assert.Equal(t, int64(1), count("sendafter IS NULL"))

	db.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestCreateAdminSendAfterAcceptsDatetimeLocal(t *testing.T) {
	prefix := uniquePrefix("adm_sa_new")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"groupid":%d,"subject":"SA %s","text":"x","sendafter":"2031-01-02T03:04"}`, groupID, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	id := uint64(result["id"].(float64))

	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM admins WHERE id = ? AND sendafter = '2031-01-02 03:04:00'", id).Scan(&n)
	assert.Equal(t, int64(1), n)
	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id)
}

func deleteAdminStatus(t *testing.T, token string, id uint64) int {
	req := httptest.NewRequest("DELETE", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(fmt.Sprintf(`{"id":%d}`, id)))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

func adminExists(id uint64) bool {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM admins WHERE id = ?", id).Scan(&n)
	return n == 1
}

func TestDeleteAdminPermissions(t *testing.T) {
	prefix := uniquePrefix("adm_delperm")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, ownerID, groupID, "Moderator")

	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, memberToken := CreateTestSession(t, memberID)

	otherGroup := CreateTestGroup(t, prefix+"_other")
	otherModID := CreateTestUser(t, prefix+"_othermod", "User")
	CreateTestMembership(t, otherModID, otherGroup, "Moderator")
	_, otherModToken := CreateTestSession(t, otherModID)

	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, supportToken := CreateTestSession(t, supportID)
	adminUserID := CreateTestUser(t, prefix+"_sysadmin", "Admin")
	_, sysadminToken := CreateTestSession(t, adminUserID)

	// A plain member and a moderator of another group cannot delete it.
	id := createTestAdmin(t, ownerID, groupID, "Del perm "+prefix)
	assert.Equal(t, 403, deleteAdminStatus(t, memberToken, id))
	assert.Equal(t, 403, deleteAdminStatus(t, otherModToken, id))
	assert.True(t, adminExists(id))

	// Support and Admin can delete any admin, without moderating its group.
	assert.Equal(t, 200, deleteAdminStatus(t, supportToken, id))
	assert.False(t, adminExists(id))

	id = createTestAdmin(t, ownerID, groupID, "Del perm admin "+prefix)
	assert.Equal(t, 200, deleteAdminStatus(t, sysadminToken, id))
	assert.False(t, adminExists(id))

	// Support and Admin can delete a system-wide admin (no group); a group moderator cannot.
	database.DBConn.Exec("INSERT INTO admins (createdby, groupid, subject, text, created) VALUES (?, NULL, ?, 'x', NOW())", supportID, "Sys "+prefix)
	var sysID uint64
	database.DBConn.Raw("SELECT id FROM admins WHERE subject = ? ORDER BY id DESC LIMIT 1", "Sys "+prefix).Scan(&sysID)
	_, ownerToken := CreateTestSession(t, ownerID)
	assert.Equal(t, 403, deleteAdminStatus(t, ownerToken, sysID))
	assert.True(t, adminExists(sysID))
	assert.Equal(t, 200, deleteAdminStatus(t, supportToken, sysID))
	assert.False(t, adminExists(sysID))

	// The group's own moderator can delete it.
	id = createTestAdmin(t, ownerID, groupID, "Del perm own "+prefix)
	assert.Equal(t, 200, deleteAdminStatus(t, ownerToken, id))
	assert.False(t, adminExists(id))
}

// The Previous tab loads the history a page at a time: limit caps the page and before continues
// from the last ADMIN of the previous page, newest first.
func TestListAdminsPages(t *testing.T) {
	prefix := uniquePrefix("adm_page")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	db := database.DBConn

	ids := []uint64{}
	for i := 0; i < 5; i++ {
		id := createTestAdmin(t, modID, groupID, fmt.Sprintf("Paged %s %d", prefix, i))
		db.Exec("UPDATE admins SET pending = 0, created = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?", 5-i, id)
		ids = append(ids, id)
	}
	defer db.Exec("DELETE FROM admins WHERE groupid = ?", groupID)

	page := func(url string) []uint64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", url+"&jwt="+modToken, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var result []map[string]interface{}
		json2.Unmarshal(rsp(resp), &result)
		got := []uint64{}
		for _, a := range result {
			got = append(got, uint64(a["id"].(float64)))
		}
		return got
	}

	base := fmt.Sprintf("/api/modtools/admin?groupid=%d&pending=false", groupID)

	first := page(base + "&limit=2")
	assert.Equal(t, []uint64{ids[4], ids[3]}, first, "newest two")

	second := page(fmt.Sprintf("%s&limit=2&before=%d", base, first[len(first)-1]))
	assert.Equal(t, []uint64{ids[2], ids[1]}, second, "next two")

	third := page(fmt.Sprintf("%s&limit=2&before=%d", base, second[len(second)-1]))
	assert.Equal(t, []uint64{ids[0]}, third, "the last one")

	assert.Len(t, page(base), 5, "no limit still returns everything")
}
