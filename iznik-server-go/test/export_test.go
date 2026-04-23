package test

import (
	"bytes"
	"compress/flate"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestExportPostUnauthorized(t *testing.T) {
	// POST /api/export without auth — first branch: 401.
	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/export", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestExportGetUnauthorized(t *testing.T) {
	// GET /api/export without auth — 401 before param validation.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/export", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestExportGetMissingParams(t *testing.T) {
	// Authed but no id/tag — the "Missing id or tag" branch.
	prefix := uniquePrefix("exportmissing")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/export?jwt="+token, nil))
	assert.Equal(t, 400, resp.StatusCode)
}

func TestExportPostCreatesRow(t *testing.T) {
	// POST returns id + 64-char hex tag and inserts a users_exports row.
	prefix := uniquePrefix("exportpost")
	userID, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/export?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	assert.Contains(t, body, "id")
	assert.Contains(t, body, "tag")

	tag, _ := body["tag"].(string)
	assert.Equal(t, 64, len(tag), "tag should be 32 bytes hex-encoded")

	// Row must exist with the returned tag.
	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_exports WHERE userid = ? AND tag = ?", userID, tag).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestExportPostAlreadyPending(t *testing.T) {
	// Second POST while first is still pending (completed IS NULL) — 409.
	prefix := uniquePrefix("exportdup")
	_, token := CreateFullTestUser(t, prefix)

	resp1, _ := getApp().Test(httptest.NewRequest("POST", "/api/export?jwt="+token, nil))
	assert.Equal(t, 200, resp1.StatusCode)

	resp2, _ := getApp().Test(httptest.NewRequest("POST", "/api/export?jwt="+token, nil))
	assert.Equal(t, 409, resp2.StatusCode)
}

func TestExportGetNotFound(t *testing.T) {
	// Valid user + valid-looking id/tag that don't match any row — 404.
	prefix := uniquePrefix("exportnotfound")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/export?id=999999999&tag="+strings.Repeat("0", 64)+"&jwt="+token, nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestExportGetPendingQueuePosition(t *testing.T) {
	// Create an export and fetch it back — completed is NULL so response should
	// include infront (queue position) and omit data.
	prefix := uniquePrefix("exportpending")
	_, token := CreateFullTestUser(t, prefix)

	resp1, _ := getApp().Test(httptest.NewRequest("POST", "/api/export?jwt="+token, nil))
	assert.Equal(t, 200, resp1.StatusCode)
	var created map[string]interface{}
	json2.Unmarshal(rsp(resp1), &created)

	id := fmt.Sprintf("%v", created["id"])
	tag, _ := created["tag"].(string)

	resp2, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/export?id=%s&tag=%s&jwt=%s", id, tag, token), nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp2), &body)
	export, ok := body["export"].(map[string]interface{})
	assert.True(t, ok, "response must contain export object")
	assert.Contains(t, export, "id")
	assert.Contains(t, export, "infront")
	assert.NotContains(t, export, "data", "pending exports must not include data")
}

func TestExportGetTagViaHeader(t *testing.T) {
	// X-Export-Tag header is accepted in place of the query parameter.
	prefix := uniquePrefix("exporthdr")
	_, token := CreateFullTestUser(t, prefix)

	resp1, _ := getApp().Test(httptest.NewRequest("POST", "/api/export?jwt="+token, nil))
	assert.Equal(t, 200, resp1.StatusCode)
	var created map[string]interface{}
	json2.Unmarshal(rsp(resp1), &created)
	id := fmt.Sprintf("%v", created["id"])
	tag, _ := created["tag"].(string)

	req := httptest.NewRequest("GET",
		fmt.Sprintf("/api/export?id=%s&jwt=%s", id, token), nil)
	req.Header.Set("X-Export-Tag", tag)
	resp2, _ := getApp().Test(req)
	assert.Equal(t, 200, resp2.StatusCode)
}

func TestExportGetCompletedDecompresses(t *testing.T) {
	// Simulate a completed export by inserting a row with raw-DEFLATE compressed
	// JSON — the handler must decompress and embed it as json.RawMessage.
	prefix := uniquePrefix("exportdone")
	userID, token := CreateFullTestUser(t, prefix)

	var buf bytes.Buffer
	w, err := flate.NewWriter(&buf, flate.DefaultCompression)
	assert.NoError(t, err)
	_, _ = w.Write([]byte(`{"hello":"world","n":42}`))
	_ = w.Close()

	tag := strings.Repeat("a", 64)
	db := database.DBConn
	res := db.Exec("INSERT INTO users_exports (userid, tag, completed, data) VALUES (?, ?, NOW(), ?)",
		userID, tag, buf.Bytes())
	assert.NoError(t, res.Error)

	var exportID uint64
	db.Raw("SELECT id FROM users_exports WHERE userid = ? AND tag = ? ORDER BY id DESC LIMIT 1",
		userID, tag).Scan(&exportID)
	assert.NotZero(t, exportID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/export?id=%d&tag=%s&jwt=%s", exportID, tag, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	export, ok := body["export"].(map[string]interface{})
	assert.True(t, ok)
	assert.Contains(t, export, "data")

	data, ok := export["data"].(map[string]interface{})
	assert.True(t, ok, "decompressed data must unmarshal as a JSON object")
	assert.Equal(t, "world", data["hello"])
	assert.Equal(t, float64(42), data["n"])
}
