package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The 'mygroups' browse feed (GET /isochrone/message, browseView=mygroups) now stamps each
// member-group post with a distance and a rippling relevance score — the same toSummary path the
// nearby feed uses — so the distance slider and the "New to you" relevance sort work in this view
// too, not just Nearby. A closer post must score higher and sort above a far one, and both carry a
// non-negative distance.
func TestMyGroupsFeedDistanceAndScore(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("myggdist")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix+"_member")

	// 'near' at the viewer's location; 'far' ~44km (~27 miles) north — same group, same age, no
	// views/replies, so the ONLY score difference is closeness.
	near := CreateTestMessage(t, posterID, group, "OFFER: near mygroups distance (myggdist)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: far mygroups distance (myggdist)", 51.9, -0.1)
	// The browse feed shows open posts (messages_spatial.successful = 0); the helper inserts 1.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", near, far)

	// Viewer is a MEMBER of the group, browses the mygroups view, and has a known location.
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", viewerID, group)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", viewerID, group)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.browseView', 'mygroups', '$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	byID := map[uint64]message.MessageSummary{}
	nearIdx, farIdx := -1, -1
	for i, m := range msgs {
		byID[m.ID] = m
		if m.ID == near {
			nearIdx = i
		}
		if m.ID == far {
			farIdx = i
		}
	}

	assert.Contains(t, byID, near, "member-group near post appears in the mygroups feed")
	assert.Contains(t, byID, far, "member-group far post appears in the mygroups feed")

	// Every post carries a non-negative distance (miles, viewer -> blurred coords), and the far
	// post is meaningfully further than the near one.
	assert.GreaterOrEqual(t, byID[near].Distance, 0.0, "near post has a non-negative distance")
	assert.Greater(t, byID[far].Distance, byID[near].Distance,
		"the ~27-mile post is further away than the one at the viewer's location")

	// The relevance score is populated and closeness-weighted: the near post scores strictly
	// higher and sorts above the far one (was 0 for every mygroups post before this change).
	assert.Greater(t, byID[near].Score, byID[far].Score,
		"a closer member-group post scores higher than a farther one")
	assert.Greater(t, byID[near].Score, 0.0, "mygroups posts now carry a non-zero relevance score")
	assert.Less(t, nearIdx, farIdx, "the near post sorts above the far post in the mygroups feed")
}

// TestMyGroupsCountDistanceLimit: the nav badge for the 'mygroups' view (GET /message/count) now
// narrows to posts within maxDistance miles of the viewer — the SAME blurred-coordinate Haversine
// the feed exposes as `distance` — so the badge tracks the distance-filtered list. Both an explicit
// ?maxDistance= and the saved settings.browseMaxDistance are honoured.
func TestMyGroupsCountDistanceLimit(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("myggcountdist")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix+"_member")

	near := CreateTestMessage(t, posterID, group, "OFFER: near mygroups count (myggcountdist)", 51.5, -0.1)
	far := CreateTestMessage(t, posterID, group, "OFFER: far mygroups count (myggcountdist)", 51.9, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", near, far)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", near, far)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", viewerID, group)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", viewerID, group)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.browseView', 'mygroups', '$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// No limit: both unseen member-group posts are counted.
	unlimitedResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, unlimitedResp.StatusCode)
	var unlimitedBody map[string]interface{}
	json2.Unmarshal(rsp(unlimitedResp), &unlimitedBody)
	unlimitedCount := unlimitedBody["count"].(float64)
	assert.GreaterOrEqual(t, unlimitedCount, float64(2),
		"with no distance limit, the mygroups count includes both unseen posts")

	// maxDistance=10 (miles): 'near' (≈0mi) is within it, 'far' (≈27mi) is not.
	limitedResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token+"&maxDistance=10", nil))
	assert.Equal(t, 200, limitedResp.StatusCode)
	var limitedBody map[string]interface{}
	json2.Unmarshal(rsp(limitedResp), &limitedBody)
	limitedCount := limitedBody["count"].(float64)
	assert.GreaterOrEqual(t, limitedCount, float64(1), "the near post is still counted within a 10-mile limit")
	assert.Less(t, limitedCount, unlimitedCount,
		"a 10-mile limit excludes the ~27-mile post, so the mygroups count drops below unlimited")

	// settings.browseMaxDistance is honoured server-side too (no query param needed).
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseMaxDistance', 10) WHERE id = ?", viewerID)
	settingResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, settingResp.StatusCode)
	var settingBody map[string]interface{}
	json2.Unmarshal(rsp(settingResp), &settingBody)
	assert.Equal(t, limitedCount, settingBody["count"].(float64),
		"settings.browseMaxDistance is honoured for mygroups, matching the explicit query param")

	// The band default (browseReachMaxDistance, written by browse:backfill-max-distance) is
	// the INBOUND fallback for a member who never touched the slider. It has to bind, because
	// it is what holds each member to their own density band now that a post's ripple grows to
	// the ceiling rather than to its own origin's band.
	db.Exec("UPDATE users SET settings = JSON_REMOVE(COALESCE(settings,'{}'), '$.browseMaxDistance') WHERE id = ?", viewerID)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseReachMaxDistance', 10) WHERE id = ?", viewerID)
	defaultResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, defaultResp.StatusCode)
	var defaultBody map[string]interface{}
	json2.Unmarshal(rsp(defaultResp), &defaultBody)
	assert.Equal(t, limitedCount, defaultBody["count"].(float64),
		"the band default narrows the feed for a member who never chose")

	// An explicit choice beats the default, including a WIDER one: a member who moved the
	// slider has said what they want, and the default is only a default.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.browseMaxDistance', 9007199254740991) WHERE id = ?", viewerID)
	chosenResp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, chosenResp.StatusCode)
	var chosenBody map[string]interface{}
	json2.Unmarshal(rsp(chosenResp), &chosenBody)
	assert.Equal(t, unlimitedCount, chosenBody["count"].(float64),
		"an explicit unlimited choice overrides a narrower band default")
}
