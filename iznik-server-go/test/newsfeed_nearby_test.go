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

// maxNearbyKmForTest mirrors newsfeed.go's maxNearbyKm ceiling. Kept here
// rather than exported from the package so the test independently pins the
// documented contract instead of just re-reading whatever the constant says.
const maxNearbyKmForTest = 128.0

// minNearbyKmForTest mirrors newsfeed.go's minNearbyKm floor - see below.
const minNearbyKmForTest = 1.0

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

	allowed := newsfeed.RecentNonAlertNewsfeedIDs([]int64{int64(recentMsg), int64(oldMsg), int64(recentAlert)}, 0)

	_, hasRecent := allowed[int64(recentMsg)]
	_, hasOld := allowed[int64(oldMsg)]
	_, hasAlert := allowed[int64(recentAlert)]

	assert.True(t, hasRecent, "recent non-alert post should count towards the nearby radius")
	assert.False(t, hasOld, "post older than 31 days should be excluded")
	assert.False(t, hasAlert, "alert post should be excluded")

	// A member's own posts are excluded when their id is passed: they sit at the
	// member's own coordinates and say nothing about how far away the community is.
	own := newsfeed.RecentNonAlertNewsfeedIDs([]int64{int64(recentMsg)}, uid)
	_, hasOwn := own[int64(recentMsg)]
	assert.False(t, hasOwn, "the member's own posts must not count towards their nearby radius")
}

// mockNewsfeedKNN points the spatial client at an in-process server that
// answers /v1/newsfeed/knn with the given ids/distances (already in the
// order GetNearbyDistance expects: nearest first) and returns an empty
// result for every other path. Returns a restore func that must be
// deferred to put SPATIAL_KNN_URL back so later tests are unaffected.
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
// to reach nearbyLimit, GetNearbyDistance must still size a radius from the
// raw KNN density rather than giving up (which callers treat as "no
// restriction at all", making "Nearby" behave exactly like "Anywhere").
//
// Two earlier attempts at this fix (PRs #1114, #1115) both introduced a
// "maxNearbyKm" constant but only used it as an initial default - the raw
// KNN 10th-nearest distance was still written into distKm unconditionally
// afterwards, so a sparse/spread-out area could still produce an unbounded
// radius. This test uses raw candidates ~370-400km away specifically so
// that an uncapped implementation would return a radius far past
// maxNearbyKmForTest, and only a genuine unconditional clamp passes it.
func TestGetNearbyDistanceCapsRawKNNFallbackRadius(t *testing.T) {
	uid := CreateTestUser(t, "nearbycapfallback", "User")
	author := CreateTestUser(t, "nearbycapfallbackauthor", "User")
	lat, lng := 55.9533, -3.1883

	var ids []int64
	var dists []float64

	addPoint := func(hoursAgo int, degrees float64) {
		id := CreateTestNewsfeedWithType(t, author, lat, lng, fmt.Sprintf("fallback post %d", len(ids)), "Message", hoursAgo)
		ids = append(ids, int64(id))
		dists = append(dists, degrees)
	}

	// Only 2 recent, non-alert posts - fewer than nearbyLimit (10), so
	// GetNearbyDistance must fall back to raw KNN density.
	for i := 0; i < 2; i++ {
		addPoint(24, 0.01*float64(i+1)) // ~1-2km away, 1 day ago
	}
	// 13 more raw KNN candidates (stale, >31 days) spread far out, ascending
	// distance - the 10th-nearest overall (index 9) is ~3.7 degrees
	// (~410km) away.
	for i := 0; i < 13; i++ {
		addPoint(24*40, 3.0+0.1*float64(i)) // 40 days ago
	}

	restore := mockNewsfeedKNN(t, ids, dists)
	defer restore()

	dist, _, _, _, _, _ := newsfeed.GetNearbyDistance(uid)

	assert.Greater(t, dist, 0.0, "Nearby should still apply a geographic radius when there aren't enough recent posts to size one from alone, rather than silently becoming Anywhere")
	assert.LessOrEqual(t, dist, maxNearbyKmForTest, "the raw-KNN fallback radius must be capped rather than left at the 10th raw candidate's ~410km distance")
}

