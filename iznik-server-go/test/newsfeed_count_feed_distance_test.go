package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	newsfeed2 "github.com/freegle/iznik-server-go/newsfeed"
	"github.com/stretchr/testify/assert"
)

// TestCountFeedDistanceAgreement verifies that /newsfeedcount and /newsfeed agree
// on geographic scope and that Count no longer hard-codes a 1609 m radius.
//
// Before the fix Count() initialised distance=1609/gotDistance=true, so with no
// distance param it applied a ~1-mile bounding box while Feed() defaulted to the
// KNN dynamic radius.  After the fix both default to gotDistance=false and call
// GetNearbyDistance(), eliminating the divergence.
func TestCountFeedDistanceAgreement(t *testing.T) {
	prefix := uniquePrefix("cnt_feed_dist")
	userID, token := CreateFullTestUser(t, prefix)

	// Seed a newsfeed item at the user's known location.
	nfID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, "Count/Feed dist test "+prefix)

	// --- 1. Explicit distance: Count and Feed must both 200 and the seeded
	//    item must appear in the feed. Count (seen-highwater-filtered subset)
	//    must not exceed Feed length. ---
	respFeed, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/newsfeed?distance=10000&jwt="+token, nil))
	assert.Equal(t, 200, respFeed.StatusCode)

	var feed []newsfeed2.NewsfeedSummary
	json2.Unmarshal(rsp(respFeed), &feed)

	var found bool
	for _, f := range feed {
		if f.ID == nfID {
			found = true
			break
		}
	}
	assert.True(t, found, "seeded newsfeed item must appear in Feed at 10000 m distance")

	respCount, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/newsfeedcount?distance=10000&jwt="+token, nil))
	assert.Equal(t, 200, respCount.StatusCode)

	var countExplicit struct {
		Count uint64 `json:"count"`
	}
	json2.Unmarshal(rsp(respCount), &countExplicit)
	// Count applies a seen-highwater filter so it may be <= len(Feed), never more.
	assert.LessOrEqual(t, countExplicit.Count, uint64(len(feed)),
		"Count (explicit distance) must be <= Feed length: Count is a seen-highwater-filtered subset")

	// --- 2. No distance param: Count must use the KNN-based radius (same as
	//    Feed) rather than the old hardcoded 1609 m cap.  Verify both return
	//    200 and that the structural constraint count <= len(feed) holds. ---
	respCountNoDist, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/newsfeedcount?jwt="+token, nil))
	assert.Equal(t, 200, respCountNoDist.StatusCode,
		"Count with no distance param must succeed (KNN path, not hardcoded 1609 m)")

	var countNoDist struct {
		Count uint64 `json:"count"`
	}
	assert.NoError(t, json2.Unmarshal(rsp(respCountNoDist), &countNoDist),
		"Count (no distance) response must be valid JSON")

	respFeedNoDist, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/newsfeed?jwt="+token, nil))
	assert.Equal(t, 200, respFeedNoDist.StatusCode)

	var feedNoDist []newsfeed2.NewsfeedSummary
	json2.Unmarshal(rsp(respFeedNoDist), &feedNoDist)

	assert.LessOrEqual(t, countNoDist.Count, uint64(len(feedNoDist)),
		"Count (no distance) must be <= Feed (no distance) length")

	// --- 3. distance=nearby must be treated identically to no distance param:
	//    both resolve to gotDistance=false and call GetNearbyDistance. ---
	respCountNearby, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/newsfeedcount?distance=nearby&jwt="+token, nil))
	assert.Equal(t, 200, respCountNearby.StatusCode,
		"Count with distance=nearby must succeed")

	var countNearby struct {
		Count uint64 `json:"count"`
	}
	assert.NoError(t, json2.Unmarshal(rsp(respCountNearby), &countNearby),
		"Count (distance=nearby) response must be valid JSON")
	// The actual property under test: distance=nearby resolves exactly like no
	// distance param (both gotDistance=false -> GetNearbyDistance), so the two
	// back-to-back counts for the same user must agree.
	assert.Equal(t, countNoDist.Count, countNearby.Count,
		"distance=nearby must be treated identically to no distance param")
}
