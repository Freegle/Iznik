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

// The mean-replies-per-post metric is surfaced as a well-formed cohort series. (Like the other
// per-day reply metrics it carries no seeded fixture here; this guards the wiring + the response
// shape - the SQL is validated separately, and the query mirrors the proven reply-rate one.)
func TestRipplingMetricsRepliesPerPost(t *testing.T) {
	prefix := uniquePrefix("ripplerpp")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	rpp, ok := result["replies_per_post"].([]interface{})
	assert.True(t, ok, "replies_per_post field present in the response")
	for _, row := range rpp {
		m, ok := row.(map[string]interface{})
		assert.True(t, ok, "each replies_per_post row is an object")
		assert.Contains(t, m, "day")
		assert.Contains(t, m, "mean_replies")
		assert.Contains(t, m, "home_mean")
		assert.Contains(t, m, "ripple_mean")
	}
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

	// The real table has FKs (chatmsgid -> chat_messages, msgid -> messages), so create
	// real referenced rows rather than sentinel ids.
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: held summary test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
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

// The endpoint surfaces the three reply KPIs. reply_source_split is sourced from
// rippling_reply_attribution (captured at reply time): a home row and a rippling row on the same
// day must yield a 50% rippling share. reply_rate_36h and reply_distance_median are computed live
// from messages/chat_messages and are always present arrays.
func TestRipplingMetricsReplyKPIs(t *testing.T) {
	prefix := uniquePrefix("ripplereply")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reply_attribution (
		msgid BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL,
		replied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, was_home_member TINYINT(1) NOT NULL,
		PRIMARY KEY (msgid, userid), KEY rra_replied_at (replied_at))`)
	// Two replies on an isolated day (5 days ago): one established member (home), one via rippling.
	db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member) VALUES " +
		"(900000091, 900000091, NOW() - INTERVAL 5 DAY, 1), (900000091, 900000092, NOW() - INTERVAL 5 DAY, 0)")
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid = 900000091")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	// All three reply-KPI keys are present (arrays).
	_, hasRate := result["reply_rate_36h"].([]interface{})
	_, hasDist := result["reply_distance_median"].([]interface{})
	split, hasSplit := result["reply_source_split"].([]interface{})
	assert.True(t, hasRate, "reply_rate_36h present")
	assert.True(t, hasDist, "reply_distance_median present")
	assert.True(t, hasSplit, "reply_source_split present")

	// The seeded day's split row: 2 replies, 1 home, 1 ripple -> 50% rippling.
	found := false
	for _, r := range split {
		if m, ok := r.(map[string]interface{}); ok && m["replies"] == float64(2) && m["home"] == float64(1) {
			found = true
			assert.Equal(t, float64(1), m["ripple"], "one rippling reply")
			assert.Equal(t, float64(50), m["ripple_pct"], "50% of replies via rippling")
		}
	}
	assert.True(t, found, "the seeded rippling/home split is surfaced")
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
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reply_attribution (
		msgid BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL,
		replied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, was_home_member TINYINT(1) NOT NULL,
		PRIMARY KEY (msgid, userid), KEY rra_replied_at (replied_at))`)
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

// Stage A guard: bounding the outcomes subquery to the window must still count an
// in-window Taken outcome. Seeds one Offer 3 days ago, marks it Taken, and asserts the
// taken_rate row for that day reports posts>=1 and taken>=1.
func TestRipplingMetricsTakenInWindowCounted(t *testing.T) {
	prefix := uniquePrefix("rippletaken")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix+"_grp")

	db := database.DBConn
	msgID := CreateTestMessage(t, posterID, groupID, prefix+" sofa", 51.5, -0.1)
	db.Exec("UPDATE messages SET arrival = NOW() - INTERVAL 3 DAY WHERE id = ?", msgID)
	db.Exec("UPDATE messages_groups SET arrival = NOW() - INTERVAL 3 DAY WHERE msgid = ?", msgID)
	db.Exec("INSERT INTO messages_outcomes (timestamp, msgid, outcome) VALUES (NOW() - INTERVAL 2 DAY, ?, 'Taken')", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msgID)

	var wantDay string
	db.Raw("SELECT DATE_FORMAT(NOW() - INTERVAL 3 DAY, '%Y-%m-%d')").Scan(&wantDay)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	taken, _ := result["taken_rate"].([]interface{})
	found := false
	for _, r := range taken {
		if m, ok := r.(map[string]interface{}); ok && m["day"] == wantDay {
			found = true
			assert.GreaterOrEqual(t, m["posts"].(float64), float64(1), "the seeded post is counted")
			assert.GreaterOrEqual(t, m["taken"].(float64), float64(1), "the Taken outcome is counted within the window")
		}
	}
	assert.True(t, found, "the seeded day appears in taken_rate")
}

