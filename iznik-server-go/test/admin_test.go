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
func createTestAdmin(t *testing.T, createdby uint64, subject string) uint64 {
	db := database.DBConn
	db.Exec("INSERT INTO admins (createdby, subject, text, created) VALUES (?, ?, 'Test admin text', NOW())",
		createdby, subject)

	var id uint64
	db.Raw("SELECT id FROM admins WHERE createdby = ? AND subject = ? ORDER BY id DESC LIMIT 1",
		createdby, subject).Scan(&id)
	assert.Greater(t, id, uint64(0))
	return id
}

func TestListAdmins(t *testing.T) {
	prefix := uniquePrefix("adm_list")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Test Admin "+prefix)

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
	_, token := CreateTestSession(t, userID)

	createTestAdmin(t, userID, "Admin "+prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	// Admins are a moderator-only view; a non-moderator is forbidden outright.
	assert.Equal(t, 403, resp.StatusCode)

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM admins WHERE subject = ?", "Admin "+prefix)
}

func TestListAdminsIncludesCompleted(t *testing.T) {
	// Discourse 9816: the ModTools "Previous" tab is the archive of *sent* admins, which have
	// `complete` set. The V2 listing previously filtered `complete IS NULL`, hiding every sent
	// admin and leaving only stale, approved-but-never-sent ones (Derek saw a 3-year-old
	// Christmas admin as the newest). The listing must include completed admins.
	prefix := uniquePrefix("adm_done")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// A sent (completed) admin.
	completedID := createTestAdmin(t, modID, "Sent Admin "+prefix)
	db := database.DBConn
	db.Exec("UPDATE admins SET complete = NOW(), pending = 0 WHERE id = ?", completedID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?jwt=%s", modToken), nil)
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
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"subject":"Test Subject %s","text":"Test text"}`, prefix)
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
	body := `{"subject":"Test","text":"Test"}`
	req := httptest.NewRequest("POST", "/api/modtools/admin", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestGetAdmin(t *testing.T) {
	prefix := uniquePrefix("adm_get")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Get Test "+prefix)

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
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Update Test "+prefix)

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
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Delete Test "+prefix)

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
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Hold Test "+prefix)

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
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	adminID := createTestAdmin(t, modID, "Release Test "+prefix)

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

func TestPostAdminCreateWithSendAfter(t *testing.T) {
	// V1 parity: V1 Admin::create() accepts sendafter (nullable datetime) for scheduled
	// admin message sending. The V2 Go INSERT was missing this column.
	// V1 usage: Admin.php line 26 includes sendafter in INSERT, line 115 filters by
	// (sendafter IS NULL OR NOW() > sendafter) when selecting admins to send.
	db := database.DBConn
	prefix := uniquePrefix("admin_sendafter")

	modID := CreateTestUser(t, prefix, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Send a future sendafter datetime.
	sendAfter := time.Now().Add(24 * time.Hour).Format("2006-01-02T15:04:05Z")
	body := fmt.Sprintf(`{"subject":"test sendafter","text":"body","sendafter":"%s"}`, sendAfter)
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

// V1 parity: Admin::getPublic returned parentid, heldat, activeonly, sendafter and createdby as
// a user object. ModAdmin needs parentid for its "copy of a suggested ADMIN" notice and
// createdby.displayname for "Created by".

func patchAdmin(t *testing.T, token string, body string) int {
	req := httptest.NewRequest("PATCH", "/api/modtools/admin?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
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
