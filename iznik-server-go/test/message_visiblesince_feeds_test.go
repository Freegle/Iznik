package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The browse list runs on ONE clock, visibleSince (isochrone_visiblesince_test.go): the
// client's "Newest posted" sort and each card's age badge both read it, so the order can never
// contradict the ages printed on it. That rule reached the reach feed and the full message
// record only. "All my communities" and a single community render from /message/mygroups, and
// a moved map from /message/inbounds - and both answered with a zero visibleSince (inbounds
// had no posted either). The client then sorted by its fallback while every card, re-rendered
// from the full message, printed the group arrival: a member on Newest posted saw
// 27 days, 7 days, 3 days, 28 days (Discourse 9808/801-802). The list locks its order at first
// paint, so the full records loading a moment later could not repair it - the summary itself
// has to carry the field.

// visibleSinceFixture is a post written 30 days ago, reposted onto its group 3 days ago (the
// group row's arrival moved, as AutoRepostService does), rippled into a further group the
// viewer is not in 1 day ago, with a deleted group row from 20 days ago that must not count,
// and a spatial arrival bumped to now by that ripple.
func visibleSinceFixture(t *testing.T, prefix string) (viewerToken string, msgID uint64, cleanup func()) {
	db := database.DBConn

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	origin := CreateTestGroup(t, prefix+"_origin")
	rippled := CreateTestGroup(t, prefix+"_rippled")
	gone := CreateTestGroup(t, prefix+"_gone")
	CreateTestMembership(t, viewerID, origin, "Member")
	CreateTestMembership(t, posterID, origin, "Member")

	msgID = CreateTestMessage(t, posterID, origin, prefix+" reposted offer", 51.5, -0.1)

	db.Exec("UPDATE messages SET arrival = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?", msgID)
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE msgid = ? AND groupid = ?", msgID, origin)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY), 'Approved', 0)", msgID, rippled)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, deleted) "+
		"VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 20 DAY), 'Approved', 0, 1)", msgID, gone)
	db.Exec("UPDATE messages_spatial SET arrival = NOW() WHERE msgid = ?", msgID)

	cleanup = func() {
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	}
	return token, msgID, cleanup
}

func assertOneClock(t *testing.T, feed string, msgs []message.MessageSummary, msgID uint64) {
	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msgID {
			found = &msgs[i]
			break
		}
	}
	if !assert.NotNil(t, found, "%s returns the post", feed) {
		return
	}

	assert.False(t, found.VisibleSince.IsZero(), "%s carries visibleSince", feed)
	daysAgo := time.Since(found.VisibleSince).Hours() / 24
	assert.InDelta(t, 3, daysAgo, 1,
		"%s: visibleSince is the OLDEST LIVE group arrival (the 3-day repost) - not the write time (30 days), not the onward ripple (1 day), not the deleted row (20 days): got %v", feed, found.VisibleSince)

	assert.False(t, found.Posted.IsZero(), "%s carries posted", feed)
	postedDaysAgo := time.Since(found.Posted).Hours() / 24
	assert.InDelta(t, 30, postedDaysAgo, 1,
		"%s: posted is still when the post was written, so the card can say 'first posted 30 days': got %v", feed, found.Posted)
}

// TestMyGroupsVisibleSinceIsOldestGroupArrival: /message/mygroups (message.Groups) - the feed
// behind "All my communities" and a single community - dates each post by the same clock the
// card prints.
func TestMyGroupsVisibleSinceIsOldestGroupArrival(t *testing.T) {
	token, msgID, cleanup := visibleSinceFixture(t, uniquePrefix("mygroups_visiblesince"))
	defer cleanup()

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	assertOneClock(t, "/message/mygroups", msgs, msgID)
}

// TestBoundsVisibleSinceIsOldestGroupArrival: /message/inbounds (message.Bounds) - what browse
// switches to the moment the member moves the map - dates each post by the same clock too.
func TestBoundsVisibleSinceIsOldestGroupArrival(t *testing.T) {
	token, msgID, cleanup := visibleSinceFixture(t, uniquePrefix("bounds_visiblesince"))
	defer cleanup()

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	assertOneClock(t, "/message/inbounds", msgs, msgID)
}
