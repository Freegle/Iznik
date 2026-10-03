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

func createTestShortlink(t *testing.T, name string, url string) uint64 {
	db := database.DBConn
	result := db.Exec("INSERT INTO shortlinks (name, url) VALUES (?, ?)", name, url)
	assert.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&id)
	return id
}

func TestGetShortlinkByID(t *testing.T) {
	prefix := uniquePrefix("Shortlink")
	slID := createTestShortlink(t, prefix+"_link", "https://example.com/"+prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/shortlink?id=%d", slID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	sl := result["shortlink"].(map[string]interface{})
	assert.Equal(t, float64(slID), sl["id"])
	assert.Equal(t, prefix+"_link", sl["name"])
	assert.Equal(t, "Other", sl["type"])
	assert.Equal(t, "https://example.com/"+prefix, sl["url"])
	assert.Contains(t, sl, "clickhistory")
}

func TestGetShortlinkList(t *testing.T) {
	prefix := uniquePrefix("ShortlinkList")
	createTestShortlink(t, prefix+"_a", "https://example.com/"+prefix+"_a")
	createTestShortlink(t, prefix+"_b", "https://example.com/"+prefix+"_b")

	req := httptest.NewRequest("GET", "/api/shortlink", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	links := result["shortlinks"].([]interface{})
	assert.GreaterOrEqual(t, len(links), 2)
}

func TestPostShortlink(t *testing.T) {
	prefix := uniquePrefix("ShortlinkPost")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, token := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"name":"%s_newlink","url":"https://example.com/%s"}`, prefix, prefix)
	req := httptest.NewRequest("POST", "/api/shortlink?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Greater(t, result["id"].(float64), float64(0))
}

func TestPostShortlinkDuplicate(t *testing.T) {
	prefix := uniquePrefix("ShortlinkDup")
	createTestShortlink(t, prefix+"_dup", "https://example.com/"+prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, token := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"name":"%s_dup","url":"https://example.com/%s_other"}`, prefix, prefix)
	req := httptest.NewRequest("POST", "/api/shortlink?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 409, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(3), result["ret"])
	assert.Equal(t, "Name already in use", result["status"])
}

func TestPostShortlinkMissingParams(t *testing.T) {
	// Missing params are rejected before the auth check, so no token needed.
	body := `{"name":"","url":""}`
	req := httptest.NewRequest("POST", "/api/shortlink", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestPostShortlinkNotLoggedIn(t *testing.T) {
	prefix := uniquePrefix("ShortlinkAnon")
	body := fmt.Sprintf(`{"name":"%s_x","url":"https://example.com/%s"}`, prefix, prefix)
	req := httptest.NewRequest("POST", "/api/shortlink", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestPostShortlinkNotModerator(t *testing.T) {
	prefix := uniquePrefix("ShortlinkNonMod")
	uID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, uID)
	body := fmt.Sprintf(`{"name":"%s_x","url":"https://example.com/%s"}`, prefix, prefix)
	req := httptest.NewRequest("POST", "/api/shortlink?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestGetShortlinkV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/shortlink", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
