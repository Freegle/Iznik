package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestMyGroupsComputesDistance: /message/mygroups (message.Groups) is the "All my communities"
// browse feed. It must return a real per-post distance (miles from the viewer) so the client's
// distance slider can narrow the list and the map. It previously returned distance 0 for every
// post, and since 0 <= any slider value the feed was never filtered - the reported mygroups
// slider no-op.
func TestMyGroupsComputesDistance(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mygroups_distance")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	CreateTestMembership(t, posterID, group, "Member")

	// Viewer in London; the post well to the north-west, so the distance is clearly non-zero
	// even after the coordinate blurring the feed applies.
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)
	msgID := CreateTestMessage(t, posterID, group, prefix+" far offer", 53.5, -2.0)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/mygroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msgID {
			found = &msgs[i]
			break
		}
	}
	assert.NotNil(t, found, "the member-group post appears in the mygroups feed")
	if found != nil {
		// London -> ~53.5N,2W is well over 100 miles. The point is it is a REAL distance, not 0,
		// so isWithinDistance(distance, slider) can actually exclude it.
		assert.Greater(t, found.Distance, 100.0,
			"mygroups feed returns a real per-post distance (miles), not 0, so the slider can filter it")
	}
}
