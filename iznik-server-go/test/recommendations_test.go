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
