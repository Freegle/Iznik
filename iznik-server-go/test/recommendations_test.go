package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// statsForSource fetches the recommendations funnel as the given Support user and
// returns (impressions, clicks, attributedReplies) for one source. Uses deltas so
// the assertions are robust to other tests' data in the shared DB.
func statsForSource(t *testing.T, token, source string) (imp, clk, rep int64) {
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/modtools/recommendations/stats?days=30&jwt="+token, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var body struct {
		Sources []struct {
			Source            string `json:"source"`
			Impressions       int64  `json:"impressions"`
			Clicks            int64  `json:"clicks"`
			AttributedReplies int64  `json:"attributedReplies"`
		} `json:"sources"`
		Holdout map[string]interface{} `json:"holdout"`
	}
	json.NewDecoder(resp.Body).Decode(&body)
	// The holdout block must always be present (structure check; exact values are
	// globally scoped so not asserted here).
	require.NotNil(t, body.Holdout, "holdout block present")
	for _, s := range body.Sources {
		if s.Source == source {
			return s.Impressions, s.Clicks, s.AttributedReplies
		}
	}
	return 0, 0, 0
}

func TestRecommendationsStatsFunnel(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("recstats")

	support := CreateTestUser(t, prefix+"_support", "Support")
	token := getToken(t, support)

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "Member")
	viewer := CreateTestUser(t, prefix+"_viewer", "Member")

	baseImp, baseClk, baseRep := statsForSource(t, token, "similar_posts")

	// Five distinct source-tagged View rows for one viewer across five posts:
	// three impressions (pageview=0) and two clicks (pageview=1). Timestamped 10
	// days ago so a 7-day reply window is testable inside the 30-day report window.
	msgs := make([]uint64, 5)
	for i := 0; i < 5; i++ {
		msgs[i] = CreateTestMessage(t, poster, groupID, fmt.Sprintf("%s item %d", prefix, i), 55.9533, -3.1883)
		pv := 0
		if i >= 3 {
			pv = 1 // last two are clicks
		}
		db.Exec("INSERT INTO messages_likes (msgid, userid, type, pageview, source, timestamp) "+
			"VALUES (?, ?, 'View', ?, 'similar_posts', DATE_SUB(NOW(), INTERVAL 10 DAY))",
			msgs[i], viewer, pv)
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_likes WHERE userid = ? AND source = 'similar_posts'", viewer)
	})

	// A reply within 7 days of the click on msgs[3] → attributed. A reply 10 days
	// after the click on msgs[4] → outside the window → not attributed.
	chatID := CreateTestChatRoom(t, viewer, &poster, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired) "+
		"VALUES (?, ?, 'Interested', 'Interested', ?, DATE_SUB(NOW(), INTERVAL 7 DAY), 1, 0)",
		chatID, viewer, msgs[3]) // click at -10d, reply at -7d → +3d, within window
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired) "+
		"VALUES (?, ?, 'Interested', 'Interested', ?, NOW(), 1, 0)",
		chatID, viewer, msgs[4]) // click at -10d, reply now → +10d, outside window
	t.Cleanup(func() {
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
	})

	imp, clk, rep := statsForSource(t, token, "similar_posts")
	assert.Equal(t, int64(5), imp-baseImp, "5 tagged views = 5 impressions")
	assert.Equal(t, int64(2), clk-baseClk, "2 pageview=1 rows = 2 clicks")
	assert.Equal(t, int64(1), rep-baseRep, "only the within-7-day reply is attributed")
}

// The stats response carries a `degraded` flag, set true only when a query hits
// its MAX_EXECUTION_TIME cap (the messages_likes stats index is not deployed and a
// query would otherwise hang the request). Against the test DB the queries
// complete, so it must be false — proving the flag is wired and the guarded
// queries still run normally.
func TestRecommendationsStatsNotDegraded(t *testing.T) {
	support := CreateTestUser(t, uniquePrefix("recdeg")+"_support", "Support")
	token := getToken(t, support)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/modtools/recommendations/stats?days=30&jwt="+token, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var body struct {
		Degraded bool `json:"degraded"`
	}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
	assert.False(t, body.Degraded, "queries complete against the test DB, so the panel is not degraded")
}

// holdoutStats fetches the holdout block as the given Support user. Values are
// global to the DB, so callers compare deltas around their own fixture.
func holdoutStats(t *testing.T, token string) (holdoutUsers, holdoutReplies, shownUsers, shownReplies int64) {
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/modtools/recommendations/stats?days=30&jwt="+token, nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var body struct {
		Holdout struct {
			HoldoutUsers   int64 `json:"holdoutUsers"`
			HoldoutReplies int64 `json:"holdoutReplies"`
			ShownUsers     int64 `json:"shownUsers"`
			ShownReplies   int64 `json:"shownReplies"`
		} `json:"holdout"`
	}
	json.NewDecoder(resp.Body).Decode(&body)

	return body.Holdout.HoldoutUsers, body.Holdout.HoldoutReplies,
		body.Holdout.ShownUsers, body.Holdout.ShownReplies
}

