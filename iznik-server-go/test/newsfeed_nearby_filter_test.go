package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	newsfeed2 "github.com/freegle/iznik-server-go/newsfeed"
	"github.com/stretchr/testify/assert"
)

// Regression test for Discourse #9937 post 1: selecting "Nearby" in the
// ChitChat distance filter showed the same unfiltered feed as "Anywhere",
// with a post 169 miles away appearing at the top.
//
// GetNearbyDistance falls back to "no reasonable radius found" whenever the
// spatial "newsfeed" KNN index can't supply nearbyLimit (10) recent,
// non-alert candidates near the user - which is exactly what the test
// spatial mock does (it only serves the "postcodes" dataset; every other
// dataset, including "newsfeed", gets an empty result - see
// ensureSpatialMock in main_test.go). The pre-#459 implementation always
// bounded this fallback to a maximum search radius; the #459 rewrite
// returned 0, which callers (newsfeed.getFeed) treat as "apply no
// geographic filtering at all".
func TestFeedNearbyDistanceExcludesFarAwayPost(t *testing.T) {
	prefix := uniquePrefix("nearbyfilter")
	userID, token := CreateFullTestUser(t, prefix)

	// A post right where the user is (Edinburgh, set by CreateTestUser).
	localMessage := fmt.Sprintf("Local test post %s", prefix)
	CreateTestNewsfeed(t, userID, 55.9533, -3.1883, localMessage)

	// A post in London - about 534km / 332 miles from Edinburgh, far beyond
	// any reasonable "Nearby" radius.
	farMessage := fmt.Sprintf("Far away test post %s", prefix)
	CreateTestNewsfeed(t, userID, 51.5074, -0.1278, farMessage)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed?distance=nearby&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var feed []newsfeed2.NewsfeedSummary
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
	// whenever it couldn't find nearbyLimit recent local candidates, so this
	// assertion fails on the bug and passes once Nearby is bounded.
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
