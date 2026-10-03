package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
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

// When a section's query does hit the deadline it comes back empty, which would read as
// "nothing to show" - so the endpoint names it in `degraded` and the dashboard reports it as a
// timeout rather than as no data. Proved by shrinking the deadline so every section trips it.
func TestRipplingMetricsReportsDegradedSectionsOnDeadline(t *testing.T) {
	prefix := uniquePrefix("rippledeadline")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	restore := rippling.MetricsDeadline
	rippling.MetricsDeadline = time.Nanosecond
	defer func() { rippling.MetricsDeadline = restore }()

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	// Still a 200 with a well-formed body: the sections that made it are worth showing.
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	degraded, _ := result["degraded"].([]interface{})
	assert.NotEmpty(t, degraded, "sections that gave up are named, not served as empty results")
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
		"source ENUM('email','tn','web') NOT NULL DEFAULT 'email', " +
		"lat DOUBLE NULL, lng DOUBLE NULL, " +
		"status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held', " +
		"created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL)")

	// The real table has FKs (chatmsgid -> chat_messages, msgid -> messages), so create
	// real referenced rows rather than sentinel ids.
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	msgID := CreateTestMessage(t, posterID, "OFFER: held summary test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, "User2User")
	makeChatMsg := func() uint64 {
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'held reply', NOW(), 0, 0, 1)", chatID, replierID)
		var id uint64
		db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&id)
		return id
	}
	cm1, cm2, cm3 := makeChatMsg(), makeChatMsg(), makeChatMsg()

	// Two held + one released, all FK-valid.
	db.Exec("INSERT INTO rippling_held_replies (chatid, chatmsgid, msgid, replieruserid, status) VALUES "+
		"(?, ?, ?, ?, 'held'), (?, ?, ?, ?, 'held'), (?, ?, ?, ?, 'released')",
		chatID, cm1, msgID, replierID,
		chatID, cm2, msgID, replierID,
		chatID, cm3, msgID, replierID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE chatmsgid IN (?, ?, ?)", cm1, cm2, cm3)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?, ?)", cm1, cm2, cm3)

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

// reply_source_split is sourced from rippling_reply_attribution (captured at reply time): a home
// row and a rippling row on the same day must yield a 50% rippling share.
func TestRipplingMetricsReplyKPIs(t *testing.T) {
	prefix := uniquePrefix("ripplereply")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	// Five replies on an isolated day (5 days ago), one per interesting bucket: a home
	// member, a graded ripple_notified capture, a graded ripple_reach capture, a graded
	// ripple_join capture (someone rippling itself put in the group), and a legacy
	// pre-migration row (attribution NULL, was_home_member=0) which must fold into
	// unknown - NOT be credited to rippling as the old replies-minus-home number did.
	db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member, attribution) VALUES " +
		"(900000091, 900000091, NOW() - INTERVAL 5 DAY, 1, 'home'), " +
		"(900000091, 900000092, NOW() - INTERVAL 5 DAY, 0, 'ripple_notified'), " +
		"(900000091, 900000093, NOW() - INTERVAL 5 DAY, 0, 'ripple_reach'), " +
		"(900000091, 900000095, NOW() - INTERVAL 5 DAY, 0, 'ripple_join'), " +
		"(900000091, 900000094, NOW() - INTERVAL 5 DAY, 0, NULL)")
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = 900000091")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	// The split is present (an array).
	split, hasSplit := result["reply_source_split"].([]interface{})
	assert.True(t, hasSplit, "reply_source_split present")

	// The seeded day's split row: 5 replies -> 1 home, 1 notified, 1 reach, 1 join, 1 unknown;
	// ripple share counts only the definite ripple channels (3/5 = 60%).
	found := false
	for _, r := range split {
		if m, ok := r.(map[string]interface{}); ok && m["replies"] == float64(5) && m["home"] == float64(1) {
			found = true
			assert.Equal(t, float64(1), m["ripple_notified"], "one notified-ledger reply")
			assert.Equal(t, float64(1), m["ripple_reach"], "one reach-fed browse reply")
			assert.Equal(t, float64(1), m["ripple_join"], "one ripple-created-membership reply")
			assert.Equal(t, float64(1), m["unknown"],
				"a legacy un-evidenced row folds into unknown, not ripple")
			assert.Equal(t, float64(3), m["ripple"], "ripple = notified + group + join + reach")
			assert.Equal(t, float64(60), m["ripple_pct"])
		}
	}
	assert.True(t, found, "the seeded channel split is surfaced")
}

