package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// browseCount asks the server what the nav badge would show for this viewer.
func browseCount(t *testing.T, token string, browseView string) float64 {
	t.Helper()
	url := fmt.Sprintf("/api/message/count?browseView=%s&jwt=%s", browseView, token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var res map[string]interface{}
	json.Unmarshal(rsp(resp), &res)
	count, _ := res["count"].(float64)
	return count
}

// "Mark seen" has to be able to clear the badge without the client naming every post. A member
// with a four-figure backlog cannot enumerate it: the ids only reach the browser as they scroll
// into the feed, which is the whole reason the count sat there (Discourse 10055). The server
// marks exactly what it counted, so the badge drains to zero by construction.
func TestClearCountDrainsCountWithoutClientIDs(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("clearcount")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_u", "User")
	posterID := CreateTestUser(t, prefix+"_p", "User")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, groupID)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID)

	var msgIDs []uint64
	for i := 0; i < 3; i++ {
		msg := CreateTestMessage(t, posterID, groupID, fmt.Sprintf("%s post %d", prefix, i), 55.9533, -3.1883)
		db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)
		msgIDs = append(msgIDs, msg)
	}
	defer func() {
		for _, m := range msgIDs {
			db.Exec("DELETE FROM messages_likes WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages_groups WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages WHERE id = ?", m)
		}
	}()

	before := browseCount(t, token, "mygroups")
	assert.GreaterOrEqual(t, before, float64(3), "the three unseen posts are counted to begin with")

	// No ids in the body: the point of the endpoint is that the client does not have them.
	url := fmt.Sprintf("/api/messages/clearcount?browseView=mygroups&jwt=%s", token)
	resp, _ := getApp().Test(httptest.NewRequest("POST", url, nil))
	body := rsp(resp)
	assert.Equal(t, 200, resp.StatusCode, "Response: %s", string(body))

	assert.Equal(t, float64(0), browseCount(t, token, "mygroups"),
		"the badge drains to zero, and the cached count is not left standing")
}

// Marking everything seen must not reach outside the viewer's own feed: another member's badge
// is untouched, and it is their own View rows that decide it.
func TestClearCountIsScopedToTheCaller(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("clearcountscope")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_u", "User")
	otherID := CreateTestUser(t, prefix+"_o", "User")
	posterID := CreateTestUser(t, prefix+"_p", "User")
	_, token := CreateTestSession(t, userID)
	_, otherToken := CreateTestSession(t, otherID)

	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", otherID, groupID)
	defer db.Exec("DELETE FROM memberships WHERE groupid = ?", groupID)

	msg := CreateTestMessage(t, posterID, groupID, prefix+" shared post", 55.9533, -3.1883)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)
	defer func() {
		db.Exec("DELETE FROM messages_likes WHERE msgid = ?", msg)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
		db.Exec("DELETE FROM messages WHERE id = ?", msg)
	}()

	assert.GreaterOrEqual(t, browseCount(t, otherToken, "mygroups"), float64(1))

	url := fmt.Sprintf("/api/messages/clearcount?browseView=mygroups&jwt=%s", token)
	resp, _ := getApp().Test(httptest.NewRequest("POST", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	assert.Equal(t, float64(0), browseCount(t, token, "mygroups"), "caller drains")
	assert.GreaterOrEqual(t, browseCount(t, otherToken, "mygroups"), float64(1),
		"the other member's badge is untouched")
}

// Logged out there is no feed to mark, and no user to mark it for.
func TestClearCountRequiresLogin(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/messages/clearcount", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

// chitChatCount asks the server what the ChitChat badge would show.
func chitChatCount(t *testing.T, token string) float64 {
	t.Helper()
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/newsfeedcount?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var res map[string]interface{}
	json.Unmarshal(rsp(resp), &res)
	count, _ := res["count"].(float64)
	return count
}

// ChitChat has the same shape of problem as browse: "Seen" needs an id, and the browser only
// has the items it has loaded, so a member with a backlog had to scroll it all into view.
// SeenAll resolves the watermark server-side.
func TestChitChatSeenAllClearsTheCount(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("chitchatseenall")

	// A full user, because the ChitChat feed is filtered by distance from the viewer.
	userID, token := CreateFullTestUser(t, prefix)
	posterID := CreateTestUser(t, prefix+"_p", "User")

	lat := 55.9533
	lng := -3.1883

	var ids []uint64
	for i := 0; i < 3; i++ {
		ids = append(ids, CreateTestNewsfeed(t, posterID, lat, lng, fmt.Sprintf("%s item %d", prefix, i)))
	}
	defer func() {
		for _, id := range ids {
			db.Exec("DELETE FROM newsfeed WHERE id = ?", id)
		}
		db.Exec("DELETE FROM newsfeed_users WHERE userid = ?", userID)
	}()

	assert.GreaterOrEqual(t, chitChatCount(t, token), float64(1), "the new items are unread to begin with")

	// No id in the body: the point is that the client does not have to name anything.
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+token,
		strings.NewReader(`{"action":"SeenAll"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode, "Response: %s", string(rsp(resp)))

	assert.Equal(t, float64(0), chitChatCount(t, token), "the ChitChat badge drains to zero")

	// The watermark is the whole mechanism: it must have moved past every item that existed.
	var watermark uint64
	db.Raw("SELECT newsfeedid FROM newsfeed_users WHERE userid = ?", userID).Scan(&watermark)
	for _, id := range ids {
		assert.GreaterOrEqual(t, watermark, id, "watermark covers every item that existed when cleared")
	}
}
