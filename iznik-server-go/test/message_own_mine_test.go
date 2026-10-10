package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// TestBoundsOwnPostFlaggedMine: /message/bounds is what the browse feed switches to as soon as the
// member moves the map, so it must flag own posts too - otherwise a member's own post is pinned
// before they touch the map and unpinned the moment they pan it.
func TestBoundsOwnPostFlaggedMine(t *testing.T) {
	prefix := uniquePrefix("bounds_mine")
	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	otherID := CreateTestUser(t, prefix+"_other", "Other")

	own := CreateTestMessage(t, viewerID, prefix+" my own offer", 51.5, -0.1)
	rival := CreateTestMessage(t, otherID, prefix+" someone else's offer", 51.5, -0.1)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/message/inbounds?swlat=51.4&swlng=-0.2&nelat=51.6&nelng=0.0&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	byID := map[uint64]message.MessageSummary{}
	for _, m := range msgs {
		byID[m.ID] = m
	}

	ownMsg, ownPresent := byID[own]
	assert.True(t, ownPresent, "the viewer's own post appears in the bounds feed")
	assert.True(t, ownMsg.Mine, "the viewer's own post is flagged mine in the bounds feed")

	rivalMsg, rivalPresent := byID[rival]
	assert.True(t, rivalPresent, "another member's post appears in the bounds feed")
	assert.False(t, rivalMsg.Mine, "another member's post is not flagged mine")
}
