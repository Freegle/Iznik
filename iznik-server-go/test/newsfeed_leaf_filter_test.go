package test

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// Road-aware ChitChat narrowing: with a travel-time budget (minutes=) and the
// reach engine answering, a thread tagged with a road-network region the
// member CANNOT reach by road drops out of the feed even though it is inside
// the crow-flies radius - the estuary's far bank. Untagged threads, and the
// whole feed when no minutes are sent, keep the pure radius behaviour.
func TestFeedLeafNarrowing(t *testing.T) {
	prefix := uniquePrefix("leafnarrow")
	userID, token := CreateFullTestUser(t, prefix)

	// All three posts are AT the member's location (Edinburgh), so the radius
	// alone keeps every one of them.
	reachableMsg := "Reachable region post " + prefix
	reachableID := CreateTestNewsfeed(t, userID, 55.9533, -3.1883, reachableMsg)
	unreachableMsg := "Unreachable region post " + prefix
	unreachableID := CreateTestNewsfeed(t, userID, 55.9540, -3.1890, unreachableMsg)
	untaggedMsg := "Untagged post " + prefix
	untaggedID := CreateTestNewsfeed(t, userID, 55.9520, -3.1870, untaggedMsg)

	// Tag: one in a region the member's budget reaches (111), one across the
	// water (222), one from before tagging existed (NULL).
	database.DBConn.Exec("UPDATE newsfeed SET leaf = 111 WHERE id = ?", reachableID)
	database.DBConn.Exec("UPDATE newsfeed SET leaf = 222 WHERE id = ?", unreachableID)

	// Stub routing server: the member's 30-minute budget reaches region 111
	// (and 333) but not 222.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/reach-labels" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"leaves": []int32{111, 333}})
	}))
	defer srv.Close()
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)
	roadblur.ResetRoutingBreaker()

	feedIDs := func(query string) map[uint64]bool {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed?"+query+"&jwt="+token, nil), 30000)
		assert.Equal(t, 200, resp.StatusCode)
		var feed []newsfeed.NewsfeedSummary
		json.Unmarshal(rsp(resp), &feed)
		ids := make(map[uint64]bool)
		for _, e := range feed {
			ids[e.ID] = true
		}
		return ids
	}

	// With a budget: the unreachable-region thread drops, everything else stays.
	ids := feedIDs(fmt.Sprintf("distance=%d&minutes=30", 16093))
	assert.True(t, ids[reachableID], "reachable-region thread stays")
	assert.True(t, ids[untaggedID], "untagged thread keeps the radius behaviour")
	assert.False(t, ids[unreachableID], "a region the member cannot drive to within the budget is out")

	// Without a budget: pure radius, all three present - engine availability
	// must never change behaviour for members who haven't sent one.
	ids = feedIDs(fmt.Sprintf("distance=%d", 16093))
	assert.True(t, ids[reachableID])
	assert.True(t, ids[untaggedID])
	assert.True(t, ids[unreachableID], "no budget sent: the radius alone decides")
}
