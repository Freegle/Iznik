package misc

import (
	"bufio"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// resetLokiSingleton resets the package-level singleton so individual tests
// can exercise GetLoki() with different env-var configurations. Must only be
// called from a single goroutine (no t.Parallel in these tests).
func resetLokiSingleton() {
	if lokiInstance != nil {
		lokiInstance.Flush() // wait for in-flight async goroutines before closing
		lokiInstance.Close() // flush and close the open log file before clearing
	}
	lokiOnce = sync.Once{}
	lokiInstance = nil
}

// newDirectDisabledClient bypasses GetLoki and returns a disabled LokiClient.
func newDirectDisabledClient() *LokiClient {
	return &LokiClient{enabled: false}
}

// newDirectEnabledClient creates an enabled LokiClient writing to dir.
func newDirectEnabledClient(dir string) *LokiClient {
	return &LokiClient{
		enabled:      true,
		jsonFilePath: dir,
	}
}

// readAllLogEntries reads every JSON-line log file in dir and returns parsed
// entries. Convenience for asserting on logged content.
func readAllLogEntries(t *testing.T, dir string) []map[string]interface{} {
	t.Helper()
	files, err := filepath.Glob(filepath.Join(dir, "*.log"))
	assert.NoError(t, err)

	var results []map[string]interface{}
	for _, f := range files {
		fh, err := os.Open(f)
		assert.NoError(t, err)
		scanner := bufio.NewScanner(fh)
		for scanner.Scan() {
			line := scanner.Text()
			if line == "" {
				continue
			}
			var entry map[string]interface{}
			if err := json.Unmarshal([]byte(line), &entry); err == nil {
				results = append(results, entry)
			}
		}
		fh.Close()
	}
	return results
}

// labelsOf extracts the "labels" map from a log entry.
func labelsOf(t *testing.T, entry map[string]interface{}) map[string]interface{} {
	t.Helper()
	labels, ok := entry["labels"].(map[string]interface{})
	assert.True(t, ok, "entry.labels must be a map")
	return labels
}

// messageOf extracts the decoded "message" field from a log entry.
func messageOf(t *testing.T, entry map[string]interface{}) map[string]interface{} {
	t.Helper()
	raw, ok := entry["message"].(map[string]interface{})
	assert.True(t, ok, "entry.message must be a map")
	return raw
}

// ================================================================
// GetLoki
// ================================================================

func TestGetLoki_NoEnvVars_Disabled(t *testing.T) {
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	os.Unsetenv("LOKI_ENABLED")
	os.Unsetenv("LOKI_JSON_PATH")

	l := GetLoki()
	assert.NotNil(t, l)
	assert.False(t, l.IsEnabled())
}

func TestGetLoki_EnabledFlagWithoutPath_Disabled(t *testing.T) {
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	os.Setenv("LOKI_ENABLED", "true")
	os.Unsetenv("LOKI_JSON_PATH")
	t.Cleanup(func() { os.Unsetenv("LOKI_ENABLED") })

	l := GetLoki()
	assert.NotNil(t, l)
	// Enabled=true but path empty → disables itself.
	assert.False(t, l.IsEnabled())
}

func TestGetLoki_EnabledWithPath_Enabled(t *testing.T) {
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})

	l := GetLoki()
	assert.NotNil(t, l)
	assert.True(t, l.IsEnabled())
}

func TestGetLoki_EnabledNumeric1_Enabled(t *testing.T) {
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "1")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})

	l := GetLoki()
	assert.True(t, l.IsEnabled())
}

func TestGetLoki_IsSingleton(t *testing.T) {
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)

	l1 := GetLoki()
	l2 := GetLoki()
	assert.Same(t, l1, l2, "GetLoki must return the same pointer on repeated calls")
}

// ================================================================
// IsEnabled
// ================================================================

func TestIsEnabled_True(t *testing.T) {
	l := &LokiClient{enabled: true}
	assert.True(t, l.IsEnabled())
}

