package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/status"
	"github.com/stretchr/testify/assert"
)

// publishStatus puts a status blob in the config table exactly as Laravel's
// PlatformStatusWriter does, generated the given duration ago.
func publishStatus(t *testing.T, body string, age time.Duration) {
	t.Helper()

	generated := time.Now().UTC().Add(-age).Format(time.RFC3339)
	value := fmt.Sprintf(`{%s,"generated_at":"%s"}`, body, generated)

	res := database.DBConn.Exec(
		"INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
		status.StatusConfigKey, value,
	)
	assert.NoError(t, res.Error)

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM config WHERE `key` = ?", status.StatusConfigKey)
	})
}

func clearStatus(t *testing.T) {
	t.Helper()
	database.DBConn.Exec("DELETE FROM config WHERE `key` = ?", status.StatusConfigKey)
}

func getStatus(t *testing.T, path string) map[string]interface{} {
	t.Helper()

	resp, _ := getApp().Test(httptest.NewRequest("GET", path, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	err := json2.Unmarshal(rsp(resp), &result)
	assert.NoError(t, err)

	return result
}

func TestGetStatus(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":false,"warning":false,"info":{}`, time.Minute)

	result := getStatus(t, "/api/status")

	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.Equal(t, false, result["error"])
	assert.Equal(t, false, result["warning"])
}

func TestGetStatusReportsPublishedBreaches(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":true,"warning":false,`+
		`"info":{"chats:process-incoming":{"error":true,"errortext":"queue backing up","warning":false,"warningtext":null}}`,
		time.Minute)

	result := getStatus(t, "/api/status")

	assert.Equal(t, true, result["error"])

	info := result["info"].(map[string]interface{})
	entry := info["chats:process-incoming"].(map[string]interface{})
	assert.Equal(t, true, entry["error"])
	assert.Equal(t, "queue backing up", entry["errortext"])
}

// The failure that went unnoticed for a month was a dead writer looking exactly
// like a healthy platform. A status nobody has refreshed must not be served as
// though it were current.
func TestGetStatusStalePublishBecomesAWarning(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":false,"warning":false,"info":{}`, 2*time.Hour)

	result := getStatus(t, "/api/status")

	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, true, result["warning"], "a stale status must warn")

	info := result["info"].(map[string]interface{})
	entry, ok := info["Status feed"].(map[string]interface{})
	assert.True(t, ok, "a stale status must say so in info, not just flip a flag")
	assert.Equal(t, true, entry["warning"])
	assert.Contains(t, entry["warningtext"], "120 minutes ago")
}

// A status inside the window is left exactly as published — no invented warning.
func TestGetStatusFreshPublishIsNotMarkedStale(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":false,"warning":false,"info":{}`, 5*time.Minute)

	result := getStatus(t, "/api/status")

	assert.Equal(t, false, result["warning"])
	assert.NotContains(t, result["info"].(map[string]interface{}), "Status feed")
}

// A stale status keeps whatever it last knew — that is still the best available
// information, it just cannot be presented as current.
func TestGetStatusStalePublishKeepsItsExistingEntries(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":true,"warning":false,`+
		`"info":{"stats:generate-daily":{"error":true,"errortext":"no rows","warning":false,"warningtext":null}}`,
		2*time.Hour)

	result := getStatus(t, "/api/status")

	info := result["info"].(map[string]interface{})
	assert.Contains(t, info, "stats:generate-daily")
	assert.Contains(t, info, "Status feed")
	assert.Equal(t, true, result["error"], "staleness must not erase a known error")
}

func TestGetStatusNeverPublished(t *testing.T) {
	clearStatus(t)

	result := getStatus(t, "/api/status")

	assert.Equal(t, float64(1), result["ret"])
	assert.Equal(t, "Platform status has not been published yet", result["status"])
}

func TestGetStatusUnreadablePublish(t *testing.T) {
	res := database.DBConn.Exec(
		"INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
		status.StatusConfigKey, "this is not json",
	)
	assert.NoError(t, res.Error)
	defer database.DBConn.Exec("DELETE FROM config WHERE `key` = ?", status.StatusConfigKey)

	result := getStatus(t, "/api/status")

	assert.Equal(t, float64(1), result["ret"])
	assert.Equal(t, "Platform status is not readable", result["status"])
}

// A payload with no usable timestamp is the writer's bug. Guessing a staleness
// we cannot measure would hide a status that is otherwise perfectly good.
func TestGetStatusWithoutTimestampIsServedUnchanged(t *testing.T) {
	res := database.DBConn.Exec(
		"INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
		status.StatusConfigKey, `{"ret":0,"status":"Success","error":false,"warning":false,"info":{}}`,
	)
	assert.NoError(t, res.Error)
	defer database.DBConn.Exec("DELETE FROM config WHERE `key` = ?", status.StatusConfigKey)

	result := getStatus(t, "/api/status")

	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, false, result["warning"])
}

func TestGetStatusV2Path(t *testing.T) {
	publishStatus(t, `"ret":0,"status":"Success","error":false,"warning":false,"info":{}`, time.Minute)

	result := getStatus(t, "/apiv2/status")

	assert.Equal(t, float64(0), result["ret"])
}

func TestGetVersion(t *testing.T) {
	result := getStatus(t, "/api/version")

	assert.Contains(t, result, "build")
	assert.Contains(t, result, "commit")
	assert.Contains(t, result, "laravel_commit")
}

func TestGetVersionV2Path(t *testing.T) {
	result := getStatus(t, "/apiv2/version")

	assert.Contains(t, result, "commit")
}