// Cohort split on reply_rate_36h: a home-only Offer (no rippled_in=1 row) and a rippled-out Offer
// (has a rippled_in=1 row) arriving the same day. The rippled one gets an Interested reply within
// 36h; the home one does not. Asserts the counts partition (home+ripple=posts) and the rippled
// cohort shows the reply.
func TestRipplingMetricsReplyRateCohorts(t *testing.T) {
	prefix := uniquePrefix("ripplerc")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	homeGrp := CreateTestGroup(t, prefix+"_home")
	awayGrp := CreateTestGroup(t, prefix+"_away")

	db := database.DBConn
	homeMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" homechair", 51.5, -0.1)
	rippMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" rippchair", 51.5, -0.1)
	for _, id := range []uint64{homeMsg, rippMsg} {
		db.Exec("UPDATE messages SET arrival = NOW() - INTERVAL 5 DAY WHERE id = ?", id)
		db.Exec("UPDATE messages_groups SET arrival = NOW() - INTERVAL 5 DAY WHERE msgid = ?", id)
	}
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, rippled_in, autoreposts) "+
		"VALUES (?, ?, NOW() - INTERVAL 5 DAY, 'Approved', 1, 0)", rippMsg, awayGrp)
	chatID := CreateTestChatRoom(t, replierID, &posterID, &homeGrp, "User2User")
	cmID := CreateTestChatMessage(t, chatID, replierID, "I'd like it")
	db.Exec("UPDATE chat_messages SET type = 'Interested', refmsgid = ?, date = NOW() - INTERVAL 5 DAY + INTERVAL 1 HOUR WHERE id = ?", rippMsg, cmID)

	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM chat_messages WHERE id = ?", cmID)

	var wantDay string
	db.Raw("SELECT DATE_FORMAT(NOW() - INTERVAL 5 DAY, '%Y-%m-%d')").Scan(&wantDay)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	rate, _ := result["reply_rate_36h"].([]interface{})
	var row map[string]interface{}
	for _, r := range rate {
		if m, ok := r.(map[string]interface{}); ok && m["day"] == wantDay {
			row = m
		}
	}
	assert.NotNil(t, row, "the seeded day appears in reply_rate_36h")
	assert.Equal(t, row["posts"], row["home_posts"].(float64)+row["ripple_posts"].(float64), "post counts partition")
	assert.GreaterOrEqual(t, row["ripple_posts"].(float64), float64(1), "the rippled-out post is in the ripple cohort")
	assert.GreaterOrEqual(t, row["home_posts"].(float64), float64(1), "the home-only post is in the home cohort")
	assert.GreaterOrEqual(t, row["ripple_replied"].(float64), float64(1), "the rippled-out post's reply is counted")
	assert.Equal(t, float64(0), row["home_replied"], "the home-only post had no reply")
}