func TestIsEnabled_False(t *testing.T) {
	l := &LokiClient{enabled: false}
	assert.False(t, l.IsEnabled())
}

// ================================================================
// log (internal) — file creation, rotation, JSON structure
// ================================================================

func TestLog_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.log(map[string]string{"app": "test"}, `{"msg":"hi"}`)
	assert.Nil(t, l.currentFile)
}

func TestLog_Enabled_CreatesFileAndWritesJSON(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.log(map[string]string{"app": "test"}, `{"msg":"hello"}`)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	assert.Equal(t, "test", labelsOf(t, entries[0])["app"])
}

func TestLog_Enabled_MultipleEntriesGoToSameFile(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.log(map[string]string{"n": "1"}, `{"x":1}`)
	l.log(map[string]string{"n": "2"}, `{"x":2}`)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 2)
}

func TestLog_Enabled_DailyRotation(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)

	// Write one entry for "today".
	l.log(map[string]string{"day": "today"}, `{"v":1}`)

	// Simulate stale state by backdating the tracked date.
	l.fileMutex.Lock()
	l.currentDate = "2000-01-01"
	l.fileMutex.Unlock()

	// Write a second entry — the mismatch triggers the re-open path.
	l.log(map[string]string{"day": "today2"}, `{"v":2}`)
	l.Close()

	// Both entries must appear in the log file(s) — the re-open path appended
	// to today's file (same dateStr), so count entries rather than files.
	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 2, "rotation path must write both entries")
}

func TestLog_Enabled_EntryHasTimestampAndLabels(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.log(map[string]string{"level": "info", "app": "freegle"}, `{"endpoint":"/test"}`)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	entry := entries[0]
	assert.NotEmpty(t, entry["timestamp"])
	labels := labelsOf(t, entry)
	assert.Equal(t, "info", labels["level"])
	assert.Equal(t, "freegle", labels["app"])
}

func TestLog_Enabled_InvalidDirectory_DoesNotPanic(t *testing.T) {
	// Writing to a non-existent directory that can't be created must not panic.
	l := &LokiClient{
		enabled:      true,
		jsonFilePath: "/nonexistent-path-that-cannot-be-created/subdir",
	}
	assert.NotPanics(t, func() {
		l.log(map[string]string{"app": "test"}, `{"x":1}`)
	})
}

// ================================================================
// LogApiRequest
// ================================================================

func TestLogApiRequest_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogApiRequest("v2", "GET", "/test", 200, 10.0, nil, nil)
	assert.Nil(t, l.currentFile)
}

func TestLogApiRequest_Enabled_WritesEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "GET", "/api/test", 200, 12.5, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
}

func TestLogApiRequest_StatusCode5xx_LevelError(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "POST", "/api/fail", 500, 1.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	assert.Equal(t, "error", labelsOf(t, entries[0])["level"])
}

func TestLogApiRequest_StatusCode503_LevelError(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "GET", "/slow", 503, 5000.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "error", labelsOf(t, entries[0])["level"])
}

func TestLogApiRequest_StatusCode4xx_LevelInfo(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "GET", "/api/auth", 401, 1.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "info", labelsOf(t, entries[0])["level"])
}

func TestLogApiRequest_StatusCode403_LevelInfo(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "GET", "/api/forbidden", 403, 1.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "info", labelsOf(t, entries[0])["level"])
}

func TestLogApiRequest_WithUserID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(789)
	l.LogApiRequest("v2", "GET", "/api/user", 200, 5.0, &uid, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "789", labelsOf(t, entries[0])["user_id"])
}

func TestLogApiRequest_ZeroUserID_NoUserIDLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(0)
	l.LogApiRequest("v2", "GET", "/api/test", 200, 5.0, &uid, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUserID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUserID, "user_id label must not be set when userId is 0")
}

func TestLogApiRequest_NilUserID_NoUserIDLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "GET", "/api/test", 200, 5.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUserID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUserID)
}

