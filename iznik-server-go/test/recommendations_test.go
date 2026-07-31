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

// TestRecommendationsStatsHoldoutSplitsByRecordedArm pins the aggregation of the
// random holdout: each arm is read from the impression tag the message page recorded
// (similar_posts = shown, similar_posts_holdout = control), not from the user id.
//
// It pins three things a careless rewrite breaks:
//   - Count-once arithmetic: a member with several views and several replies is ONE
//     user whose replies are counted once each (3 views + 2 replies -> 1 and 2, not
//     1 and 6). The join aggregates each side separately to stay runnable at scale.
//   - Disjoint arms: a member shown on one post and held out on another counts as
//     shown, never in both, because assignment is per-view.
//   - Eligibility: a member who only ever viewed from browse never recorded either
//     tag, so they join neither cohort.
func TestRecommendationsStatsHoldoutSplitsByRecordedArm(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("recholdout")

	support := CreateTestUser(t, prefix+"_support", "Support")
	token := getToken(t, support)

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "Member")

	baseHU, baseHR, baseSU, baseSR := holdoutStats(t, token)

	// seedViews records n impressions of the given source for a member, and cleans up.
	seedViews := func(member uint64, source string, n int) {
		for i := 0; i < n; i++ {
			msg := CreateTestMessage(t, poster, groupID, fmt.Sprintf("%s %s %d", prefix, source, i), 55.9533, -3.1883)
			db.Exec("INSERT INTO messages_likes (msgid, userid, type, pageview, source, timestamp) "+
				"VALUES (?, ?, 'View', 0, ?, DATE_SUB(NOW(), INTERVAL 5 DAY))", msg, member, source)
		}
		t.Cleanup(func() { db.Exec("DELETE FROM messages_likes WHERE userid = ?", member) })
	}
	// seedReplies records n Interested replies by a member, and cleans up.
	seedReplies := func(member uint64, n int) {
		chatID := CreateTestChatRoom(t, member, &poster, nil, "User2User")
		for i := 0; i < n; i++ {
			msg := CreateTestMessage(t, poster, groupID, fmt.Sprintf("%s reply %d %d", prefix, member, i), 55.9533, -3.1883)
			db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, processingsuccessful, reviewrequired) "+
				"VALUES (?, ?, 'Interested', 'Interested', ?, DATE_SUB(NOW(), INTERVAL 4 DAY), 1, 0)",
				chatID, member, msg)
		}
		t.Cleanup(func() {
			db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
			db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
		})
	}

	// Holdout member: 3 held-out views + 2 replies -> holdout cohort +1 user, +2 replies.
	holdoutViewer := CreateTestUser(t, prefix+"_holdout", "Member")
	seedViews(holdoutViewer, "similar_posts_holdout", 3)
	seedReplies(holdoutViewer, 2)

	// Shown member: 3 shown views + 1 reply -> shown cohort +1 user, +1 reply.
	shownViewer := CreateTestUser(t, prefix+"_shown", "Member")
	seedViews(shownViewer, "similar_posts", 3)
	seedReplies(shownViewer, 1)

	// Member shown on one post and held out on another: ever-shown wins, so they land
	// in the shown cohort only (+1 user, +1 reply), never the holdout.
	bothViewer := CreateTestUser(t, prefix+"_both", "Member")
	seedViews(bothViewer, "similar_posts", 1)
	seedViews(bothViewer, "similar_posts_holdout", 1)
	seedReplies(bothViewer, 1)

	// A member who only ever viewed from browse, plus a reply: they never recorded
	// either arm's tag, so they must NOT join either cohort. Regression guard for the
	// dilution bug where any View row swamped both cohorts.
	browser := CreateTestUser(t, prefix+"_browser", "Member")
	seedViews(browser, "browse", 1)
	seedReplies(browser, 1)

	hu, hr, su, sr := holdoutStats(t, token)

	assert.Equal(t, int64(1), hu-baseHU, "one holdout member, counted once not once per view")
	assert.Equal(t, int64(2), hr-baseHR, "the holdout member's two replies, not multiplied by views")
	assert.Equal(t, int64(2), su-baseSU, "shown + both members, each counted once")
	assert.Equal(t, int64(2), sr-baseSR, "shown member's reply + both member's reply")
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
