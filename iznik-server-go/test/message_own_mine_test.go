package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestMyGroupsOwnPostFlaggedMine: /message/mygroups (message.Groups) is the "All my communities"
// and single-group browse feed. Like the nearby/reach feed it must flag the viewer's own posts
// with `mine`, because that flag is the ONLY thing the client has to pin own posts to the top of
// every sort order and to lift them into the "N posts by you" row. Without it members reported
// having to switch the browse view to Nearby and the sort to Closest before their own post
// surfaced - Closest happened to work only because your own post is the nearest post to you.
func TestMyGroupsOwnPostFlaggedMine(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mygroups_mine")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	otherID := CreateTestUser(t, prefix+"_other", "Other")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	CreateTestMembership(t, otherID, group, "Member")

	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	own := CreateTestMessage(t, viewerID, group, prefix+" my own offer", 51.5, -0.1)
	rival := CreateTestMessage(t, otherID, group, prefix+" someone else's offer", 51.5, -0.1)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	byID := map[uint64]message.MessageSummary{}
	for _, m := range msgs {
		byID[m.ID] = m
	}

	ownMsg, ownPresent := byID[own]
	assert.True(t, ownPresent, "the viewer's own post appears in the mygroups feed")
	assert.True(t, ownMsg.Mine, "the viewer's own post is flagged mine in the mygroups feed")

	rivalMsg, rivalPresent := byID[rival]
	assert.True(t, rivalPresent, "another member's post appears in the mygroups feed")
	assert.False(t, rivalMsg.Mine, "another member's post is not flagged mine")
}

// TestBoundsOwnPostFlaggedMine: /message/bounds is what the browse feed switches to as soon as the
// member moves the map, so it must flag own posts too - otherwise a member's own post is pinned
// before they touch the map and unpinned the moment they pan it.
func TestBoundsOwnPostFlaggedMine(t *testing.T) {
	prefix := uniquePrefix("bounds_mine")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	otherID := CreateTestUser(t, prefix+"_other", "Other")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	CreateTestMembership(t, otherID, group, "Member")

	own := CreateTestMessage(t, viewerID, group, prefix+" my own offer", 51.5, -0.1)
	rival := CreateTestMessage(t, otherID, group, prefix+" someone else's offer", 51.5, -0.1)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	byID := map[uint64]message.MessageSummary{}
	for _, m := range msgs {
		byID[m.ID] = m
	}

	ownMsg, ownPresent := byID[own]
	assert.True(t, ownPresent, "the viewer's own post appears in the bounds feed")
	assert.True(t, ownMsg.Mine, "the viewer's own post is flagged mine in the bounds feed")

	rivalMsg, rivalPresent := byID[rival]
	assert.True(t, rivalPresent, "another member's post appears in the bounds feed")
	assert.False(t, rivalMsg.Mine, "another member's post is not flagged mine")
}
