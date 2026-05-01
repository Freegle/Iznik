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

func createTestStdMsg(t *testing.T, configID uint64, title string) uint64 {
	db := database.DBConn
	result := db.Exec("INSERT INTO mod_stdmsgs (configid, title, subjpref, subjsuff, body) VALUES (?, ?, '', '', '')", configID, title)
	assert.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&id)
	assert.Greater(t, id, uint64(0))
	return id
}

func TestGetStdMsg(t *testing.T) {
	prefix := uniquePrefix("StdMsg")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/stdmsg?id=%d", msgID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "stdmsg")

	msg := result["stdmsg"].(map[string]interface{})
	assert.Equal(t, float64(msgID), msg["id"])
}

func TestPostStdMsg(t *testing.T) {
	prefix := uniquePrefix("StdMsgPost")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)

	body := fmt.Sprintf(`{"configid":%d,"title":"%s_newmsg"}`, cfgID, prefix)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Greater(t, result["id"].(float64), float64(0))
}

func TestPatchStdMsg(t *testing.T) {
	prefix := uniquePrefix("StdMsgPatch")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	body := fmt.Sprintf(`{"id":%d,"title":"%s_updated","body":"New body text"}`, msgID, prefix)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestDeleteStdMsg(t *testing.T) {
	prefix := uniquePrefix("StdMsgDel")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?id=%d&jwt=%s", msgID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM mod_stdmsgs WHERE id = ?", msgID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

// TestDeleteStdMsgViaBody tests that DELETE works when the id is sent in the
// JSON body (as the frontend does via $delv2 in BaseAPI).
func TestDeleteStdMsgViaBody(t *testing.T) {
	prefix := uniquePrefix("StdMsgDelBody")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	body := fmt.Sprintf(`{"id":%d,"configid":%d}`, msgID, cfgID)
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM mod_stdmsgs WHERE id = ?", msgID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestDeleteStdMsgInvalidBody(t *testing.T) {
	prefix := uniquePrefix("StdMsgDelBadJSON")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, token := CreateTestSession(t, modID)

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader("{invalid json"))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(3), result["ret"])
}

// TestDeleteStdMsgViaBodyWithoutId covers the branch where a valid JSON body is
// sent but the id field is absent (zero), so both the body-id and query-string
// fallback are zero, and the handler returns 404 with ret=2.
func TestDeleteStdMsgViaBodyWithoutId(t *testing.T) {
	prefix := uniquePrefix("StdMsgDelNoId")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, token := CreateTestSession(t, modID)

	// Valid JSON with no id — exercises: JSON branch true → BodyParser ok → req.ID=0
	// → first id==0 branch true → query fallback id=0 → second id==0 branch true → 404
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader(`{}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestPostStdMsgMissingTitle(t *testing.T) {
	prefix := uniquePrefix("StdMsgNoTitle")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)

	body := fmt.Sprintf(`{"configid":%d}`, cfgID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/stdmsg?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(3), result["ret"])
}

func TestDeleteStdMsgUnauthorized(t *testing.T) {
	prefix := uniquePrefix("StdMsgDelUnauth")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	otherModID := CreateTestUser(t, prefix+"_other", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, otherToken := CreateTestSession(t, otherModID)

	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	// Protect the config so only the creator can modify it
	db := database.DBConn
	db.Exec("UPDATE mod_configs SET protected = 1 WHERE id = ?", cfgID)

	// Try to delete with different moderator (should fail)
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?id=%d&jwt=%s", msgID, otherToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(4), result["ret"])
}

func TestDeleteStdMsgNotFound(t *testing.T) {
	prefix := uniquePrefix("StdMsgDelNotFound")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, token := CreateTestSession(t, modID)

	// Try to delete with invalid ID
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?id=999999&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestGetStdMsgV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/modtools/stdmsg?id=0", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}
