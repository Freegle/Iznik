package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/alert"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestAlert_GetAlert_NotFound(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/alert/99999999", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestAlert_GetAlert_PublicAccess(t *testing.T) {
	prefix := uniquePrefix("alert_public")
	db := database.DBConn

	db.Exec("INSERT INTO alerts (createdby, subject, text, html, `from`, `to`, created) VALUES (?, ?, ?, ?, ?, 'Users', NOW())",
		1, "Test Alert "+prefix, "Test message", "<p>Test message</p>", "admin@example.com")

	var alertID uint64
	db.Raw("SELECT id FROM alerts WHERE subject = ? ORDER BY id DESC LIMIT 1", "Test Alert "+prefix).Scan(&alertID)
	assert.Greater(t, alertID, uint64(0))

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/alert/%d", alertID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	alertObj := result["alert"].(map[string]interface{})
	assert.Equal(t, float64(alertID), alertObj["id"])
	assert.Equal(t, "Test Alert"+prefix, alertObj["subject"])
}

func TestAlert_GetAlert_InvalidID(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/alert/invalid", nil))
	assert.Equal(t, 400, resp.StatusCode)
}

func TestAlert_ListAlerts_Unauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/alert", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestAlert_ListAlerts_Forbidden(t *testing.T) {
	prefix := uniquePrefix("alert_regular_user")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/alert?jwt=%s", token), nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAlert_ListAlerts_AdminAccess(t *testing.T) {
	prefix := uniquePrefix("alert_admin_list")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("INSERT INTO alerts (createdby, subject, text, html, `from`, `to`, created) VALUES (?, ?, ?, ?, ?, 'Users', NOW())",
		adminID, "Test Alert "+prefix, "Test message", "<p>Test message</p>", "admin@example.com")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/alert?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	alerts := result["alerts"].([]interface{})
	assert.Greater(t, len(alerts), 0)
}

func TestAlert_CreateAlert_Unauthorized(t *testing.T) {
	body := `{"from":"admin@example.com","subject":"Test","text":"Body","html":"<p>Body</p>"}`
	req := httptest.NewRequest("PUT", "/api/alert", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestAlert_CreateAlert_Forbidden(t *testing.T) {
	prefix := uniquePrefix("alert_regular_create")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"from":"admin@example.com","subject":"Test","text":"Body","html":"<p>Body</p>"}`
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/alert?jwt=%s", token), bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAlert_CreateAlert_Success(t *testing.T) {
	prefix := uniquePrefix("alert_create_success")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"from":"admin@example.com","subject":"Test Alert %s","text":"Test body","html":"<p>Test body</p>"}`, prefix)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/alert?jwt=%s", token), bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.Greater(t, result["id"], float64(0))
}

func TestAlert_CreateAlert_DefaultTo(t *testing.T) {
	prefix := uniquePrefix("alert_default_to")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"from":"admin@example.com","subject":"Test %s","text":"Body"}`, prefix)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/alert?jwt=%s", token), bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	alertID := uint64(result["id"].(float64))

	var createdAlert alert.Alert
	db := database.DBConn
	db.Raw("SELECT id, `to` FROM alerts WHERE id = ?", alertID).Scan(&createdAlert)
	assert.Equal(t, "Mods", createdAlert.To)
}

func TestAlert_CreateAlert_DefaultHTML(t *testing.T) {
	prefix := uniquePrefix("alert_default_html")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"from":"admin@example.com","subject":"Test %s","text":"Line 1\\nLine 2\\nLine 3"}`, prefix)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/alert?jwt=%s", token), bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	alertID := uint64(result["id"].(float64))

	var createdAlert alert.Alert
	db := database.DBConn
	db.Raw("SELECT id, html FROM alerts WHERE id = ?", alertID).Scan(&createdAlert)
	assert.Contains(t, createdAlert.Html, "<br>")
}

func TestAlert_RecordAlert_InvalidRequest(t *testing.T) {
	body := `{"action":"invalid"}`
	req := httptest.NewRequest("POST", "/api/alert", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestAlert_RecordAlert_NoTrackid(t *testing.T) {
	body := `{"action":"clicked","trackid":0}`
	req := httptest.NewRequest("POST", "/api/alert", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestAlert_RecordAlert_ValidClick(t *testing.T) {
	db := database.DBConn

	db.Exec("INSERT INTO alerts_tracking (alertid, response) VALUES (1, NULL)")
	var trackID uint64
	db.Raw("SELECT id FROM alerts_tracking WHERE alertid = 1 ORDER BY id DESC LIMIT 1").Scan(&trackID)

	if trackID > 0 {
		body := fmt.Sprintf(`{"action":"clicked","trackid":%d}`, trackID)
		req := httptest.NewRequest("POST", "/api/alert", bytes.NewBufferString(body))
		req.Header.Set("Content-Type", "application/json")

		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode)

		var result map[string]interface{}
		json.Unmarshal(rsp(resp), &result)
		assert.Equal(t, float64(0), result["ret"])
	}
}
