package test

import (
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