func TestLogApiRequest_ExtraFields_InMessage(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	extra := map[string]string{"ip": "1.2.3.4", "request_id": "req-abc"}
	l.LogApiRequest("v2", "GET", "/api/test", 200, 5.0, nil, extra)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	assert.Equal(t, "1.2.3.4", msg["ip"])
	assert.Equal(t, "req-abc", msg["request_id"])
}

func TestLogApiRequest_LabelFields(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequest("v2", "DELETE", "/api/item/1", 204, 3.0, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "freegle", labels["app"])
	assert.Equal(t, "api", labels["source"])
	assert.Equal(t, "v2", labels["api_version"])
	assert.Equal(t, "DELETE", labels["method"])
	assert.Equal(t, "204", labels["status_code"])
}

// ================================================================
// LogApiRequestFull
// ================================================================

func TestLogApiRequestFull_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogApiRequestFull("v2", "GET", "/test", 200, 10.0, nil, nil, nil, nil, nil)
	assert.Nil(t, l.currentFile)
}

func TestLogApiRequestFull_Enabled_BasicEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequestFull("v2", "GET", "/api/test", 200, 10.0, nil, nil, nil, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
}

func TestLogApiRequestFull_WithQueryParams_IncludedInMessage(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	qp := map[string]string{"q": "bicycle", "lat": "51.5"}
	l.LogApiRequestFull("v2", "GET", "/search", 200, 8.0, nil, nil, qp, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	qpMap, ok := msg["query_params"].(map[string]interface{})
	assert.True(t, ok)
	assert.Equal(t, "bicycle", qpMap["q"])
}

func TestLogApiRequestFull_EmptyQueryParams_NotInMessage(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequestFull("v2", "GET", "/api/test", 200, 5.0, nil, nil, map[string]string{}, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	_, hasQP := msg["query_params"]
	assert.False(t, hasQP, "empty query_params must not be included")
}

func TestLogApiRequestFull_WithRequestBody_IncludedAndTruncated(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	longVal := make([]byte, 100)
	for i := range longVal {
		longVal[i] = 'x'
	}
	body := map[string]interface{}{"field": string(longVal)}
	l.LogApiRequestFull("v2", "POST", "/api/item", 201, 5.0, nil, nil, nil, body, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	reqBody, ok := msg["request_body"].(map[string]interface{})
	assert.True(t, ok)
	fieldVal := reqBody["field"].(string)
	assert.LessOrEqual(t, len(fieldVal), maxStringLength+3, "long values should be truncated")
}

func TestLogApiRequestFull_EmptyRequestBody_NotInMessage(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequestFull("v2", "GET", "/test", 200, 5.0, nil, nil, nil, map[string]interface{}{}, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	_, hasBody := msg["request_body"]
	assert.False(t, hasBody)
}

func TestLogApiRequestFull_WithResponseBody_IncludedInMessage(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	respBody := map[string]interface{}{"id": float64(42), "status": "ok"}
	l.LogApiRequestFull("v2", "GET", "/api/item/42", 200, 3.0, nil, nil, nil, nil, respBody)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	rBody, ok := msg["response_body"].(map[string]interface{})
	assert.True(t, ok)
	assert.Equal(t, "ok", rBody["status"])
}

func TestLogApiRequestFull_5xx_LevelError(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiRequestFull("v2", "GET", "/crash", 500, 1.0, nil, nil, nil, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "error", labelsOf(t, entries[0])["level"])
}

func TestLogApiRequestFull_WithUserID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(1001)
	l.LogApiRequestFull("v2", "GET", "/api/me", 200, 2.0, &uid, nil, nil, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "1001", labelsOf(t, entries[0])["user_id"])
}

// ================================================================
// LogApiHeaders
// ================================================================

func TestLogApiHeaders_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogApiHeaders("v2", "GET", "/test", nil, nil, nil, "req-1")
	assert.Nil(t, l.currentFile)
}

func TestLogApiHeaders_Enabled_WritesEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	reqHeaders := map[string]string{"Content-Type": "application/json", "X-Real-IP": "1.2.3.4"}
	respHeaders := map[string]string{"Content-Type": "application/json"}
	uid := uint64(55)
	l.LogApiHeaders("v2", "GET", "/api/test", reqHeaders, respHeaders, &uid, "req-xyz")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "api_headers", labels["source"])
}

