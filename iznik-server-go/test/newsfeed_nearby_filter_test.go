package test

import (
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/stretchr/testify/assert"
)

// Regression test for Discourse #9937 post 1: selecting "Nearby" in the
// ChitChat distance filter showed the same unfiltered feed as "Anywhere",
// with a post 169 miles away appearing at the top.
//
// Root cause: GetNearbyDistance returns 0 ("give up") whenever the spatial
// "newsfeed" KNN index can't supply nearbyLimit (10) candidates, and
// getFeed() treats a 0 return as "apply no geographic filtering at all" -
// exactly the same code path used for "Anywhere". The test spatial mock
// (ensureSpatialMock in main_test.go) only serves the "postcodes" dataset
// and returns an empty result for every other dataset including "newsfeed",
// so this exercises exactly that fallback.
func TestFeedNearbyDistanceExcludesFarAwayPost(t *testing.T) {
	prefix := uniquePrefix("nearbyfilter")
	userID, token := CreateFullTestUser(t, prefix)

	// A post right where the user is (Edinburgh, set by CreateTestUser).
	localMessage := "Local test post " + prefix
	CreateTestNewsfeed(t, userID, 55.9533, -3.1883, localMessage)

	// A post in London - about 534km / 332 miles from Edinburgh, far beyond
	// any reasonable "Nearby" radius.
	farMessage := "Far away test post " + prefix
	CreateTestNewsfeed(t, userID, 51.5074, -0.1278, farMessage)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed?distance=nearby&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var feed []newsfeed.NewsfeedSummary
	json.Unmarshal(rsp(resp), &feed)

	ids := make(map[uint64]bool)
	for _, entry := range feed {
		ids[entry.ID] = true
	}

	localID := findNewsfeedIDByMessage(t, localMessage)
	farID := findNewsfeedIDByMessage(t, farMessage)

	assert.True(t, ids[localID], "the local post should be in the Nearby feed")

	// The far-away post must NOT leak into "Nearby". Before the fix,
	// GetNearbyDistance fell back to an unfiltered (zero/disabled) radius
	// whenever it couldn't establish a data-driven one, so this assertion
	// fails on the bug and passes once Nearby is bounded.
	assert.False(t, ids[farID], "far-away post should NOT appear under Nearby")
}

func findNewsfeedIDByMessage(t *testing.T, message string) uint64 {
	var id uint64
	database.DBConn.Raw("SELECT id FROM newsfeed WHERE message = ? ORDER BY id DESC LIMIT 1", message).Scan(&id)
	if id == 0 {
		t.Fatalf("could not find newsfeed entry for message %q", message)
	}
	return id
}
