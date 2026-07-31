package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Community News drip-posts to the ChitChat feed as type Alert, placed at the
// centre of the area the news is about (CommunityNewsChitChatService). Its
// docblock assumed alerts got the same MBRContains filtering as any other post,
// but the alerts arm of the feed query had no geographic predicate at all - it
// was "any location, capped at 5" - so a Yorkshire news item was served to
// somebody in Cornwall, and unpinned alerts also went uncapped in the
// "everywhere" feed.
//
// Only a PINNED alert - a central Freegle announcement - is meant to reach
// everyone regardless of location.

func pinNewsfeed(t *testing.T, id uint64) {
	if err := database.DBConn.Exec("UPDATE newsfeed SET pinned = 1 WHERE id = ?", id).Error; err != nil {
		t.Fatalf("failed to pin newsfeed %d: %v", id, err)
	}
}

func feedIDs(t *testing.T, token string, distance string) map[uint64]bool {
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed?distance="+distance+"&jwt="+token, nil), 30000)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var feed []newsfeed.NewsfeedSummary
	json.Unmarshal(rsp(resp), &feed)

	ids := make(map[uint64]bool)
	for _, entry := range feed {
		ids[entry.ID] = true
	}

	return ids
}

func TestFeedUnpinnedAlertStaysInGeography(t *testing.T) {
	prefix := uniquePrefix("alertgeo")
	userID, token := CreateFullTestUser(t, prefix)

	// CreateTestUser puts the user in Edinburgh. London is ~330 miles away, well
	// outside any sane "nearby" radius.
	londonLat, londonLng := 51.5074, -0.1278

	unpinnedID := CreateTestNewsfeedWithType(t, userID, londonLat, londonLng,
		"Far unpinned alert "+prefix, utils.NEWSFEED_TYPE_ALERT, 0)

	pinnedID := CreateTestNewsfeedWithType(t, userID, londonLat, londonLng,
		"Far pinned alert "+prefix, utils.NEWSFEED_TYPE_ALERT, 0)
	pinNewsfeed(t, pinnedID)

	ids := feedIDs(t, token, "nearby")

	assert.False(t, ids[unpinnedID],
		"an unpinned alert 330 miles away must not appear in the Nearby feed")
	assert.True(t, ids[pinnedID],
		"a pinned alert is a central announcement and must still reach everyone")
}

func TestFeedLocalUnpinnedAlertStillAppears(t *testing.T) {
	prefix := uniquePrefix("alertlocal")
	userID, token := CreateFullTestUser(t, prefix)

	// Right where the user is: local Community News should still be delivered.
	localID := CreateTestNewsfeedWithType(t, userID, 55.9533, -3.1883,
		"Local alert "+prefix, utils.NEWSFEED_TYPE_ALERT, 0)

	ids := feedIDs(t, token, "nearby")

	assert.True(t, ids[localID],
		"an alert at the user's own location must still appear")
}

func TestFeedCapsAlertsWhenNearby(t *testing.T) {
	prefix := uniquePrefix("alertcapnear")
	userID, token := CreateFullTestUser(t, prefix)

	created := createAlertBurst(t, userID, prefix, 55.9533, -3.1883)
	ids := feedIDs(t, token, "nearby")

	assertAlertsCapped(t, created, ids)
}

func TestFeedCapsAlertsWhenEverywhere(t *testing.T) {
	prefix := uniquePrefix("alertcapevery")
	userID, token := CreateFullTestUser(t, prefix)

	// With "everywhere" there is no geographic filter to hold the volume down,
	// so the cap is the only thing standing between a news run and a feed full
	// of Community News.
	created := createAlertBurst(t, userID, prefix, 55.9533, -3.1883)
	ids := feedIDs(t, token, "anywhere")

	assertAlertsCapped(t, created, ids)
}

// createAlertBurst makes more alerts than the cap allows, newest last.
func createAlertBurst(t *testing.T, userID uint64, prefix string, lat float64, lng float64) []uint64 {
	var created []uint64

	for i := 0; i < utils.NEWSFEED_ALERTS_PER_FEED+3; i++ {
		created = append(created, CreateTestNewsfeedWithType(t, userID, lat, lng,
			fmt.Sprintf("Burst alert %d %s", i, prefix), utils.NEWSFEED_TYPE_ALERT, 0))
	}

	return created
}

func assertAlertsCapped(t *testing.T, created []uint64, ids map[uint64]bool) {
	shown := 0
	for _, id := range created {
		if ids[id] {
			shown++
		}
	}

	// Before the cap all of them came through. Other tests share this database
	// and may push some of ours out, so assert the ceiling rather than an exact
	// count - the ceiling is the whole point.
	assert.LessOrEqual(t, shown, utils.NEWSFEED_ALERTS_PER_FEED,
		"no more than NEWSFEED_ALERTS_PER_FEED alerts should reach the feed")
	assert.Greater(t, shown, 0,
		"alerts should not be excluded from the feed altogether")
}