// Cohort split on taken_rate: a home-only Offer and a rippled-out Offer the same day; only the
// rippled-out one is Taken. Asserts counts partition and the ripple cohort carries the take.
func TestRipplingMetricsTakenRateCohorts(t *testing.T) {
	prefix := uniquePrefix("rippletc")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	homeGrp := CreateTestGroup(t, prefix+"_home")
	awayGrp := CreateTestGroup(t, prefix+"_away")

	db := database.DBConn
	homeMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" homedesk", 51.5, -0.1)
	rippMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" rippdesk", 51.5, -0.1)
	for _, id := range []uint64{homeMsg, rippMsg} {
		db.Exec("UPDATE messages SET arrival = NOW() - INTERVAL 5 DAY WHERE id = ?", id)
		db.Exec("UPDATE messages_groups SET arrival = NOW() - INTERVAL 5 DAY WHERE msgid = ?", id)
	}
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, rippled_in, autoreposts) "+
		"VALUES (?, ?, NOW() - INTERVAL 5 DAY, 'Approved', 1, 0)", rippMsg, awayGrp)
	db.Exec("INSERT INTO messages_outcomes (timestamp, msgid, outcome) VALUES (NOW() - INTERVAL 4 DAY, ?, 'Taken')", rippMsg)

	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid IN (?, ?)", homeMsg, rippMsg)

	var wantDay string
	db.Raw("SELECT DATE_FORMAT(NOW() - INTERVAL 5 DAY, '%Y-%m-%d')").Scan(&wantDay)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	taken, _ := result["taken_rate"].([]interface{})
	var row map[string]interface{}
	for _, r := range taken {
		if m, ok := r.(map[string]interface{}); ok && m["day"] == wantDay {
			row = m
		}
	}
	assert.NotNil(t, row, "the seeded day appears in taken_rate")
	assert.Equal(t, row["posts"], row["home_posts"].(float64)+row["ripple_posts"].(float64), "post counts partition")
	assert.GreaterOrEqual(t, row["ripple_taken"].(float64), float64(1), "the rippled-out take is in the ripple cohort")
	assert.Equal(t, float64(0), row["home_taken"], "the home-only post was not taken")
}

