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

// The member's own post is theirs to see the moment they post it, so the filter
// exempts the author. The own-posts arm of the mygroups feed cannot cover that
// on its own: it only serves posts not yet in messages_spatial.
func TestPendingReachNeverHidesYourOwnPost(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("pendingreachown")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")

	msgID := CreateTestMessage(t, viewerID, prefix+" my own offer", 51.5, -0.1)
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