// Regression test for the same PR #1114/#1115 review finding, but for the
// "happy path": even when there ARE enough recent, non-alert posts to avoid
// the raw-KNN fallback entirely, a sparse/spread-out area could still size
// an unbounded radius directly from their distance. That path must be
// capped too.
func TestGetNearbyDistanceCapsHappyPathRadius(t *testing.T) {
	uid := CreateTestUser(t, "nearbycaphappy", "User")
	author := CreateTestUser(t, "nearbycaphappyauthor", "User")
	lat, lng := 55.9533, -3.1883

	var ids []int64
	var dists []float64

	addPoint := func(degrees float64) {
		id := CreateTestNewsfeedWithType(t, author, lat, lng, fmt.Sprintf("happy post %d", len(ids)), "Message", 24)
		ids = append(ids, int64(id))
		dists = append(dists, degrees)
	}

	// 10 recent, non-alert posts (enough to avoid the fallback branch), but
	// spread very far apart - the 10th-nearest (index 9) is ~4.1 degrees
	// (~455km) away.
	for i := 0; i < 10; i++ {
		addPoint(0.5 + 0.4*float64(i))
	}

	restore := mockNewsfeedKNN(t, ids, dists)
	defer restore()

	dist, _, _, _, _, _ := newsfeed.GetNearbyDistance(uid)

	assert.Greater(t, dist, 0.0)
	assert.LessOrEqual(t, dist, maxNearbyKmForTest, "even the happy-path (enough recent, non-alert posts) radius must be capped so a sparse/spread-out area can't produce an unbounded 'Nearby' radius")
}

// Regression test from the adversarial review of the #9937 fix: capping the
// radius from above isn't enough, because both data-driven branches can
// compute a distance of exactly 0, not just an unbounded one. A newsfeed
// post at the user's own coordinates - their own post, or someone else's
// right on top of them - has KNN distance 0 in decimal degrees, and 0 hits
// getFeed()'s "no restriction at all" path exactly like an unbounded radius
// does. With distinct-distance dedupe these 10 co-located posts now count as
// ONE location, so this routes through the too-few fallback - and its
// window-reach distance is also 0, exercising the floor there.
func TestGetNearbyDistanceFloorsHappyPathZeroRadius(t *testing.T) {
	uid := CreateTestUser(t, "nearbyfloorhappy", "User")
	author := CreateTestUser(t, "nearbyfloorhappyauthor", "User")
	lat, lng := 55.9533, -3.1883

	var ids []int64
	var dists []float64

	addPoint := func(degrees float64) {
		id := CreateTestNewsfeedWithType(t, author, lat, lng, fmt.Sprintf("floorhappy post %d", len(ids)), "Message", 24)
		ids = append(ids, int64(id))
		dists = append(dists, degrees)
	}

	// 10 recent, non-alert posts, all at the user's exact coordinates - the
	// 10th-nearest (index 9) is 0 degrees away.
	for i := 0; i < 10; i++ {
		addPoint(0.0)
	}

	restore := mockNewsfeedKNN(t, ids, dists)
	defer restore()

	dist, _, _, _, _, _ := newsfeed.GetNearbyDistance(uid)

	assert.GreaterOrEqual(t, dist, minNearbyKmForTest, "a 0-degree happy-path radius must be floored to a positive value, not left at 0 (which getFeed() treats as 'no restriction', reintroducing #9937)")
}

// Same review finding, but for the raw-KNN fallback branch: too few recent,
// non-alert posts to reach nearbyLimit, and the raw nearbyLimit-th KNN
// candidate is also co-located with the user (distance 0).
func TestGetNearbyDistanceFloorsRawKNNFallbackZeroRadius(t *testing.T) {
	uid := CreateTestUser(t, "nearbyfloorfallback", "User")
	author := CreateTestUser(t, "nearbyfloorfallbackauthor", "User")
	lat, lng := 55.9533, -3.1883

	var ids []int64
	var dists []float64

	addPoint := func(hoursAgo int, degrees float64) {
		id := CreateTestNewsfeedWithType(t, author, lat, lng, fmt.Sprintf("floorfallback post %d", len(ids)), "Message", hoursAgo)
		ids = append(ids, int64(id))
		dists = append(dists, degrees)
	}

	// Only 2 recent, non-alert posts - fewer than nearbyLimit (10), so
	// GetNearbyDistance must fall back to raw KNN density.
	for i := 0; i < 2; i++ {
		addPoint(24, 0.0)
	}
	// 13 more raw KNN candidates (stale, >31 days), all co-located with the
	// user - the 10th-nearest overall (index 9) is 0 degrees away.
	for i := 0; i < 13; i++ {
		addPoint(24*40, 0.0)
	}

	restore := mockNewsfeedKNN(t, ids, dists)
	defer restore()

	dist, _, _, _, _, _ := newsfeed.GetNearbyDistance(uid)

	assert.GreaterOrEqual(t, dist, minNearbyKmForTest, "a 0-degree raw-KNN fallback radius must be floored to a positive value, not left at 0 (which getFeed() treats as 'no restriction', reintroducing #9937)")
}
