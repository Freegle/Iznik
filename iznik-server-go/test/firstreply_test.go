package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Tick 1 is the reach the post has now; tick 3 is where it ends up. A point in
// tick 3 but not tick 1 is somebody the current rules make wait days.
const frTick1 = "POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))"
const frTick3 = "POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))"

// ensureFirstReplyTables is defined once, in chatprompt_test.go.

// seedRipplingReach gives msgid a current reach of tick 1 and an eventual reach of tick 3.
func seedRipplingReach(t *testing.T, msgID uint64, withMax bool) {
	db := database.DBConn

	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	res := db.Exec("INSERT INTO rippling_reach "+
		"(msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers, "+
		" max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "+
		"VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), "+
		" NOW(), 'drive', 1, 3, 4000, 30, NULL, NOW(), 'expanding', NOW(), NOW())",
		msgID, frTick1, frTick1)
	if res.Error != nil {
		t.Fatalf("could not seed reach: %v", res.Error)
	}

	if withMax {
		db.Exec("UPDATE rippling_reach SET max_polygon = ST_GeomFromText(?, 3857), "+
			"max_cumulative_users = 4000 WHERE msgid = ?", frTick3, msgID)
	}
}

// TestFirstReplyPassthrough_FirstReplyInsideEventualReach: the whole point of the
// feature. Somebody the ripple has not got to yet, but will, replies to a post
// that has nothing. Holding them would delay the poster's only reply to protect
// an ordering that replier crosses in a few days anyway.
func TestFirstReplyPassthrough_FirstReplyInsideEventualReach(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	// Whole-network arm; the rollout split is exercised separately.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "100")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough test", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	// (lng, lat) inside tick 3 only.
	assert.True(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))
}

func TestFirstReplyPassthrough_SecondReplyIsStillHeld(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass2")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	// Whole-network arm; the rollout split is exercised separately.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "100")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough second", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, "+
		"reviewrequired, processingrequired, processingsuccessful) "+
		"VALUES (?, ?, 'I would like this', 'Interested', ?, NOW(), 0, 0, 1)",
		chatID, replierID, msgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9),
		"once a post has a reply, local-first ordering is worth the delay again")
}

func TestFirstReplyPassthrough_OutsideEventualReachIsHeld(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass3")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	// Whole-network arm; the rollout split is exercised separately.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "100")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough far", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	// Aberdeen: the post never gets there.
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, -2.1, 57.1))
}

func TestFirstReplyPassthrough_DisabledAndUnpopulatedBothHold(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass4")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough off", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Switched off: unchanged behaviour.
	t.Setenv("FIRSTREPLY_ENABLED", "false")
	seedRipplingReach(t, msgID, true)
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))

	// Switched on but the eventual reach has not been computed yet - the state
	// every row is in until the backfill drains. Must also be unchanged.
	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	// Whole-network arm; the rollout split is exercised separately.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "100")
	seedRipplingReach(t, msgID, false)
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))
}

// The rollout split has to agree with the batch side's msgid % 100 bucketing, or
// a post would be in the trial for an emailed reply and out of it for an in-app
// one - which would make the arms meaningless.
func TestFirstReplyPassthrough_RespectsTheRolloutPercentage(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frrollout")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: rollout", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	// Default is nobody, so enabling the lever alone changes nothing.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "0")
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9),
		"a zero rollout must select nothing, not everything")

	// Full rollout includes every post.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "100")
	assert.True(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))

	// A partial rollout agrees with msgid % 100, the same bucket the batch app uses.
	t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", "50")
	assert.Equal(t, msgID%100 < 50, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))
}

// The sysadmin metrics endpoint must actually answer, against the real schema.
//
// This exists because it silently did not. "Posts spoken to" was counted with
// SELECT COUNT(DISTINCT msgid) FROM firstreply_prompts_sent - correct when a
// prompt was about one post, and dead once prompts were grouped and that table
// was re-keyed on the member. The column was gone, so the query errored, GORM's
// Scan left the destination at its zero value, and the dashboard - which guards
// the sentence with v-if on that value - simply stopped printing it. Nothing
// failed, nothing logged, a section of the panel just silently went missing, and
// no test noticed because nothing here called the endpoint at all.
//
// So this asserts the whole handler runs and that the number is right, rather
// than re-stating any single query - a test that repeated the SQL would have
// been just as wrong as the code.
func TestFirstReplyMetricsEndpoint_CountsPostsFromTheGroupedSet(t *testing.T) {
	ensureFirstReplyTables(t)

	prefix := uniquePrefix("frmetrics")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	db := database.DBConn

	// Two prompts covering three posts between them, with one post in BOTH -
	// which is the normal case (a photo question and a delivery question can
	// cover the same item) and the one a naive count gets wrong.
	msgA := CreateTestMessage(t, posterID, groupID, "OFFER: metrics a", 51.5, -0.1)
	msgB := CreateTestMessage(t, posterID, groupID, "OFFER: metrics b", 51.5, -0.1)
	msgC := CreateTestMessage(t, posterID, groupID, "OFFER: metrics c", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, adminID, &posterID, nil, "User2User")
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	for _, set := range [][]uint64{{msgA, msgB}, {msgB, msgC}} {
		chatMsgID := seedPrompt(t, chatID, adminID, set[0], "photo", "[]")
		msgids, _ := json.Marshal(set)
		db.Exec("UPDATE chat_prompts SET msgids = ? WHERE chatmsgid = ?", string(msgids), chatMsgID)
		defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	}

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/firstreply/metrics?jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	// Every section the dashboard reads must be present - a query that errors
	// leaves its key at a zero value rather than failing the request.
	for _, key := range []string{"daily", "passthrough", "scouts", "prompts", "postsengaged"} {
		_, ok := result[key]
		assert.True(t, ok, "metrics payload must carry %s", key)
	}

	engaged, _ := result["postsengaged"].(float64)
	assert.GreaterOrEqual(t, engaged, float64(3),
		"three distinct posts were covered, counted once each despite one appearing twice")
}

