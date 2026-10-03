package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// A post withdrawn while still Pending keeps its messages_groups row with deleted=1, gets
// no outcome, and never reaches messages_spatial. The own-posts arm of both browse feeds
// (it serves the viewer's posts that are not in messages_spatial yet) excluded posts with
// an outcome but not deleted rows, so withdrawn test posts came back as "posts by you" and
// the count grew with every one withdrawn (Discourse 10216/2).

// ownWithdrawnAndPending makes one own post withdrawn while Pending, and one still Pending.
func ownWithdrawnAndPending(t *testing.T, prefix string) (withdrawn, pending uint64, token string) {
	db := database.DBConn
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	withdrawn = CreateTestMessage(t, viewerID, group, prefix+" withdrawn test post", 51.5, -0.1)
	pending = CreateTestMessage(t, viewerID, group, prefix+" still pending post", 51.5, -0.1)
	for _, id := range []uint64{withdrawn, pending} {
		db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", id)
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", id)
	}
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ?", withdrawn)
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

func TestMyGroupsOmitsOwnPostWithdrawnWhilePending(t *testing.T) {
	withdrawn, pending, token := ownWithdrawnAndPending(t, uniquePrefix("mygroups_withdrawn"))
	ids := browseFeedIDs(t, "/api/message/mygroups?jwt="+token)
	assert.False(t, ids[withdrawn], "a post withdrawn while Pending is not in the mygroups feed")
	assert.True(t, ids[pending], "a post still Pending is in the mygroups feed")
}

// The bounds feed carries the same own-posts filter. It does not currently surface a still
// Pending own post at all, so only the withdrawn half is asserted here; this guards the filter
// rather than reproducing the report, which came through the mygroups feed above.
func TestBoundsOmitsOwnPostWithdrawnWhilePending(t *testing.T) {
	withdrawn, _, token := ownWithdrawnAndPending(t, uniquePrefix("bounds_withdrawn"))
	ids := browseFeedIDs(t, "/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token)
	assert.False(t, ids[withdrawn], "a post withdrawn while Pending is not in the bounds feed")
}
