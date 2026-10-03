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
