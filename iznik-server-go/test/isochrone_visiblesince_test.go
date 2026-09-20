package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The browse feed ran two clocks. It ORDERED by messages.arrival - when a post was written,
// which never moves - while each card DATED itself from a group arrival, which moves when a post
// ripples into a further group and again when it is reposted. So a feed set to "Newest posted"
// printed 5 days, 4 days, 5 days, 2 hours and was, by its own sort key, in perfect order.
//
// VisibleSince is the one clock both ends now use: the oldest arrival across the groups the post
// is live on, i.e. the earliest anyone could have seen it. These tests pin it to that definition
// on the endpoints the browse card is built from - the feed summary and the full message.

// TestFeedVisibleSinceIsOldestGroupArrival: a post written weeks ago whose group row arrived
// recently (an autorepost) must date from the GROUP arrival, not from when it was written -
// while Posted still reports the write time so the card can say "first posted N days".
func TestFeedVisibleSinceIsOldestGroupArrival(t *testing.T) {
	prefix := uniquePrefix("visiblesince")
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	group := CreateTestGroup(t, prefix+"_member")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, group)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.browseView', 'mygroups') WHERE id = ?", userID)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, group)

	msg := CreateTestMessage(t, posterID, group, prefix+" repostedpost", 55.9533, -3.1883)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)

	// Written 30 days ago, but only landed on the group 3 days ago - the shape every
	// autoreposted post has.
	db.Exec("UPDATE messages SET arrival = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?", msg)
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE msgid = ?", msg)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msg {
			found = &msgs[i]
		}
	}
	assert.NotNil(t, found, "the reposted post is in the feed")
	if found == nil {
		return
	}

	daysAgo := time.Since(found.VisibleSince).Hours() / 24
	assert.InDelta(t, 3, daysAgo, 1, "VisibleSince is the group arrival (3 days), not the write time (30 days)")

	postedDaysAgo := time.Since(found.Posted).Hours() / 24
	assert.InDelta(t, 30, postedDaysAgo, 1, "Posted still reports when the post was written, so the card can say how far back it started")
}

// TestFeedVisibleSinceTakesEarliestOfSeveralGroups: a post that has rippled onward is live on
// several groups with different arrivals. The earliest is when it first became available to
// anyone, so that - not the latest ripple - is what the feed dates and orders by. Taking the
// latest is what made a long-standing post jump to the top of "Newest posted" every time its
// reach grew.
func TestFeedVisibleSinceTakesEarliestOfSeveralGroups(t *testing.T) {
	prefix := uniquePrefix("visiblesincemulti")
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	origin := CreateTestGroup(t, prefix+"_origin")
	rippled := CreateTestGroup(t, prefix+"_rippled")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, rippled)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, rippled)

	msg := CreateTestMessage(t, posterID, origin, prefix+" rippledpost", 55.9533, -3.1883)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)

	// Landed on its origin group 10 days ago, rippled into the viewer's group 2 days ago.
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE msgid = ? AND groupid = ?", msg, origin)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY), 'Approved', 0)", msg, rippled)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msg {
			found = &msgs[i]
		}
	}
	assert.NotNil(t, found, "the rippled-in post is in the feed")
	if found == nil {
		return
	}

	daysAgo := time.Since(found.VisibleSince).Hours() / 24
	assert.InDelta(t, 10, daysAgo, 1, "VisibleSince is the EARLIEST group arrival, not the latest ripple")
}

// TestMessageVisibleSinceOnFullMessage: the browse card is re-rendered from the full message once
// the store loads it, so that payload has to carry the same number. It did not, and the card's
// age silently changed the moment the store filled in - the state a member actually looks at.
func TestMessageVisibleSinceOnFullMessage(t *testing.T) {
	prefix := uniquePrefix("visiblesincefull")
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	group := CreateTestGroup(t, prefix)
	msg := CreateTestMessage(t, posterID, group, prefix+" fullmessage", 55.9533, -3.1883)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)

	db.Exec("UPDATE messages SET arrival = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?", msg)
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE msgid = ?", msg)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/"+fmt.Sprint(msg), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var m message.Message
	json2.Unmarshal(rsp(resp), &m)
	assert.Equal(t, msg, m.ID)

	daysAgo := time.Since(m.VisibleSince).Hours() / 24
	assert.InDelta(t, 3, daysAgo, 1, "the full message carries VisibleSince too, so the card's age does not change when the store loads")

	arrivalDaysAgo := time.Since(m.Arrival).Hours() / 24
	assert.InDelta(t, 30, arrivalDaysAgo, 1, "Arrival on the full message is still the write time - what the card calls 'first posted'")
}