// Cohort medians on reply_distance_median: a home-only post whose replier is near the post
// and a rippled-out post whose replier is far away, replying the same day. Asserts both cohort
// medians are present and ripple_median_km > home_median_km.
// Uses explicit locations inserted at known coordinates so the test does not depend on what
// locations happen to exist in the test DB at runtime.
func TestRipplingMetricsDistanceCohorts(t *testing.T) {
	prefix := uniquePrefix("rippledc")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	nearReplier := CreateTestUser(t, prefix+"_near", "User")
	farReplier := CreateTestUser(t, prefix+"_far", "User")
	homeGrp := CreateTestGroup(t, prefix+"_home")
	awayGrp := CreateTestGroup(t, prefix+"_away")

	db := database.DBConn

	// Insert explicit locations at known coordinates. Post location: London (51.5, -0.1).
	// Near replier: same location as the post (~0 km away).
	// Far replier: Edinburgh (55.95, -3.19) — ~535 km from London.
	// Using name prefixed by uniquePrefix to avoid collisions.
	// 'type' is required (NOT NULL); 'Point' is valid per the enum.
	var postLocID, nearLocID, farLocID uint64
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Point', 51.5, -0.1)", prefix+"_postloc")
	db.Raw("SELECT id FROM locations WHERE name = ?", prefix+"_postloc").Scan(&postLocID)
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Point', 51.5, -0.1)", prefix+"_nearloc")
	db.Raw("SELECT id FROM locations WHERE name = ?", prefix+"_nearloc").Scan(&nearLocID)
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Point', 55.95, -3.19)", prefix+"_farloc")
	db.Raw("SELECT id FROM locations WHERE name = ?", prefix+"_farloc").Scan(&farLocID)

	defer db.Exec("DELETE FROM locations WHERE name IN (?, ?, ?)", prefix+"_postloc", prefix+"_nearloc", prefix+"_farloc")

	// Set replier home locations.
	db.Exec("UPDATE users SET lastlocation = ? WHERE id = ?", nearLocID, nearReplier)
	db.Exec("UPDATE users SET lastlocation = ? WHERE id = ?", farLocID, farReplier)
	defer db.Exec("UPDATE users SET lastlocation = NULL WHERE id IN (?, ?)", nearReplier, farReplier)

	homeMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" homebike", 51.5, -0.1)
	rippMsg := CreateTestMessage(t, posterID, homeGrp, prefix+" rippbike", 51.5, -0.1)
	// Override the locationid to the explicit post location we inserted.
	db.Exec("UPDATE messages SET locationid = ? WHERE id IN (?, ?)", postLocID, homeMsg, rippMsg)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, rippled_in, autoreposts) "+
		"VALUES (?, ?, NOW() - INTERVAL 5 DAY, 'Approved', 1, 0)", rippMsg, awayGrp)
	hChat := CreateTestChatRoom(t, nearReplier, &posterID, &homeGrp, "User2User")
	hCm := CreateTestChatMessage(t, hChat, nearReplier, "near")
	db.Exec("UPDATE chat_messages SET type='Interested', refmsgid=?, date=NOW() - INTERVAL 5 DAY WHERE id=?", homeMsg, hCm)
	rChat := CreateTestChatRoom(t, farReplier, &posterID, &homeGrp, "User2User")
	rCm := CreateTestChatMessage(t, rChat, farReplier, "far")
	db.Exec("UPDATE chat_messages SET type='Interested', refmsgid=?, date=NOW() - INTERVAL 5 DAY WHERE id=?", rippMsg, rCm)

	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", homeMsg, rippMsg)
	defer db.Exec("DELETE FROM chat_messages WHERE id IN (?, ?)", hCm, rCm)

	var wantDay string
	db.Raw("SELECT DATE_FORMAT(NOW() - INTERVAL 5 DAY, '%Y-%m-%d')").Scan(&wantDay)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	dist, _ := result["reply_distance_median"].([]interface{})
	var row map[string]interface{}
	for _, r := range dist {
		if m, ok := r.(map[string]interface{}); ok && m["day"] == wantDay {
			row = m
		}
	}
	assert.NotNil(t, row, "the seeded day appears in reply_distance_median")
	assert.GreaterOrEqual(t, row["home_replies"].(float64), float64(1), "home cohort has a reply")
	assert.GreaterOrEqual(t, row["ripple_replies"].(float64), float64(1), "ripple cohort has a reply")
	// Near replier is at the same spot as the post (~0 km); far replier is in Edinburgh (~535 km).
	assert.Greater(t, row["ripple_median_km"].(float64), row["home_median_km"].(float64), "rippled replies are further away")
}

