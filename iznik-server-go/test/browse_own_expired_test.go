package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The browse feeds (GET /isochrone/message and GET /message/inbounds) deliberately include the
// viewer's own recent posts regardless of reach/spatial state, so a poster sees their own post
// immediately (even while awaiting moderation). That "own posts" arm queries the messages table
// directly, so it bypasses the messages_spatial pruning that removes expired posts for everyone
// else. Without applying the same age-based expiry the My Posts endpoint uses, a poster's own post
// that has aged out of its group's display window kept showing on browse even though My Posts had
// already hidden it as old. These tests assert an aged-out own post is excluded while a fresh one
// is still shown.
//
// The group is given a short display window (maxagetoshow) so a post can be past expiry while still
// inside the 90-day own-posts window (older than 90 days and the own-posts arm wouldn't fetch it at
// all, so there'd be nothing to filter).
func setupOwnExpiryGroup(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	db.Exec("UPDATE `groups` SET settings = ? WHERE id = ?",
		`{"maxagetoshow":20,"reposts":{"offer":1,"wanted":1,"max":1}}`, group)
	return group
}

func TestBrowseOwnExpiredHiddenIsochrone(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ownexpiso")
	group := setupOwnExpiryGroup(t, prefix)

	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	// The own-posts arm only runs when the viewer has a location.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 53.0, 'lng', -2.0)) WHERE id = ?", posterID)

	fresh := CreateTestMessageWithArrival(t, posterID, group, "OFFER: fresh own post ("+prefix+")", 53.0, -2.0, 5)
	old := CreateTestMessageWithArrival(t, posterID, group, "OFFER: aged-out own post ("+prefix+")", 53.0, -2.0, 45)
	// The helper doesn't set messages.lat/lng (real posts have them); the own-posts arm needs them.
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id IN (?, ?)", fresh, old)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[fresh], "a fresh own post still appears on the nearby feed")
	assert.False(t, got[old], "an own post aged out of its group's display window is hidden on the nearby feed, matching My Posts")
}

func TestBrowseOwnExpiredHiddenBounds(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ownexpbnd")
	group := setupOwnExpiryGroup(t, prefix)

	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 53.0, 'lng', -2.0)) WHERE id = ?", posterID)

	fresh := CreateTestMessageWithArrival(t, posterID, group, "OFFER: fresh own post ("+prefix+")", 53.0, -2.0, 5)
	old := CreateTestMessageWithArrival(t, posterID, group, "OFFER: aged-out own post ("+prefix+")", 53.0, -2.0, 45)
	// The helper doesn't set messages.lat/lng (real posts have them); the own-posts arm's bounds
	// containment test is on POINT(messages.lng, messages.lat), so without them it matches nothing.
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id IN (?, ?)", fresh, old)

	url := fmt.Sprintf("/api/message/inbounds?swlat=%f&swlng=%f&nelat=%f&nelng=%f&jwt=%s",
		52.9, -2.1, 53.1, -1.9, token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[fresh], "a fresh own post still appears on the in-bounds feed")
	assert.False(t, got[old], "an own post aged out of its group's display window is hidden on the in-bounds feed, matching My Posts")
}

// Age isn't the only way an own post leaves My Posts: an Approved post that has been pruned from
// messages_spatial (expired, withdrawn, deleted or taken) drops off My Posts immediately because
// its query requires spatialid IS NOT NULL. The browse own-posts arm queries the messages table
// directly, so before this fix a still-recent (arrival within the 90-day own-posts window) Approved
// post with no outcome row yet kept showing on browse even though it was gone from spatial and gone
// from My Posts. These assert the own-posts arm matches My Posts' "in spatial OR Pending/Rejected"
// active-set: pruned-from-spatial Approved posts are hidden; Pending posts (never in spatial) still
// show so the poster sees a post awaiting moderation.
func TestBrowseOwnPrunedFromSpatialHidden(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ownprunedspatial")
	group := CreateTestGroup(t, prefix)

	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	// The own-posts arm only runs when the viewer has a location.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 53.0, 'lng', -2.0)) WHERE id = ?", posterID)

	// All three are recent (3 days) so age expiry is not what's being tested here.
	active := CreateTestMessageWithArrival(t, posterID, group, "OFFER: active own post ("+prefix+")", 53.0, -2.0, 3)
	pruned := CreateTestMessageWithArrival(t, posterID, group, "OFFER: pruned own post ("+prefix+")", 53.0, -2.0, 3)
	pending := CreateTestMessageWithArrival(t, posterID, group, "OFFER: pending own post ("+prefix+")", 53.0, -2.0, 3)
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id IN (?, ?, ?)", active, pruned, pending)

	// 'pruned' is Approved with no outcome row but has been removed from messages_spatial, exactly
	// like an expired/withdrawn post between the daily prune and the outcome-row write.
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", pruned)
	// 'pending' is awaiting moderation: never in spatial, collection Pending.
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", pending)
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", pending)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[active], "an active own post still in messages_spatial appears on the nearby feed")
	assert.False(t, got[pruned], "an own post pruned from messages_spatial (expired/withdrawn) is hidden on the nearby feed, matching My Posts")
	assert.True(t, got[pending], "a Pending own post (never in spatial) still shows so the poster sees it awaiting moderation")
}