func TestLogApiHeaders_WithUserID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(99)
	l.LogApiHeaders("v2", "POST", "/api/message", nil, nil, &uid, "req-1")
	l.Close()

	entries := readAllLogEntries(t, dir)
	// LogApiHeaders puts user_id in the message body, not in labels.
	msg := messageOf(t, entries[0])
	assert.NotNil(t, msg["user_id"])
}

func TestLogApiHeaders_NilUserID_NoLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogApiHeaders("v2", "GET", "/test", nil, nil, nil, "req-1")
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUID)
}

// ================================================================
// LogFromLogsTable
// ================================================================

func TestLogFromLogsTable_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogFromLogsTable("TN", "subtype", nil, nil, nil, nil, "text")
	assert.Nil(t, l.currentFile)
}

func TestLogFromLogsTable_Enabled_BasicEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogFromLogsTable("TN", "message", nil, nil, nil, nil, "some text")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "logs_table", labels["source"])
	assert.Equal(t, "TN", labels["type"])
	assert.Equal(t, "message", labels["subtype"])
}

func TestLogFromLogsTable_WithGroupID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	gid := uint64(42)
	l.LogFromLogsTable("TN", "sub", &gid, nil, nil, nil, "text")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "42", labelsOf(t, entries[0])["groupid"])
}

func TestLogFromLogsTable_WithUserID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(77)
	l.LogFromLogsTable("TN", "sub", nil, &uid, nil, nil, "text")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "77", labelsOf(t, entries[0])["user_id"])
}

func TestLogFromLogsTable_ZeroUserID_NoLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	uid := uint64(0)
	l.LogFromLogsTable("TN", "sub", nil, &uid, nil, nil, "text")
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUID)
}

func TestLogFromLogsTable_AllOptionalFieldsNil(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogFromLogsTable("EVT", "custom", nil, nil, nil, nil, "")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
}

func TestLogFromLogsTable_WithAllIDs(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	gid := uint64(1)
	uid := uint64(2)
	byUser := uint64(3)
	msgID := uint64(4)
	l.LogFromLogsTable("TN", "sub", &gid, &uid, &byUser, &msgID, "test")
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	msg := messageOf(t, entries[0])
	assert.NotNil(t, msg["by_user"])
	assert.NotNil(t, msg["msg_id"])
}

// ================================================================
// LogClientEntry
// ================================================================

func TestLogClientEntry_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogClientEntry("info", "pageview", map[string]interface{}{"url": "/home"})
	assert.Nil(t, l.currentFile)
}

func TestLogClientEntry_Enabled_WritesEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogClientEntry("info", "pageview", map[string]interface{}{"url": "/home"})
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "client", labels["source"])
	assert.Equal(t, "info", labels["level"])
	assert.Equal(t, "pageview", labels["event_type"])
}

func TestLogClientEntry_WithNumericUserID_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	data := map[string]interface{}{"user_id": float64(321), "url": "/item"}
	l.LogClientEntry("info", "click", data)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "321", labelsOf(t, entries[0])["user_id"])
}

func TestLogClientEntry_ZeroUserID_NoLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	data := map[string]interface{}{"user_id": float64(0)}
	l.LogClientEntry("info", "click", data)
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUID, "user_id label must not be set when 0")
}

func TestLogClientEntry_NoUserID_NoLabel(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogClientEntry("error", "js_error", map[string]interface{}{"msg": "oops"})
	l.Close()

	entries := readAllLogEntries(t, dir)
	_, hasUID := labelsOf(t, entries[0])["user_id"]
	assert.False(t, hasUID)
}

