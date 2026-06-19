package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// The rippling sysadmin metrics endpoint surfaces rippling_event_metrics totals, Support/Admin
// only (§15/§16).
func TestRipplingMetricsEndpoint(t *testing.T) {
	prefix := uniquePrefix("ripplemetrics")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), 'reply_blocked', 7) " +
		"ON DUPLICATE KEY UPDATE count = 7")
	defer db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'reply_blocked' AND day = CURDATE()")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	totals, _ := result["totals"].([]interface{})
	found := false
	for _, tm := range totals {
		if m, ok := tm.(map[string]interface{}); ok && m["event"] == "reply_blocked" {
			found = true
			assert.Equal(t, float64(7), m["count"], "reply_blocked total surfaced")
		}
	}
	assert.True(t, found, "reply_blocked total present in the rollup")
}

// A non-admin must be forbidden from the sysadmin metrics endpoint.
func TestRipplingMetricsRequiresAdmin(t *testing.T) {
	prefix := uniquePrefix("ripplemetrics_noauth")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 403, resp.StatusCode, "non-admin is forbidden from rippling metrics")
}

// The endpoint also surfaces the §16 tuner's geographically-unusual hotspots so sysadmin can see
// a local problem the overall average hides.
func TestRipplingMetricsSurfacesHotspots(t *testing.T) {
	prefix := uniquePrefix("ripplehotspot")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	// rippling_hotspots ships with PR G; stand it up so this runs in isolation too.
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_hotspots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " +
		"detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, period_start DATE, area_type VARCHAR(16), " +
		"area_id BIGINT UNSIGNED NULL, area_name VARCHAR(128) NULL, metric VARCHAR(48), value DOUBLE, " +
		"baseline DOUBLE, deviation DOUBLE, direction VARCHAR(8), severity VARCHAR(8))")
	db.Exec("INSERT INTO rippling_hotspots (period_start, area_type, area_id, area_name, metric, value, baseline, deviation, direction, severity) " +
		"VALUES (CURDATE(), 'group', 987654, 'Anomaly Town', 'secondary_reject_rate', 0.9, 0.1, 12.3, 'high', 'alert')")
	defer db.Exec("DELETE FROM rippling_hotspots WHERE area_id = 987654")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	hotspots, _ := result["hotspots"].([]interface{})
	found := false
	for _, h := range hotspots {
		if m, ok := h.(map[string]interface{}); ok && m["area_name"] == "Anomaly Town" {
			found = true
			assert.Equal(t, "alert", m["severity"])
			assert.Equal(t, "high", m["direction"])
		}
	}
	assert.True(t, found, "the flagged hotspot is surfaced to sysadmin")
}
