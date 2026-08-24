package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	newsfeed2 "github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Community News drip-posts to ChitChat as unpinned Alerts placed at the centre
// of a news area. The feed serves those only from inside the member's own alert
// box, capped at NEWSFEED_ALERTS_PER_FEED, so nobody can review what is going out
// nationally. `newsletters=all` lifts both limits for ChitChat moderators only.

// createTestAlertAt inserts an unpinned Alert at a point, with the area name in
// `location` the way CommunityNewsChitChatService writes it.
func createTestAlertAt(t *testing.T, userID uint64, lat float64, lng float64, message string, location string) uint64 {
	db := database.DBConn

	result := db.Exec(fmt.Sprintf("INSERT INTO newsfeed (userid, message, type, timestamp, deleted, reviewrequired, position, hidden, pinned, location) "+
		"VALUES (?, ?, 'Alert', NOW(), NULL, 0, ST_GeomFromText(?, %d), NULL, 0, ?)", utils.SRID),
		userID, message, fmt.Sprintf("POINT(%f %f)", lng, lat), location)

	if result.Error != nil {
		t.Fatalf("ERROR: Failed to create test alert: %v", result.Error)
	}

	var id uint64
	db.Raw("SELECT id FROM newsfeed WHERE userid = ? AND message = ? ORDER BY id DESC LIMIT 1",
		userID, message).Scan(&id)

	if id == 0 {
		t.Fatalf("ERROR: Alert was created but ID not found")
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM newsfeed WHERE id = ?", id)
	})

	return id
}

// feedContains reports whether a feed response includes a newsfeed id.
func feedContains(t *testing.T, token string, query string, wanted uint64) bool {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed?jwt="+token+query, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var feed []newsfeed2.Newsfeed
	json2.Unmarshal(rsp(resp), &feed)

	for _, item := range feed {
		if item.ID == wanted {
			return true
		}
	}

	return false
}

// Test users sit at Edinburgh (CreateTestUser settings mylocation). Cornwall is
// far outside NEWSFEED_ALERT_RADIUS_KM of that, so an alert there is only ever
// visible because the flag lifted the geographic filter, never by accident.
const farAwayLat = 50.2660
const farAwayLng = -5.0527

func TestFeedAllNewslettersVisibleToChitChatMod(t *testing.T) {
	prefix := uniquePrefix("nlmod")
	userID, token := CreateFullTestUser(t, prefix)
	makeChitChatMod(t, userID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	alertID := createTestAlertAt(t, poster, farAwayLat, farAwayLng, "Newsletter far away "+prefix, "Penzance")

	// Without the flag the alert is out of area, on both feed paths.
	assert.False(t, feedContains(t, token, "&distance=0", alertID),
		"an out-of-area newsletter post must not reach the everywhere feed unasked")
	assert.False(t, feedContains(t, token, "&distance=10000", alertID),
		"an out-of-area newsletter post must not reach the distance-filtered feed unasked")

	// With it, a ChitChat mod sees it on both.
	assert.True(t, feedContains(t, token, "&distance=0&newsletters=all", alertID),
		"a ChitChat mod asking for all newsletters must see an out-of-area one")
	assert.True(t, feedContains(t, token, "&distance=10000&newsletters=all", alertID),
		"the distance-filtered feed path must honour the flag too")
}

func TestFeedAllNewslettersIgnoredForOrdinaryMember(t *testing.T) {
	prefix := uniquePrefix("nlmember")
	_, token := CreateFullTestUser(t, prefix)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	alertID := createTestAlertAt(t, poster, farAwayLat, farAwayLng, "Newsletter far away "+prefix, "Penzance")

	// The param is not a way for a member to escape the geographic filter.
	assert.False(t, feedContains(t, token, "&distance=0&newsletters=all", alertID),
		"an ordinary member must not be able to widen the feed with the flag")
	assert.False(t, feedContains(t, token, "&distance=10000&newsletters=all", alertID),
		"an ordinary member must not be able to widen the distance-filtered feed either")
}

func TestFeedAllNewslettersLeavesOrdinaryPostsAlone(t *testing.T) {
	// The flag widens the alert arm only - it must not drag in out-of-area
	// member chat, which would be a privacy change rather than a review tool.
	prefix := uniquePrefix("nlchat")
	userID, token := CreateFullTestUser(t, prefix)
	makeChitChatMod(t, userID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	chatID := CreateTestNewsfeed(t, poster, farAwayLat, farAwayLng, "Far away chatter "+prefix)
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM newsfeed WHERE id = ?", chatID)
	})

	assert.False(t, feedContains(t, token, "&distance=10000&newsletters=all", chatID),
		"the flag must not widen the feed for ordinary ChitChat posts")
}

func TestNewsfeedAlertLocationIsNotTruncated(t *testing.T) {
	// Alert posts store the news AREA NAME in `location`, not a postcode, so the
	// "trim the last two characters off the postcode" fallback mangles them
	// ("Edinburgh" -> "Edinbur"). The Freegle system account that posts them has
	// no lastlocation, so that fallback is exactly the path they take.
	prefix := uniquePrefix("nlloc")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	alertID := createTestAlertAt(t, poster, 55.9533, -3.1883, "Newsletter local "+prefix, "Edinburgh")

	id := strconv.FormatUint(alertID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var single newsfeed2.Newsfeed
	json2.Unmarshal(rsp(resp), &single)
	assert.Equal(t, "Edinburgh", single.Location,
		"an Alert's area name must be returned intact, not treated as a postcode")
}

func TestNewsfeedMessageLocationStillTruncated(t *testing.T) {
	// The postcode fallback must survive for ordinary posts, which is what it
	// was written for.
	prefix := uniquePrefix("nlloc2")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	nfID := CreateTestNewsfeed(t, poster, 55.9533, -3.1883, "Ordinary post "+prefix)
	database.DBConn.Exec("UPDATE newsfeed SET location = ? WHERE id = ?", "EH3 9DR", nfID)
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM newsfeed WHERE id = ?", nfID)
	})

	id := strconv.FormatUint(nfID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeed/"+id, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var single newsfeed2.Newsfeed
	json2.Unmarshal(rsp(resp), &single)
	assert.Equal(t, "EH3 9", single.Location,
		"an ordinary post's postcode must still be truncated for privacy")
}
