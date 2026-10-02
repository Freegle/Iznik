package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// A post is not live until its reach has been calculated. Rippling starts a post
// small and grows it, so a post with no rippling_reach row has no audience at
// all - browse offered it anyway and the reply gate then refused, which is what
// "this hasn't reached your area yet" looked like from the member's side.
//
// The gate is a grace period rather than a requirement, because 132 browsable
// posts have no row and never will: their origin cannot snap to the road graph.
// See rippling.ReachPendingFilter.

// seedPendingReachRow gives a post the bare reach row the feeds test for. Only
// its existence is read here, so no grid is needed - but outer_bound is NOT
// NULL with no default, so it still has to be given one.
func seedPendingReachRow(t *testing.T, msgid uint64) {
	t.Helper()
	res := database.DBConn.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, tick, total_ticks, status) "+
		"VALUES (?, 51.5, -0.1, ST_Envelope(ST_GeomFromText('LINESTRING(0 0, 1 1)', 3857)), 1, 3, 'expanding') "+
		"ON DUPLICATE KEY UPDATE tick = VALUES(tick)", msgid)
	if res.Error != nil {
		t.Fatalf("could not seed reach: %v", res.Error)
	}
}

func feedHasMessage(t *testing.T, url string, msgid uint64) bool {
	t.Helper()
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	for _, m := range msgs {
		if m.ID == msgid {
			return true
		}
	}

	return false
}

// The three states a post can be in, on both feeds that show other members'
// posts from the spatial index.
func TestPendingReachGatesBrowseFeeds(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("pendingreach")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	CreateTestMembership(t, posterID, group, "Member")

	msgID := CreateTestMessage(t, posterID, group, prefix+" offer", 51.5, -0.1)
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// The fixtures are back-dated past the grace period; this one is testing the
	// grace period, so put it back to just-posted.
	db.Exec("UPDATE messages SET arrival = NOW() WHERE id = ?", msgID)

	mygroups := "/api/message/mygroups?jwt=" + token
	bounds := "/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt=" + token

	// Just posted, reach not calculated yet: not live, so not shown.
	assert.False(t, feedHasMessage(t, mygroups, msgID),
		"a post whose reach has not been calculated is not live in the mygroups feed")
	assert.False(t, feedHasMessage(t, bounds, msgID),
		"a post whose reach has not been calculated is not live in the bounds feed")

	// Reach lands - typically within a minute - and the post goes live.
	seedPendingReachRow(t, msgID)
	assert.True(t, feedHasMessage(t, mygroups, msgID),
		"a post with reach is live in the mygroups feed")
	assert.True(t, feedHasMessage(t, bounds, msgID),
		"a post with reach is live in the bounds feed")

	// Reach that never arrives must not hide the post for ever: some origins
	// cannot snap to the road graph and will never get a row.
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	db.Exec("UPDATE messages SET arrival = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?", msgID)
	assert.True(t, feedHasMessage(t, mygroups, msgID),
		"a post that waited out the grace period is shown in the mygroups feed regardless")
	assert.True(t, feedHasMessage(t, bounds, msgID),
		"a post that waited out the grace period is shown in the bounds feed regardless")
}

// The member's own post is theirs to see the moment they post it, so the filter
// exempts the author. The own-posts arm of the mygroups feed cannot cover that
// on its own: it only serves posts not yet in messages_spatial.
func TestPendingReachNeverHidesYourOwnPost(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("pendingreachown")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")

	msgID := CreateTestMessage(t, viewerID, group, prefix+" my own offer", 51.5, -0.1)
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Inside the grace period, which is the only window where the author
	// exemption does any work.
	db.Exec("UPDATE messages SET arrival = NOW() WHERE id = ?", msgID)

	assert.True(t, feedHasMessage(t, "/api/message/mygroups?jwt="+token, msgID),
		"a member sees their own post before its reach is calculated")
	assert.True(t, feedHasMessage(t,
		"/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token, msgID),
		"a member sees their own post on the map before its reach is calculated")
}