// ================================================================
// LogCustom
// ================================================================

func TestLogCustom_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogCustom("vector", nil, map[string]interface{}{"hits": 5})
	assert.Nil(t, l.currentFile)
}

func TestLogCustom_Enabled_WritesEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogCustom("vector_search", map[string]string{"model": "text-embed"}, map[string]interface{}{"hits": 10})
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "vector_search", labels["source"])
	assert.Equal(t, "freegle", labels["app"])
	assert.Equal(t, "text-embed", labels["model"])
}

func TestLogCustom_InjectsTimestampIfAbsent(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	data := map[string]interface{}{"hits": 3}
	before := time.Now()
	l.LogCustom("stats", nil, data)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	tsStr, ok := msg["timestamp"].(string)
	assert.True(t, ok)
	ts, err := time.Parse(time.RFC3339Nano, tsStr)
	assert.NoError(t, err)
	assert.True(t, ts.After(before.Add(-time.Second)))
}

func TestLogCustom_PreservesExistingTimestamp(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	existing := "2024-01-15T10:00:00Z"
	data := map[string]interface{}{"timestamp": existing, "hits": 5}
	l.LogCustom("stats", nil, data)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	assert.Equal(t, existing, msg["timestamp"])
}

func TestLogCustom_NilExtraLabels_NoPanic(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	assert.NotPanics(t, func() {
		l.LogCustom("source", nil, map[string]interface{}{"k": "v"})
	})
	l.Close()
}

// ================================================================
// LogChatReply
// ================================================================

func TestLogChatReply_Disabled_NoFile(t *testing.T) {
	l := newDirectDisabledClient()
	l.LogChatReply("email", 1, 2, nil, nil)
	assert.Nil(t, l.currentFile)
}

func TestLogChatReply_Enabled_WritesEntry(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogChatReply("email", 10, 20, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Len(t, entries, 1)
	labels := labelsOf(t, entries[0])
	assert.Equal(t, "chat_reply", labels["source"])
	assert.Equal(t, "email", labels["reply_source"])
	assert.Equal(t, "20", labels["user_id"])
}

func TestLogChatReply_AmpSource_LabelSet(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogChatReply("amp", 5, 6, nil, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	assert.Equal(t, "amp", labelsOf(t, entries[0])["reply_source"])
}

func TestLogChatReply_WithMessageID_InBody(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	msgID := uint64(999)
	l.LogChatReply("website", 7, 8, &msgID, nil)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	assert.NotNil(t, msg["message_id"])
}

func TestLogChatReply_WithEmailTrackingID_InBody(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	etid := uint64(555)
	l.LogChatReply("amp", 9, 10, nil, &etid)
	l.Close()

	entries := readAllLogEntries(t, dir)
	msg := messageOf(t, entries[0])
	assert.NotNil(t, msg["email_tracking_id"])
}

// ================================================================
// Close
// ================================================================

func TestClose_Disabled_NoPanic(t *testing.T) {
	l := newDirectDisabledClient()
	assert.NotPanics(t, func() { l.Close() })
}

func TestClose_EnabledNoOpenFile_NoPanic(t *testing.T) {
	l := &LokiClient{enabled: true}
	assert.NotPanics(t, func() { l.Close() })
}

func TestClose_EnabledWithOpenFile_ClosesFile(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.log(map[string]string{"app": "test"}, `{"x":1}`)
	assert.NotNil(t, l.currentFile)

	l.Close()
	assert.Nil(t, l.currentFile, "currentFile must be nil after Close")
}

func TestClose_CalledTwice_NoPanic(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.log(map[string]string{}, `{}`)
	l.Close()
	assert.NotPanics(t, func() { l.Close() })
}

// ================================================================
// NewLokiMiddleware
// ================================================================

func TestNewLokiMiddleware_ReturnsHandler(t *testing.T) {
	handler := NewLokiMiddleware(LokiMiddlewareConfig{})
	assert.NotNil(t, handler, "NewLokiMiddleware must return a non-nil handler")
}

func TestNewLokiMiddleware_DisabledLoki_RequestPassesThrough(t *testing.T) {
	// When Loki is disabled (default in tests), the middleware must call
	// c.Next() and not block the request.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	os.Unsetenv("LOKI_ENABLED")

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{}))
	app.Get("/ping", func(c *fiber.Ctx) error {
		return c.SendString("pong")
	})

	req := httptest.NewRequest("GET", "/ping", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestNewLokiMiddleware_WithSkipFunc_RequestPassesThrough(t *testing.T) {
	// Even if Loki were enabled, a skip function returning true must skip logging.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	os.Unsetenv("LOKI_ENABLED")

	skipCalled := false
	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		Skip: func(c *fiber.Ctx) bool {
			skipCalled = true
			return true
		},
	}))
	app.Get("/skip", func(c *fiber.Ctx) error {
		return c.SendStatus(204)
	})

	req := httptest.NewRequest("GET", "/skip", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 204, resp.StatusCode)
	// Skip is not called when Loki itself is disabled (early return happens first).
	_ = skipCalled
}

