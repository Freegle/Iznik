package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// A post withdrawn while still Pending is soft-deleted (messages.deleted stamped), gets
// no outcome, and never reaches messages_spatial. The own-posts arm of both browse feeds
// (it serves the viewer's posts that are not in messages_spatial yet) excluded posts with
// an outcome but not deleted ones, so withdrawn test posts came back as "posts by you" and
// the count grew with every one withdrawn (Discourse 10216/2).

// ownWithdrawnAndPending makes one own post withdrawn while Pending, and one still Pending.
func ownWithdrawnAndPending(t *testing.T, prefix string) (withdrawn, pending uint64, token string) {
	db := database.DBConn
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	withdrawn = CreateTestMessage(t, viewerID, prefix+" withdrawn test post", 51.5, -0.1)
	pending = CreateTestMessage(t, viewerID, prefix+" still pending post", 51.5, -0.1)
	for _, id := range []uint64{withdrawn, pending} {
		db.Exec("UPDATE messages SET collection = 'Pending' WHERE id = ?", id)
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", id)
	}
	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", withdrawn)
	return withdrawn, pending, token
}

func browseFeedIDs(t *testing.T, url string) map[uint64]bool {
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	ids := map[uint64]bool{}
	for _, m := range msgs {
		ids[m.ID] = true
	}
	return ids
}

// The bounds feed's own-posts arm must leave out a post withdrawn while Pending. It does not
// currently surface a still Pending own post at all, so only the withdrawn half is asserted.
func TestBoundsOmitsOwnPostWithdrawnWhilePending(t *testing.T) {
	withdrawn, _, token := ownWithdrawnAndPending(t, uniquePrefix("bounds_withdrawn"))
	ids := browseFeedIDs(t, "/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token)
	assert.False(t, ids[withdrawn], "a post withdrawn while Pending is not in the bounds feed")
}

// The nearby feed is the default Browse view, and its own-posts arm admits a post with a
// Pending group row. Withdrawing leaves that row Pending but deleted, so without a check on
// deleted the withdrawn post still counted as one of "your posts" (Discourse 10216/7).
func TestNearbyOmitsOwnPostWithdrawnWhilePending(t *testing.T) {
	withdrawn, pending, token := ownWithdrawnAndPending(t, uniquePrefix("nearby_withdrawn"))
	db := database.DBConn
	// The own-posts arm needs messages.lat/lng; withdrawal also soft-deletes the message.
	db.Exec("UPDATE messages SET lat = 51.5, lng = -0.1 WHERE id IN (?, ?)", withdrawn, pending)
	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", withdrawn)
	ids := browseFeedIDs(t, "/api/isochrone/message?jwt="+token)
	assert.False(t, ids[withdrawn], "a post withdrawn while Pending is not in the nearby feed")
	assert.True(t, ids[pending], "a post still Pending is in the nearby feed")
}
