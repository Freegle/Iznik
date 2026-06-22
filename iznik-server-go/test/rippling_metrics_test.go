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

// The endpoint returns live_metrics from the weekly batch rollup (rippling_live_metrics). This
// covers §16.1/§16.2: volume distribution and reach drive-min, written by ripple:tune and read
// back by the sysadmin dashboard as a trend.
func TestRipplingMetricsSurfacesLiveMetrics(t *testing.T) {
	prefix := uniquePrefix("ripplive")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_live_metrics (" +
		"id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " +
		"period_start DATE NOT NULL, period_type VARCHAR(8) NOT NULL DEFAULT 'weekly', " +
		"stratum_type VARCHAR(16) NOT NULL DEFAULT 'overall', " +
		"stratum_key VARCHAR(64) NOT NULL DEFAULT 'all', " +
		"metric VARCHAR(48) NOT NULL, value DOUBLE NOT NULL, sample_size INT NOT NULL DEFAULT 0, " +
		"created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " +
		"UNIQUE KEY rippling_live_metrics_uniq (period_start, period_type, stratum_type, stratum_key, metric)" +
		")")
	db.Exec("INSERT INTO rippling_live_metrics (period_start, period_type, stratum_type, stratum_key, metric, value, sample_size) " +
		"VALUES (CURDATE() - INTERVAL 3 DAY, 'weekly', 'overall', 'all', 'volume_posts_p50', 42.5, 100) " +
		"ON DUPLICATE KEY UPDATE value = 42.5")
	defer db.Exec("DELETE FROM rippling_live_metrics WHERE metric = 'volume_posts_p50' AND stratum_type = 'overall' AND sample_size = 100")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	live, _ := result["live_metrics"].([]interface{})
	found := false
	for _, row := range live {
		if m, ok := row.(map[string]interface{}); ok && m["metric"] == "volume_posts_p50" {
			found = true
			assert.Equal(t, float64(42.5), m["value"], "volume_posts_p50 value correct")
			assert.Equal(t, float64(100), m["sample_size"])
		}
	}
	assert.True(t, found, "volume_posts_p50 live metric surfaced in response")
}

// The endpoint returns a cross_group_summary computed live from messages_groups.rippled_in,
// answering §16.3: what fraction of post appearances were rippled-in by the engine.
func TestRipplingMetricsCrossGroupSummary(t *testing.T) {
	prefix := uniquePrefix("ripplecross")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	// cross_group_summary must always be present (it queries messages_groups which always exists)
	// and period_days must be 30.
	cg, ok := result["cross_group_summary"].(map[string]interface{})
	assert.True(t, ok, "cross_group_summary field present in response")
	assert.Equal(t, float64(30), cg["period_days"], "period_days is 30")
	// totals/pcts are numeric (we can't assert exact values against a shared test DB)
	_, hasTotal := cg["total"]
	_, hasPct := cg["cross_group_pct"]
	assert.True(t, hasTotal, "total field present")
	assert.True(t, hasPct, "cross_group_pct field present")
}

// The endpoint returns a capture_summary from rippling_algorithm_metrics (§16.4 timing /
// capture). The capture_rate is computed from pairs_in_time / pairs_total.
func TestRipplingMetricsCaptureSummary(t *testing.T) {
	prefix := uniquePrefix("ripplecapture")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	// rippling_algorithm_metrics is renamed from ripple_algorithm_metrics by migration
	// 2026_06_18_000002; stand it up defensively in case the test DB hasn't run it.
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_algorithm_metrics (" +
		"id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, week_start DATE NOT NULL, " +
		"`group` VARCHAR(32) NOT NULL, curve VARCHAR(64) NOT NULL, " +
		"pairs_total INT UNSIGNED NOT NULL, pairs_in_time INT UNSIGNED NOT NULL, " +
		"pairs_late INT UNSIGNED NOT NULL, pairs_unreachable INT UNSIGNED NOT NULL, " +
		"notify_vol BIGINT UNSIGNED NOT NULL, ticks SMALLINT UNSIGNED NOT NULL, " +
		"lifetime_days FLOAT NOT NULL, max_minutes FLOAT NOT NULL, " +
		"reply_p50_hours FLOAT NULL, reply_p75_hours FLOAT NULL, " +
		"created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)")
	db.Exec("INSERT INTO rippling_algorithm_metrics " +
		"(week_start, `group`, curve, pairs_total, pairs_in_time, pairs_late, pairs_unreachable, notify_vol, ticks, lifetime_days, max_minutes, reply_p50_hours, reply_p75_hours) " +
		"VALUES ('2026-06-09', 'all', 'front-heavy', 200, 150, 40, 10, 5000, 6, 7.0, 30.0, 2.5, 6.0) " +
		"ON DUPLICATE KEY UPDATE pairs_total = 200")
	defer db.Exec("DELETE FROM rippling_algorithm_metrics WHERE week_start = '2026-06-09' AND `group` = 'all' AND curve = 'front-heavy'")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	cap, ok := result["capture_summary"].(map[string]interface{})
	assert.True(t, ok, "capture_summary field present in response")
	assert.Equal(t, float64(200), cap["pairs_total"], "pairs_total correct")
	assert.Equal(t, float64(150), cap["pairs_in_time"], "pairs_in_time correct")
	// capture_rate = 150/200 * 100 = 75.0
	assert.InDelta(t, 75.0, cap["capture_rate"], 0.01, "capture_rate computed correctly")
	assert.Equal(t, "front-heavy", cap["curve"], "curve surfaced")
	assert.Equal(t, float64(2.5), cap["reply_p50_hours"], "reply_p50_hours surfaced")
}

// The endpoint returns a held_reply_summary from rippling_held_replies (§15 friction / §16.5).
func TestRipplingMetricsHeldReplySummary(t *testing.T) {
	prefix := uniquePrefix("rippleheld")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	// rippling_held_replies ships with PR C; stand it up so this runs in isolation.
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_held_replies (" +
		"id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " +
		"chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL, " +
		"msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL, " +
		"lat DOUBLE NULL, lng DOUBLE NULL, " +
		"status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held', " +
		"created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL)")
	// Insert two held + one released row with sentinel ids that won't clash.
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status) VALUES " +
		"(9000001, 9000001, 9000001, 9000001, 'held'), " +
		"(9000002, 9000002, 9000002, 9000002, 'held'), " +
		"(9000003, 9000003, 9000003, 9000003, 'released')")
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatid IN (9000001, 9000002, 9000003)")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	held, _ := result["held_reply_summary"].([]interface{})
	statusCounts := map[string]float64{}
	for _, row := range held {
		if m, ok := row.(map[string]interface{}); ok {
			statusCounts[m["status"].(string)] = m["count"].(float64)
		}
	}
	assert.GreaterOrEqual(t, statusCounts["held"], float64(2), "at least 2 held rows counted")
	assert.GreaterOrEqual(t, statusCounts["released"], float64(1), "at least 1 released row counted")
}