func TestNewLokiMiddleware_EnabledLoki_RequestPassesThrough(t *testing.T) {
	// Even with Loki enabled, the middleware must not block the downstream handler.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})
	// Drain + close before TempDir cleanup to prevent ENOTEMPTY races with async goroutine.
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		GetUserId: func(c *fiber.Ctx) *uint64 {
			uid := uint64(42)
			return &uid
		},
	}))
	// Flush in-flight log goroutines before t.TempDir() removes the log directory.
	t.Cleanup(GetLoki().Flush)
	app.Get("/hello", func(c *fiber.Ctx) error {
		return c.SendString("world")
	})

	req := httptest.NewRequest("GET", "/hello", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestNewLokiMiddleware_EnabledLoki_SetsXUserIDHeader(t *testing.T) {
	// When Loki is enabled and GetUserId returns a non-nil ID, the middleware
	// must set the X-User-Id response header for HAProxy rate limiting.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})
	// Drain + close before TempDir cleanup to prevent ENOTEMPTY races with async goroutine.
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		GetUserId: func(c *fiber.Ctx) *uint64 {
			uid := uint64(123)
			return &uid
		},
	}))
	// Flush in-flight log goroutines before t.TempDir() removes the log directory.
	t.Cleanup(GetLoki().Flush)
	app.Get("/check", func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})

	req := httptest.NewRequest("GET", "/check", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, "123", resp.Header.Get("X-User-Id"))
}

func TestNewLokiMiddleware_EnabledLoki_SetsXUserRoleHeader(t *testing.T) {
	// GetUserRole returning a non-empty string must set X-User-Role header.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})
	// Drain + close before TempDir cleanup to prevent ENOTEMPTY races with async goroutine.
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	role := "moderator"
	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		GetUserId: func(c *fiber.Ctx) *uint64 {
			uid := uint64(5)
			return &uid
		},
		GetUserRole: func(c *fiber.Ctx) *string { return &role },
	}))
	// Flush in-flight log goroutines before t.TempDir() removes the log directory.
	t.Cleanup(GetLoki().Flush)
	app.Get("/role", func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})

	req := httptest.NewRequest("GET", "/role", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, "moderator", resp.Header.Get("X-User-Role"))
}

