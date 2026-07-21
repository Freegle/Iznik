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

// Groups used to carry a moderator-drawn `postvisibility` polygon, and
// /message/inbounds hid a post from any viewer outside it. That has been
// retired: how far a freegler sees is their own choice via the distance slider
// and the rippling reach model, and a group can't override it.
//
// The column still exists and still holds the polygons groups last had, so this
// guards against the filtering being reintroduced by accident - which would
// silently hide posts again, on the basis of a value no moderator can now see
// or edit.
func TestBoundsIgnoresPostVisibility(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("postvis")
	group := CreateTestGroup(t, prefix)

	// A postvisibility polygon nowhere near the post or the viewer. Under the old
	// behaviour this hid the post from everyone standing outside it.
	db.Exec(fmt.Sprintf(
		"UPDATE `groups` SET postvisibility = ST_GeomFromText('POLYGON((0 0, 0.1 0, 0.1 0.1, 0 0.1, 0 0))', %d) WHERE id = ?",
		utils.SRID), group)

	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 53.0, 'lng', -2.0)) WHERE id = ?", posterID)

	msg := CreateTestMessageWithArrival(t, posterID, group, "OFFER: visible anyway ("+prefix+")", 53.0, -2.0, 5)
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id = ?", msg)

	url := fmt.Sprintf("/api/message/inbounds?swlat=%f&swlng=%f&nelat=%f&nelng=%f&jwt=%s",
		52.9, -2.1, 53.1, -1.9, token)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msg {
			found = true
		}
	}
	assert.True(t, found, "a post shows in bounds even though the group's postvisibility polygon excludes the viewer")
}

// Logged-out visitors were the other group affected: with no user location the
// handler guessed the centre of the map, which the polygon also excluded.
func TestBoundsIgnoresPostVisibilityWhenLoggedOut(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("postvisout")
	group := CreateTestGroup(t, prefix)

	db.Exec(fmt.Sprintf(
		"UPDATE `groups` SET postvisibility = ST_GeomFromText('POLYGON((0 0, 0.1 0, 0.1 0.1, 0 0.1, 0 0))', %d) WHERE id = ?",
		utils.SRID), group)

	posterID, _ := CreateFullTestUser(t, prefix+"_poster")
	msg := CreateTestMessageWithArrival(t, posterID, group, "OFFER: visible logged out ("+prefix+")", 53.0, -2.0, 5)
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id = ?", msg)

	url := fmt.Sprintf("/api/message/inbounds?swlat=%f&swlng=%f&nelat=%f&nelng=%f",
		52.9, -2.1, 53.1, -1.9)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msg {
			found = true
		}
	}
	assert.True(t, found, "a post shows to a logged-out visitor despite the group's postvisibility polygon")
}
