package test

import (
	"bytes"
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestCreateCharitySignup(t *testing.T) {
	prefix := uniquePrefix("charity")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	defer func() {
		db := database.DBConn
		db.Exec("DELETE FROM background_tasks WHERE task_type = 'email_charity_signup' AND data LIKE ?", "%"+prefix+"%")
		db.Exec("DELETE FROM charities WHERE orgname LIKE ?", "%"+prefix+"%")
		db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
		db.Exec("DELETE FROM users WHERE id = ?", userID)
	}()

	body := `{"orgname":"Test Charity ` + prefix + `","orgtype":"registered","charitynumber":"1234567","contactemail":"test@testcharity.org","contactname":"Jane Smith","website":"https://testcharity.org","description":"We help people in need"}`
	req := httptest.NewRequest("POST", "/api/charities?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Greater(t, result["id"], float64(0))

	charityID := uint64(result["id"].(float64))

	// Verify the record was created in the database.
	db := database.DBConn
	var orgname, orgtype, status, contactemail string
	db.Raw("SELECT orgname, orgtype, status, contactemail FROM charities WHERE id = ?", charityID).Row().Scan(&orgname, &orgtype, &status, &contactemail)
	assert.Contains(t, orgname, prefix)
	assert.Equal(t, "registered", orgtype)
	assert.Equal(t, "Pending", status)
	assert.Equal(t, "test@testcharity.org", contactemail)

	// Verify userid was recorded.
	var userid uint64
	db.Raw("SELECT COALESCE(userid, 0) FROM charities WHERE id = ?", charityID).Scan(&userid)
	assert.Equal(t, userID, userid)

	// Verify background task was queued.
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_charity_signup' AND processed_at IS NULL AND data LIKE ?",
		"%"+prefix+"%").Scan(&taskCount)
	assert.Equal(t, int64(1), taskCount)
}

func TestCreateCharitySignup_MissingOrgName(t *testing.T) {
	prefix := uniquePrefix("charity_no_name")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	defer func() {
		db := database.DBConn
		db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
		db.Exec("DELETE FROM users WHERE id = ?", userID)
	}()

	body := `{"contactemail":"test@test.org"}`
	req := httptest.NewRequest("POST", "/api/charities?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestCreateCharitySignup_MissingContactEmail(t *testing.T) {
	prefix := uniquePrefix("charity_no_email")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	defer func() {
		db := database.DBConn
		db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
		db.Exec("DELETE FROM users WHERE id = ?", userID)
	}()

	body := `{"orgname":"Test Org"}`
	req := httptest.NewRequest("POST", "/api/charities?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}