// TestRecommendationsStatsHoldoutCountsUserOnce pins the arithmetic of the holdout
// aggregation: a member with several views and several replies counts as ONE user
// whose replies are counted once each.
//
// This is the case that breaks a careless rewrite. The original query joined
// messages_likes to chat_messages on userid alone, which multiplies every view row
// by every reply the member sent, and leaned on COUNT(DISTINCT) to undo it - correct
// but unrunnable at production scale. The replacement aggregates each side
// separately, so with 3 views and 2 replies the answer must still be 1 user and
// 2 replies, not 1 and 6.
func TestRecommendationsStatsHoldoutCountsUserOnce(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("recholdout")

	support := CreateTestUser(t, prefix+"_support", "Support")
	token := getToken(t, support)

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "Member")
	viewer := CreateTestUser(t, prefix+"_viewer", "Member")

	baseHU, baseHR, baseSU, baseSR := holdoutStats(t, token)

	// Three message-page views by the one member, all inside the 30-day window.
	// The cohort is message-page-active users, so the source tag is what puts this
	// member in it at all.
	msgs := make([]uint64, 3)
	for i := 0; i < 3; i++ {
		msgs[i] = CreateTestMessage(t, poster, groupID, fmt.Sprintf("%s item %d", prefix, i), 55.9533, -3.1883)
		db.Exec("INSERT INTO messages_likes (msgid, userid, type, pageview, source, timestamp) "+
			"VALUES (?, ?, 'View', 0, 'message_page', DATE_SUB(NOW(), INTERVAL 5 DAY))", msgs[i], viewer)
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_likes WHERE userid = ?", viewer)
	})

	// Two Interested replies by the same member, also inside the window.
	chatID := CreateTestChatRoom(t, viewer, &poster, nil, "User2User")
	for i := 0; i < 2; i++ {
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired) "+
			"VALUES (?, ?, 'Interested', 'Interested', ?, DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 0)",
			chatID, viewer, msgs[i])
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
	})

	// A member who only ever viewed from browse, never a message page, plus a reply.
	// They never reached the surface the holdout gates, so they must NOT join either
	// cohort. This is the regression guard for the dilution bug: the query used to
	// count every user with any View row, which swamped both cohorts with members
	// who could never have been shown a recommendation. If that comes back, this
	// member leaks in and the exact deltas below stop matching.
	browser := CreateTestUser(t, prefix+"_browser", "Member")
	browseMsg := CreateTestMessage(t, poster, groupID, prefix+" browse item", 55.9533, -3.1883)
	db.Exec("INSERT INTO messages_likes (msgid, userid, type, pageview, source, timestamp) "+
		"VALUES (?, ?, 'View', 0, 'browse', DATE_SUB(NOW(), INTERVAL 5 DAY))", browseMsg, browser)
	browseChat := CreateTestChatRoom(t, browser, &poster, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired) "+
		"VALUES (?, ?, 'Interested', 'Interested', ?, DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 0)",
		browseChat, browser, browseMsg)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_likes WHERE userid = ?", browser)
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", browseChat)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", browseChat)
	})

	hu, hr, su, sr := holdoutStats(t, token)

	// Which cohort the member lands in is decided by the id they were assigned.
	if viewer%10 == 0 {
		assert.Equal(t, int64(1), hu-baseHU, "member counts once, not once per view")
		assert.Equal(t, int64(2), hr-baseHR, "both replies counted, not multiplied by views")
		assert.Equal(t, int64(0), su-baseSU, "shown cohort untouched")
		assert.Equal(t, int64(0), sr-baseSR, "shown cohort untouched")
	} else {
		assert.Equal(t, int64(1), su-baseSU, "member counts once, not once per view")
		assert.Equal(t, int64(2), sr-baseSR, "both replies counted, not multiplied by views")
		assert.Equal(t, int64(0), hu-baseHU, "holdout cohort untouched")
		assert.Equal(t, int64(0), hr-baseHR, "holdout cohort untouched")
	}
}

func TestRecommendationsStatsRequiresSupport(t *testing.T) {
	prefix := uniquePrefix("recstatsauth")

	// Ordinary member → 403.
	member := CreateTestUser(t, prefix+"_member", "Member")
	memberToken := getToken(t, member)
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/modtools/recommendations/stats?jwt="+memberToken, nil), 60000)
	assert.Equal(t, 403, resp.StatusCode, "non-admin must be forbidden")

	// Unauthenticated → 401.
	respAnon, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/modtools/recommendations/stats", nil), 60000)
	assert.Equal(t, 401, respAnon.StatusCode, "unauthenticated must be unauthorized")
}
