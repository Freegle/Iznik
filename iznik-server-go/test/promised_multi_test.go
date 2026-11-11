package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A post offering several of something is only "promised" once every item still
// available is spoken for. A promise carries no quantity, so one promise counts
// as one item: three jars, one promise, two still free, not promised (#1444).

// promisedFixture makes a viewer with a location and one of their own Offer of
// three items. The post is still Pending and not in messages_spatial, which is
// the case every browse feed serves through its own-posts arm; those arms
// compute promised directly rather than reading messages_spatial.promised.
func promisedFixture(t *testing.T, prefix string) (msgID uint64, posterID uint64, token string) {
	db := database.DBConn
	posterID, token = CreateFullTestUser(t, prefix+"_poster")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, utils.ROLE_MEMBER)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", posterID)

	msgID = CreateTestMessage(t, posterID, group, "OFFER: Three jars ("+prefix+")", 51.5, -0.1)
	db.Exec("UPDATE messages SET lat = 51.5, lng = -0.1, availableinitially = 3, availablenow = 3 WHERE id = ?", msgID)
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", msgID)
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_promises WHERE msgid = ?", msgID)
	})
	return
}

func promiseTo(t *testing.T, msgID uint64, prefix string) {
	db := database.DBConn
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	db.Exec("INSERT INTO messages_promises (msgid, userid) VALUES (?, ?)", msgID, userID)
}

func fetchMessagePromised(t *testing.T, msgID uint64, token string) (bool, int) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/"+fmt.Sprint(msgID)+"?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msg message.Message
	json2.Unmarshal(rsp(resp), &msg)
	if !assert.Equal(t, msgID, msg.ID, "the post is returned to its owner") {
		return false, 0
	}
	return msg.Promised, msg.Promisecount
}

func summaryPromised(t *testing.T, url string, msgID uint64) (found bool, promised bool) {
	found, promised, _ = summaryPromisedCount(t, url, msgID)
	return
}

func summaryPromisedCount(t *testing.T, url string, msgID uint64) (found bool, promised bool, count int) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	for _, m := range msgs {
		if m.ID == msgID {
			return true, m.Promised, m.Promisecount
		}
	}
	return false, false, 0
}

func TestPromisedForCountsOnePromiseAsOneItem(t *testing.T) {
	assert.False(t, message.PromisedFor(0, 1), "no promise, nothing promised")
	assert.True(t, message.PromisedFor(1, 1), "one item, one promise")
	assert.False(t, message.PromisedFor(1, 3), "three items, one promise")
	assert.True(t, message.PromisedFor(3, 3), "three items, three promises")
	assert.True(t, message.PromisedFor(4, 3), "more promises than items still counts")
	assert.True(t, message.PromisedFor(1, 0), "nothing left and a promise: spoken for")
	assert.False(t, message.PromisedFor(0, 0), "nothing left and no promise: not promised")
}

func TestMultiItemPromisedOnlyWhenAllSpokenFor(t *testing.T) {
	prefix := uniquePrefix("promulti")
	msgID, _, token := promisedFixture(t, prefix)

	promiseTo(t, msgID, prefix+"_p1")
	promised, count := fetchMessagePromised(t, msgID, token)
	assert.Equal(t, 1, count)
	assert.False(t, promised, "one of three promised: two still free, so not promised")

	promiseTo(t, msgID, prefix+"_p2")
	promiseTo(t, msgID, prefix+"_p3")
	promised, count = fetchMessagePromised(t, msgID, token)
	assert.Equal(t, 3, count)
	assert.True(t, promised, "all three promised")
}

func TestSingleItemPromisedOnFirstPromise(t *testing.T) {
	prefix := uniquePrefix("promsingle")
	msgID, _, token := promisedFixture(t, prefix)
	database.DBConn.Exec("UPDATE messages SET availableinitially = 1, availablenow = 1 WHERE id = ?", msgID)

	promiseTo(t, msgID, prefix+"_p1")
	promised, _ := fetchMessagePromised(t, msgID, token)
	assert.True(t, promised, "a single item is promised on the first promise")
}

func TestMyPostsPromisedNeedsEveryItem(t *testing.T) {
	prefix := uniquePrefix("prommyposts")
	msgID, posterID, token := promisedFixture(t, prefix)
	url := "/api/user/" + fmt.Sprint(posterID) + "/message?active=true&jwt=" + token

	promiseTo(t, msgID, prefix+"_p1")
	found, promised, count := summaryPromisedCount(t, url, msgID)
	assert.True(t, found, "own post is in My Posts")
	assert.False(t, promised, "one of three promised is not promised on My Posts")
	// My Posts still needs to know a promise exists, to fetch the collection details.
	assert.Equal(t, 1, count, "My Posts carries the promise count")

	promiseTo(t, msgID, prefix+"_p2")
	promiseTo(t, msgID, prefix+"_p3")
	_, promised, count = summaryPromisedCount(t, url, msgID)
	assert.True(t, promised, "all three promised shows as promised on My Posts")
	assert.Equal(t, 3, count)
}

func TestMyGroupsOwnPostPromisedNeedsEveryItem(t *testing.T) {
	prefix := uniquePrefix("prommygroups")
	msgID, _, token := promisedFixture(t, prefix)
	url := "/api/message/mygroups?jwt=" + token

	promiseTo(t, msgID, prefix+"_p1")
	found, promised := summaryPromised(t, url, msgID)
	assert.True(t, found, "own post is served by the mygroups own-posts arm")
	assert.False(t, promised, "one of three promised is not promised on mygroups")

	promiseTo(t, msgID, prefix+"_p2")
	promiseTo(t, msgID, prefix+"_p3")
	_, promised = summaryPromised(t, url, msgID)
	assert.True(t, promised, "all three promised shows as promised on mygroups")
}

func TestBoundsOwnPostPromisedNeedsEveryItem(t *testing.T) {
	prefix := uniquePrefix("prombounds")
	msgID, _, token := promisedFixture(t, prefix)
	url := "/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt=" + token

	promiseTo(t, msgID, prefix+"_p1")
	found, promised := summaryPromised(t, url, msgID)
	assert.True(t, found, "own post is served by the bounds own-posts arm")
	assert.False(t, promised, "one of three promised is not promised on the bounds feed")

	promiseTo(t, msgID, prefix+"_p2")
	promiseTo(t, msgID, prefix+"_p3")
	_, promised = summaryPromised(t, url, msgID)
	assert.True(t, promised, "all three promised shows as promised on the bounds feed")
}

func TestNearbyOwnPostPromisedNeedsEveryItem(t *testing.T) {
	prefix := uniquePrefix("promnearby")
	msgID, _, token := promisedFixture(t, prefix)
	url := "/api/isochrone/message?jwt=" + token

	promiseTo(t, msgID, prefix+"_p1")
	found, promised := summaryPromised(t, url, msgID)
	assert.True(t, found, "own post is served by the nearby own-posts arm")
	assert.False(t, promised, "one of three promised is not promised on the nearby feed")

	promiseTo(t, msgID, prefix+"_p2")
	promiseTo(t, msgID, prefix+"_p3")
	_, promised = summaryPromised(t, url, msgID)
	assert.True(t, promised, "all three promised shows as promised on the nearby feed")
}