func TestNewLokiMiddleware_EnabledLoki_SkipFuncTrue_Skips(t *testing.T) {
	// Skip returning true must prevent any logging.
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	dir := t.TempDir()
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() {
		os.Unsetenv("LOKI_ENABLED")
		os.Unsetenv("LOKI_JSON_PATH")
	})
	// Drain + close before TempDir cleanup to prevent ENOTEMPTY races with async goroutine.
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		Skip: func(c *fiber.Ctx) bool { return true },
	}))
	app.Get("/skipped", func(c *fiber.Ctx) error {
		return c.SendStatus(200)
	})

	req := httptest.NewRequest("GET", "/skipped", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

// --- line-size bounding -----------------------------------------------------
//
// Truncating string VALUES does not bound a log line. A response body that is a
// long array of small objects sailed through truncateMap at full length, and a
// real /api/changes response reached 1.85MB that way. Loki's max_line_size is
// 256KB and it discards the whole entry rather than clipping it, so those
// requests vanished from the logs completely - 1,727 entries / 1.10GB in two
// days on 2026-08-23. These tests pin the bound on every dimension.

func wideResponseBody(elements int) map[string]interface{} {
	items := make([]interface{}, elements)
	for i := range items {
		items[i] = map[string]interface{}{
			"id":      float64(i),
			"subject": "a reasonably typical subject line",
			"type":    "Offer",
		}
	}
	return map[string]interface{}{"messages": items}
}

func TestTruncateValue_LongSlice_CappedWithCount(t *testing.T) {
	in := make([]interface{}, maxSliceElements+50)
	for i := range in {
		in[i] = float64(i)
	}

	out, ok := truncateValue(in).([]interface{})
	assert.True(t, ok)
	assert.Len(t, out, maxSliceElements+1, "kept elements plus one marker")
	assert.Equal(t, "...(50 more elements)", out[len(out)-1])
}

func TestTruncateValue_ShortSlice_Unchanged(t *testing.T) {
	in := []interface{}{float64(1), float64(2), float64(3)}
	out, ok := truncateValue(in).([]interface{})
	assert.True(t, ok)
	assert.Len(t, out, 3, "a slice within the cap must not gain a marker")
	assert.Equal(t, float64(3), out[2])
}

func TestTruncateMap_ManyKeys_Capped(t *testing.T) {
	in := map[string]interface{}{}
	for i := 0; i < maxMapKeys+10; i++ {
		in[fmt.Sprintf("key%03d", i)] = "v"
	}

	out := truncateMap(in)
	assert.Len(t, out, maxMapKeys+1, "kept keys plus the _truncated marker")
	assert.Equal(t, "10 more keys", out["_truncated"])
}

func TestTruncateMap_DeepNesting_StopsAtDepthLimit(t *testing.T) {
	// Build a chain deeper than the limit; it must terminate, not recurse away.
	deep := map[string]interface{}{"leaf": "end"}
	for i := 0; i < maxValueDepth+5; i++ {
		deep = map[string]interface{}{"next": deep}
	}

	encoded, err := json.Marshal(truncateMap(deep))
	assert.NoError(t, err)
	assert.Contains(t, string(encoded), "depth limit")
}

func TestCapLogLine_OversizedResponseBody_OmittedNotDropped(t *testing.T) {
	logData := map[string]interface{}{
		"endpoint":      "/api/changes",
		"duration_ms":   float64(10500),
		"user_id":       float64(3378155),
		"timestamp":     "2026-08-23T17:13:48Z",
		"request_id":    "1a02f9d3b37eca9efc0",
		"response_body": strings.Repeat("x", maxLogLineBytes*2),
	}

	line := capLogLine(logData)
	assert.NotNil(t, line, "an oversized entry must still produce a line")
	assert.LessOrEqual(t, len(line), maxLogLineBytes)

	var decoded map[string]interface{}
	assert.NoError(t, json.Unmarshal(line, &decoded))
	assert.Equal(t, "/api/changes", decoded["endpoint"], "context fields survive")
	assert.Equal(t, float64(3378155), decoded["user_id"])
	assert.Contains(t, decoded["response_body"], "omitted")
}

func TestCapLogLine_NormalEntry_Untouched(t *testing.T) {
	logData := map[string]interface{}{
		"endpoint":      "/api/item/42",
		"response_body": map[string]interface{}{"status": "ok"},
	}

	line := capLogLine(logData)
	assert.NotNil(t, line)

	var decoded map[string]interface{}
	assert.NoError(t, json.Unmarshal(line, &decoded))
	body, ok := decoded["response_body"].(map[string]interface{})
	assert.True(t, ok, "a small body must be left intact, not stringified")
	assert.Equal(t, "ok", body["status"])
}

func TestLogApiRequestFull_HugeResponseBody_LineUnderLokiLimit(t *testing.T) {
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	// 5,000 small objects: every string inside is already under maxStringLength,
	// so the old string-only truncation left this at megabytes.
	l.LogApiRequestFull("v2", "GET", "/api/changes", 200, 10500.0, nil, nil, nil, nil, wideResponseBody(5000))
	l.Close()

	data, err := os.ReadFile(filepath.Join(dir, "go-api-"+time.Now().Format("2006-01-02")+".log"))
	assert.NoError(t, err)

	for _, line := range strings.Split(strings.TrimSpace(string(data)), "\n") {
		assert.LessOrEqual(t, len(line), 256*1024,
			"every emitted line must fit Loki's max_line_size or the entry is discarded whole")
	}

	entries := readAllLogEntries(t, dir)
	assert.NotEmpty(t, entries, "the request must still be logged, not dropped")
	assert.Equal(t, "/api/changes", messageOf(t, entries[0])["endpoint"])
}

func TestCapLogLine_OversizedNonBodyField_HardClipped(t *testing.T) {
	// Nothing here is a body, so dropping bodies cannot help: the guard has to
	// fall through to the hard clip. `endpoint` is a string we do not control,
	// so the clip must truncate it rather than copy it through.
	logData := map[string]interface{}{
		"endpoint":    strings.Repeat("e", maxLogLineBytes*2),
		"duration_ms": float64(3),
		"user_id":     float64(99),
		"timestamp":   "2026-08-23T17:13:48Z",
	}

	line := capLogLine(logData)
	assert.NotNil(t, line)
	assert.LessOrEqual(t, len(line), maxLogLineBytes,
		"the last-resort clip must itself be within the limit")

	var decoded map[string]interface{}
	assert.NoError(t, json.Unmarshal(line, &decoded))
	assert.Contains(t, decoded["_truncated"], "exceeded")
	assert.Equal(t, float64(99), decoded["user_id"], "context worth keeping survives the clip")
	assert.LessOrEqual(t, len(decoded["endpoint"].(string)), maxStringLength+3)
}

func TestCapLogLine_UnmarshalableValue_StillEmitsAnEntry(t *testing.T) {
	// A channel cannot be marshalled. The first Marshal fails, so every later
	// step fails too - we must still emit a valid line rather than nothing.
	line := capLogLine(map[string]interface{}{
		"endpoint": "/api/thing",
		"bad":      make(chan int),
	})

	assert.NotNil(t, line, "an unmarshalable payload must not silently vanish")
	var decoded map[string]interface{}
	assert.NoError(t, json.Unmarshal(line, &decoded), "whatever we emit must be valid JSON")
}

func TestTruncateValueDepth_ScalarAtDepthLimit_Marked(t *testing.T) {
	assert.Equal(t, fmt.Sprintf("...(depth limit %d reached)", maxValueDepth),
		truncateValueDepth("anything", maxValueDepth))
}

func TestLogClientEntry_HugeBrowserPayload_LineUnderLokiLimit(t *testing.T) {
	// LogClientEntry marshals a map supplied straight from the browser, so its
	// size is controlled by the client rather than by us.
	dir := t.TempDir()
	l := newDirectEnabledClient(dir)
	l.LogClientEntry("info", "scroll", map[string]interface{}{
		"user_id": float64(3378155),
		"blob":    strings.Repeat("z", maxLogLineBytes*2),
	})
	l.Close()

	data, err := os.ReadFile(filepath.Join(dir, "go-api-"+time.Now().Format("2006-01-02")+".log"))
	assert.NoError(t, err)
	for _, line := range strings.Split(strings.TrimSpace(string(data)), "\n") {
		assert.LessOrEqual(t, len(line), 256*1024)
	}
}