// The ?start= / ?end= range bounds every headline KPI so a treatment group's before-vs-after can
// be read. We seed a single reply in Jan 2020 - long before rippling_reply_attribution existed, so
// no real rows collide - and confirm it appears only when its day is inside the requested window.
// The default (no params) range is echoed back non-empty.
func TestRipplingMetricsDateRange(t *testing.T) {
	prefix := uniquePrefix("rippledate")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member) VALUES " +
		"(900000291, 900000291, '2020-01-15 12:00:00', 0)")
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = 900000291")

	fetchSplit := func(qs string) []interface{} {
		resp, _ := getApp().Test(httptest.NewRequest("GET",
			fmt.Sprintf("/api/rippling/metrics?%s&jwt=%s", qs, token), nil))
		assert.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		json.Unmarshal(rsp(resp), &result)
		split, _ := result["reply_source_split"].([]interface{})
		return split
	}
	dayPresent := func(split []interface{}, day string) bool {
		for _, r := range split {
			if m, ok := r.(map[string]interface{}); ok && m["day"] == day {
				return true
			}
		}
		return false
	}

	// Window covering Jan 2020 includes the seeded reply; a 2021 window excludes it.
	in := fetchSplit("start=2020-01-01%2000:00:00&end=2020-02-01%2000:00:00")
	assert.True(t, dayPresent(in, "2020-01-15"), "seeded reply present when its day is in range")
	out := fetchSplit("start=2021-01-01%2000:00:00&end=2021-02-01%2000:00:00")
	assert.False(t, dayPresent(out, "2020-01-15"), "seeded reply absent when its day is out of range")

	// With no params the handler defaults the range (last 30 days) and echoes it back.
	respDefault, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	var rd map[string]interface{}
	json.Unmarshal(rsp(respDefault), &rd)
	assert.NotEmpty(t, rd["start"], "start defaults when absent")
	assert.NotEmpty(t, rd["end"], "end defaults when absent")
}

// The trial is over - rippling is fully live - so the trial scoping is retired: the endpoint
// ignores a stale ?trialOnly=1 from a cached client (no filtering, no trial keys in the
// response) instead of scoping to the old RIPPLE_WITHIN_GROUPS set.
func TestRipplingMetricsTrialScopeRetired(t *testing.T) {
	prefix := uniquePrefix("rippletrial")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?trialOnly=1&jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	_, hasTrialOnly := result["trial_only"]
	_, hasTrialIDs := result["trial_group_ids"]
	assert.False(t, hasTrialOnly, "trial_only no longer in the response")
	assert.False(t, hasTrialIDs, "trial_group_ids no longer in the response")
}

// The client-reported reply surfaces (advisory cross-check of the attribution channels) are
// summarised over the same window, with NULL reported as '(not reported)'.
func TestRipplingMetricsClientSourceSummary(t *testing.T) {
	prefix := uniquePrefix("ripplesrc")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member, attribution, client_source) VALUES " +
		"(900000391, 900000391, NOW() - INTERVAL 3 DAY, 0, 'ripple_notified', 'browse'), " +
		"(900000391, 900000392, NOW() - INTERVAL 3 DAY, 0, 'ripple_notified', 'browse'), " +
		"(900000391, 900000393, NOW() - INTERVAL 3 DAY, 1, 'home', NULL)")
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = 900000391")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	summary, has := result["client_source_summary"].([]interface{})
	assert.True(t, has, "client_source_summary present")
	// The seeded rows carry client_source - evidence only live capture writes - so the
	// live-capture boundary date must be surfaced for the dashboard's chart marker.
	assert.NotEmpty(t, result["attribution_capture_from"],
		"attribution_capture_from set once live-captured evidence exists")
	counts := map[string]float64{}
	for _, r := range summary {
		if m, ok := r.(map[string]interface{}); ok {
			counts[m["source"].(string)] += m["count"].(float64)
		}
	}
	assert.GreaterOrEqual(t, counts["browse"], float64(2), "browse surfaces counted")
	assert.GreaterOrEqual(t, counts["(not reported)"], float64(1), "NULL surfaces reported honestly")
}
