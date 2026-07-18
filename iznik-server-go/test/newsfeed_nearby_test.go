package test

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Regression test for the PR #459 review: the spatial "newsfeed" index dropped
// the type!=ALERT and 31-day recency filters that the old GetNearbyDistance
// query applied. RecentNonAlertNewsfeedIDs restores them so alerts and stale
// posts don't shrink the computed "nearby" radius.
func TestRecentNonAlertNewsfeedIDs(t *testing.T) {
	uid := CreateTestUser(t, "nearbydist", "User")
	lat, lng := 51.5, -0.1

	recentMsg := CreateTestNewsfeedWithType(t, uid, lat, lng, "recent message", "Message", 24*5) // 5 days ago
	oldMsg := CreateTestNewsfeedWithType(t, uid, lat, lng, "stale message", "Message", 24*40)    // 40 days ago
	recentAlert := CreateTestNewsfeedWithType(t, uid, lat, lng, "recent alert", utils.NEWSFEED_TYPE_ALERT, 24*5)

	allowed := newsfeed.RecentNonAlertNewsfeedIDs([]int64{int64(recentMsg), int64(oldMsg), int64(recentAlert)})

	_, hasRecent := allowed[int64(recentMsg)]
	_, hasOld := allowed[int64(oldMsg)]
	_, hasAlert := allowed[int64(recentAlert)]

	assert.True(t, hasRecent, "recent non-alert post should count towards the nearby radius")
	assert.False(t, hasOld, "post older than 31 days should be excluded")
	assert.False(t, hasAlert, "alert post should be excluded")
}

// mockNewsfeedKNN points the spatial client at an in-process server that
// answers /v1/newsfeed/knn with the given ids/distances (already in the
// order GetNearbyDistance expects: nearest first) and returns the original
// SPATIAL_KNN_URL handler for every other path. Returns a restore func that
// must be deferred to put SPATIAL_KNN_URL back so later tests are unaffected.
func mockNewsfeedKNN(t *testing.T, ids []int64, dists []float64) func() {
	t.Helper()
	original := os.Getenv("SPATIAL_KNN_URL")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if !strings.Contains(r.URL.Path, "/newsfeed/knn") {
			fmt.Fprint(w, `{"results":[]}`)
			return
		}
		var b strings.Builder
		b.WriteString(`{"results":[`)
		for i, id := range ids {
			if i > 0 {
				b.WriteString(",")
			}
			fmt.Fprintf(&b, `{"id":%d,"distance":%g}`, id, dists[i])
		}
		b.WriteString(`]}`)
		fmt.Fprint(w, b.String())
	}))

	os.Setenv("SPATIAL_KNN_URL", srv.URL)

	return func() {
		srv.Close()
		os.Setenv("SPATIAL_KNN_URL", original)
	}
}

// Regression test for Discourse #9937: when the spatial KNN index has enough
// raw candidates (any age/type) but too few pass the recent/non-alert filter
// to reach nearbyLimit, GetNearbyDistance used to give up and return distance
// 0 - which callers treat identically to "no restriction at all", making
// "Nearby" behave exactly like "Anywhere". This is the realistic production
// failure mode: nationwide only a small fraction of newsfeed posts are recent
// and non-alert, so quieter areas routinely fail to find 10 of them among
// their nearest 100 raw spatial candidates.
func TestGetNearbyDistanceFallsBackToRawKNNWhenTooFewRecentPosts(t *testing.T) {
	uid := CreateTestUser(t, "nearbyfallback", "User")

	// CreateTestUser sets settings.mylocation to this Edinburgh point, which
	// is what user.GetLatLng(uid) will return.
	lat, lng := 55.9533, -3.1883

	var ids []int64
	var dists []float64

	addPoint := func(hoursAgo int) {
		id := CreateTestNewsfeedWithType(t, uid, lat, lng, fmt.Sprintf("post %d", len(ids)), "Message", hoursAgo)
		ids = append(ids, int64(id))
		dists = append(dists, 0.001*float64(len(ids)))
	}

	// Only 3 recent, non-alert posts - fewer than the nearbyLimit (10) that
	// RecentNonAlertNewsfeedIDs needs to size the "nearby" radius by itself.
	for i := 0; i < 3; i++ {
		addPoint(24) // 1 day ago
	}
	// 12 more raw KNN candidates (stale, >31 days) so the spatial index has
	// plenty of points to draw a fallback radius from even though they don't
	// pass the recency filter.
	for i := 0; i < 12; i++ {
		addPoint(24 * 40) // 40 days ago
	}

	restore := mockNewsfeedKNN(t, ids, dists)
	defer restore()

	dist, _, _, _, _, _ := newsfeed.GetNearbyDistance(uid)

	assert.Greater(t, dist, 0.0, "Nearby should still apply a geographic radius when there aren't enough recent posts to size one from alone, rather than silently becoming Anywhere")
}