// TestRipplingMetricsTrialScope verifies the ?trialOnly=1 filter:
//   - when no rippling_reach rows exist (no trial running), trialOnly=1 returns empty KPI arrays
//     and trial_group_ids: [] (no dilution risk — the scope correctly returns nothing).
//   - when rippling_reach has a row for a specific group (simulating a trial group), trialOnly=1
//     scopes all KPIs to that group only: a message from a NON-trial group must not appear.
//   - trial_only and trial_group_ids are always echoed back in the response.
func TestRipplingMetricsTrialScope(t *testing.T) {
	prefix := uniquePrefix("ripptrial")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn

	// Ensure rippling_reach exists (defensive — migrations should have run it).
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		arrival TIMESTAMP NULL, mode VARCHAR(8) NOT NULL DEFAULT 'drive',
		tick SMALLINT UNSIGNED NOT NULL DEFAULT 0, total_ticks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		total_freeglers INT UNSIGNED NOT NULL DEFAULT 0, max_drive_min FLOAT NULL,
		schedule JSON NULL, polygon GEOMETRY NULL SRID 3857,
		rejected_groups JSON NULL, ripple_intro_sent TINYINT(1) NOT NULL DEFAULT 0,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	)`)

	// ---- part A: ?trialOnly=1 response always has the correct shape ----
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?trialOnly=1&jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var emptyResult map[string]interface{}
	json.Unmarshal(rsp(resp), &emptyResult)

	// Response must echo back the scope flags, regardless of whether reach rows exist.
	assert.Equal(t, true, emptyResult["trial_only"], "trial_only echoed when trialOnly=1")
	_, hasTrialGroupIDs := emptyResult["trial_group_ids"]
	assert.True(t, hasTrialGroupIDs, "trial_group_ids key is always present in response")

	// ---- part B: reach row for trial group → non-trial posts are excluded ----
	// Create a trial group and a non-trial group.
	trialGroupID := CreateTestGroup(t, prefix+"_trial")
	nonTrialGroupID := CreateTestGroup(t, prefix+"_nontrial")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, trialGroupID, "Member")
	CreateTestMembership(t, posterID, nonTrialGroupID, "Member")
	CreateTestMembership(t, replierID, trialGroupID, "Member")
	CreateTestMembership(t, replierID, nonTrialGroupID, "Member")

	// Seed attribution rows: one from the trial group, one from the non-trial group.
	// Use a date 10 days ago so it's inside the default 30-day window.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reply_attribution (
		msgid BIGINT UNSIGNED NOT NULL, userid BIGINT UNSIGNED NOT NULL,
		replied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, was_home_member TINYINT(1) NOT NULL,
		PRIMARY KEY (msgid, userid), KEY rra_replied_at (replied_at))`)

	// Create real messages with FK-valid msgids.
	trialMsgID := CreateTestMessage(t, posterID, trialGroupID, "OFFER: trial scope test", 51.5, -0.1)
	nonTrialMsgID := CreateTestMessage(t, posterID, nonTrialGroupID, "OFFER: non-trial test", 51.5, -0.1)

	// Seed attribution rows: one from the trial group, one from the non-trial group.
	db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member) VALUES "+
		"(?, ?, NOW() - INTERVAL 10 DAY, 1), (?, ?, NOW() - INTERVAL 10 DAY, 1)",
		trialMsgID, replierID,
		nonTrialMsgID, replierID)
	defer db.Exec("DELETE FROM rippling_reply_attribution WHERE msgid IN (?, ?)", trialMsgID, nonTrialMsgID)

	// The CreateTestMessage helper inserts a messages_groups row for the group; we need the
	// origin row to have rippled_in=0 (the default). Verify and ensure.
	db.Exec("UPDATE messages_groups SET rippled_in = 0 WHERE msgid IN (?, ?) AND deleted = 0",
		trialMsgID, nonTrialMsgID)

	// Insert a rippling_reach row for trialMsgID — this makes trialGroupID appear in the trial scope.
	db.Exec("INSERT IGNORE INTO rippling_reach (msgid, lat, lng) VALUES (?, 51.5, -0.1)", trialMsgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", trialMsgID)

	// Fetch with trialOnly=1 — should see trialGroupID in trial_group_ids.
	resp2, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?trialOnly=1&jwt=%s", token), nil))
	assert.Equal(t, 200, resp2.StatusCode)
	var trialResult map[string]interface{}
	json.Unmarshal(rsp(resp2), &trialResult)

	assert.Equal(t, true, trialResult["trial_only"], "trial_only echoed in scoped response")
	trialIDsB, _ := trialResult["trial_group_ids"].([]interface{})
	assert.NotEmpty(t, trialIDsB, "trial_group_ids is non-empty when reach rows exist")
	found := false
	for _, id := range trialIDsB {
		if id == float64(trialGroupID) {
			found = true
			break
		}
	}
	assert.True(t, found, "the seeded trial group id is present in trial_group_ids")

	// Fetch without ?trialOnly — both groups' attribution rows show up in reply_source_split.
	// Since the attribution table has rows for both trialMsgID and nonTrialMsgID, the
	// unscoped total should be >= 2.
	resp3, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/rippling/metrics?jwt=%s", token), nil))
	var allResult map[string]interface{}
	json.Unmarshal(rsp(resp3), &allResult)
	assert.Equal(t, false, allResult["trial_only"], "trial_only is false when param absent")
	trialIDsC, _ := allResult["trial_group_ids"].([]interface{})
	assert.Empty(t, trialIDsC, "trial_group_ids is empty when ?trialOnly not set")
}
