package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// =============================================================================
// GET /api/comment tests
// =============================================================================

func TestCommentGetSingleUnauthorized(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/comment?id=1", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommentGetSingleNotFound(t *testing.T) {
	prefix := uniquePrefix("cmget_nf")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/comment?id=999999999&jwt=%s", modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestCommentGetListEmpty(t *testing.T) {
	prefix := uniquePrefix("cmget_empty")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	// Regular user (not moderator) should get empty list
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/comment?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	comments := result["comments"].([]interface{})
	assert.Equal(t, 0, len(comments))
}

// =============================================================================
// POST/PATCH/DELETE tests (existing)
// =============================================================================

func TestCommentCreateUnauthorized(t *testing.T) {
	body := `{"userid":1,"groupid":1,"user1":"Test"}`
	req := httptest.NewRequest("POST", "/api/comment", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommentCreateBySupport(t *testing.T) {
	prefix := uniquePrefix("cmwr_support")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	_, supportToken := CreateTestSession(t, supportID)

	// Support can add comment without a group
	body := fmt.Sprintf(`{"userid":%d,"user1":"Support comment"}`, targetID)
	req := httptest.NewRequest("POST", "/api/comment?jwt="+supportToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Greater(t, result["id"], float64(0))

	// Cleanup
	db := database.DBConn
	db.Exec("DELETE FROM users_comments WHERE id = ?", int(result["id"].(float64)))
}

func TestCommentEditUnauthorized(t *testing.T) {
	body := `{"id":1,"user1":"Hacked"}`
	req := httptest.NewRequest("PATCH", "/api/comment", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommentDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/comment/1", nil))
	assert.Equal(t, 401, resp.StatusCode)
}