// A bare end date means midnight, which silently drops everything that happened
// TODAY - the most interesting part of the window and the least likely to be
// questioned, because the panel still looks perfectly plausible. The handler
// widens a date-only bound to cover its whole day.
//
// This is a regression test for a real one: the dashboard's own date filter
// sends bare dates, and the average "hours earlier" read 5.6 instead of 3.7
// because the most recent passthroughs were being excluded.
func TestFirstReplyMetrics_BareEndDateIncludesToday(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frmetricstoday")

	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: today", 51.5, -0.1)

	// A passthrough recorded a few minutes ago, with a known saving.
	db.Exec("INSERT INTO firstreply_passthroughs (msgid, userid, source, lat, lng, created_at, waited_hours, computed_at) "+
		"VALUES (?, ?, 'web', 51.5, -0.1, NOW(), 9.5, NOW())", msgID, posterID)
	defer db.Exec("DELETE FROM firstreply_passthroughs WHERE msgid = ?", msgID)

	today := time.Now().Format("2006-01-02")
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/firstreply/metrics?start=%s&end=%s&jwt=%s", today, today, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	pt, _ := result["passthrough"].(map[string]interface{})
	assert.NotNil(t, pt)
	sized, _ := pt["sized"].(float64)
	assert.GreaterOrEqual(t, sized, float64(1),
		"a passthrough from earlier today must be inside a window whose end is today's date")
}

// The overall KPI: rippled posts split into the arm that got the treatment and
// the arm that did not, counted on replies and on rehomes.
//
// Every other number on this dashboard can rise while this one does not - more
// scouts mailed is not more items rehomed - so the split has to bucket on exactly
// the rule the levers bucket on, and it has to be honest at the ends.
//
// Asserted at the boundaries rather than on absolute counts. Other tests leave
// rippled posts inside the same window, so "trial has exactly N" would be a
// promise about other people's fixtures; "at 100% nothing is a holdout" is a
// promise about this code.
func TestFirstReplyMetrics_SplitsRippledPostsIntoTrialAndHoldout(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frmetricsarms")

	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: arm split", 51.5, -0.1)

	// created_at is what the KPI windows on, and the column is NULL by default -
	// a row without it would be silently invisible to the whole comparison.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, created_at) "+
		"VALUES (?, 51.5, -0.1, ST_GeomFromText("+
		"'POLYGON((0.0 51.4,0.2 51.4,0.2 51.6,0.0 51.6,0.0 51.4))', 3857), ST_Envelope(ST_GeomFromText("+
		"'POLYGON((0.0 51.4,0.2 51.4,0.2 51.6,0.0 51.6,0.0 51.4))', 3857)), NOW())", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// A reply from somebody other than the poster, and a rehome.
	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date) "+
		"VALUES (?, ?, 'interested', 'Interested', ?, NOW())", chatID, replierID, msgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)

	armsFor := func(t *testing.T, percent string) map[string]map[string]interface{} {
		t.Helper()
		t.Setenv("FIRSTREPLY_ROLLOUT_PERCENT", percent)

		resp, _ := getApp().Test(httptest.NewRequest("GET",
			fmt.Sprintf("/api/firstreply/metrics?jwt=%s", token), nil), 60000)
		require.Equal(t, 200, resp.StatusCode)

		var result map[string]interface{}
		require.NoError(t, json.Unmarshal(rsp(resp), &result))

		out := map[string]map[string]interface{}{}
		raw, _ := result["arms"].([]interface{})
		for _, r := range raw {
			row, _ := r.(map[string]interface{})
			arm, _ := row["arm"].(string)
			out[arm] = row
		}
		return out
	}

	t.Run("everything is in the trial at 100%", func(t *testing.T) {
		arms := armsFor(t, "100")

		trial, ok := arms["trial"]
		require.True(t, ok, "at 100%% every rippled post is in the trial")
		assert.GreaterOrEqual(t, trial["posts"].(float64), float64(1))
		assert.GreaterOrEqual(t, trial["replied"].(float64), float64(1),
			"a post with an Interested reply from someone else counts as replied")
		assert.GreaterOrEqual(t, trial["taken"].(float64), float64(1),
			"and a Taken outcome is the number the whole feature answers to")

		if holdout, present := arms["holdout"]; present {
			assert.Zero(t, holdout["posts"], "nothing can be a holdout at 100%")
		}
	})

	t.Run("everything is a holdout at 0%", func(t *testing.T) {
		arms := armsFor(t, "0")

		holdout, ok := arms["holdout"]
		require.True(t, ok, "at 0%% nothing has had the treatment")
		assert.GreaterOrEqual(t, holdout["posts"].(float64), float64(1))

		if trial, present := arms["trial"]; present {
			assert.Zero(t, trial["posts"], "nothing can be in the trial at 0%")
		}
	})
}

// The endpoint is Support/Admin only - it reports on members and their posts.
func TestFirstReplyMetrics_RequiresSupportOrAdmin(t *testing.T) {
	prefix := uniquePrefix("frmetricsauth")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/firstreply/metrics?jwt=%s", token), nil))

	assert.Equal(t, 403, resp.StatusCode)
}
