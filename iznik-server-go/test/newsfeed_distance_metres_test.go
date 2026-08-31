package test

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// The ChitChat "how far away" slider persists a crow-flies radius in METRES
// (settings.newsfeedarea) and the feed endpoint takes it as ?distance=<metres>.
// getFeed built its bounding box with "distance / 1000" on the uint64, so the
// metres were truncated to a whole number of kilometres: a 1900m setting
// filtered to 1km, and anything under 1000m produced a zero-size box that
// matched nothing at all.
//
// The member sits in Edinburgh (CreateTestUser). The box corners are taken at
// bearing 45/225, so a radius r reaches r*cos(45) due north:
//
//	requested 1900m -> 1343m north
//	truncated 1000m ->  707m north
//
// so a post 1000m north is inside the box the member asked for and outside the
// truncated one.
const edinburghLat = 55.9533
const edinburghLng = -3.1883

// One degree of latitude is ~111.32km, so this converts metres north into a latitude.
func latNorthOf(metres float64) float64 {
	return edinburghLat + metres/111320.0
}

func TestFeedDistanceInMetresIsNotTruncatedToWholeKm(t *testing.T) {
	prefix := uniquePrefix("distmetres")
	userID, token := CreateFullTestUser(t, prefix)

	inRangeID := CreateTestNewsfeedWithType(t, userID, latNorthOf(1000), edinburghLng,
		"Metres in range "+prefix, "Message", 0)

	// Well outside even the correct box, so the filter is proven to still bite.
	outOfRangeID := CreateTestNewsfeedWithType(t, userID, latNorthOf(5000), edinburghLng,
		"Metres out of range "+prefix, "Message", 0)

	ids := feedIDs(t, token, "1900")

	assert.True(t, ids[inRangeID],
		"a post 1000m away must be in a feed filtered to 1900m")
	assert.False(t, ids[outOfRangeID],
		"a post 5000m away must not be in a feed filtered to 1900m")
}

func TestFeedDistanceBelowOneKmDoesNotEmptyTheFeed(t *testing.T) {
	prefix := uniquePrefix("distsubkm")
	userID, token := CreateFullTestUser(t, prefix)

	// Truncation turned any sub-kilometre radius into a zero-size box, so even a
	// post on the member's own doorstep vanished.
	closeID := CreateTestNewsfeedWithType(t, userID, latNorthOf(300), edinburghLng,
		"Sub-km close "+prefix, "Message", 0)

	farID := CreateTestNewsfeedWithType(t, userID, latNorthOf(3000), edinburghLng,
		"Sub-km far "+prefix, "Message", 0)

	ids := feedIDs(t, token, "800")

	assert.True(t, ids[closeID],
		"a post 300m away must survive an 800m feed filter")
	assert.False(t, ids[farID],
		"a post 3000m away must not survive an 800m feed filter")
}

