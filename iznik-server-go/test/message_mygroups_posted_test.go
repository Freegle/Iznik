package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestMyGroupsReturnsPosted: /message/mygroups (the "All my communities" browse feed) must
// return `posted` - the ORIGINAL post time (messages.arrival), stable across rippling - just
// like the nearby/reach feed does. The client's "Newest posted" sort keys on posted and only
// falls back to arrival; the mygroups feed omitted posted, so the fallback ordered by the
// ripple-BUMPED spatial arrival and the selected sort appeared not to be applied at all
// (Discourse 9844, recurring on the mygroups view).
func TestMyGroupsReturnsPosted(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mygroups_posted")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, viewerID, group, "Member")
	CreateTestMembership(t, posterID, group, "Member")

	msgID := CreateTestMessage(t, posterID, group, prefix+" old offer", 51.5, -0.1)

	// Simulate a post that went up 3 days ago and has just rippled into a new group:
	// the original post time is old, but the spatial arrival was bumped to NOW by the
	// reach engine.
	db.Exec("UPDATE messages SET arrival = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE id = ?", msgID)
	db.Exec("UPDATE messages_spatial SET arrival = NOW() WHERE msgid = ?", msgID)

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
		assert.False(t, found.Posted.IsZero(),
			"mygroups feed populates posted (the stable original post time)")
		// posted is the 3-day-old original time, clearly before the bumped spatial arrival.
		assert.True(t, found.Posted.Before(time.Now().Add(-48*time.Hour)),
			"posted is the ORIGINAL post time, not the ripple-bumped arrival: got %v", found.Posted)
	}
}
