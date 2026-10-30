package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/localreach"
	"github.com/stretchr/testify/assert"
)

// Test users live in Edinburgh (CreateTestUser's mylocation). stubRouting answers every reach
// with a 14.7-mile frontier.
const (
	edinLat   = 55.9533
	edinLng   = -3.1883
	londonLat = 51.5074
	londonLng = -0.1278
)

type storedReach struct {
	Lat          *float64
	Lng          *float64
	Reachminutes *int
	Reachmiles   *float64
}

func readReach(t *testing.T, table string, id uint64) storedReach {
	t.Helper()
	var r storedReach
	database.DBConn.Table(table).Select("lat, lng, reachminutes, reachmiles").Where("id = ?", id).Scan(&r)
	return r
}

func reachRequest(t *testing.T, method, path, token, body string) (int, map[string]interface{}) {
	t.Helper()
	req := httptest.NewRequest(method, path+"?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	assert.Nil(t, err)
	var out map[string]interface{}
	_ = json2.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func createEvent(t *testing.T, token, extra string) uint64 {
	t.Helper()
	code, out := reachRequest(t, "POST", "/api/communityevent", token,
		`{"title":"Reach test event","location":"Village hall","description":"Come along"`+extra+`}`)
	assert.Equal(t, 200, code, "%v", out)
	id := uint64(out["id"].(float64))
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM communityevents WHERE id = ?", id) })
	database.DBConn.Exec("INSERT INTO communityevents_dates (eventid, start, end) VALUES (?, DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 8 DAY))", id)
	database.DBConn.Exec("UPDATE communityevents SET pending = 0 WHERE id = ?", id)
	return id
}

func listIDs(t *testing.T, path, token string) []uint64 {
	t.Helper()
	resp, _ := getApp().Test(httptest.NewRequest("GET", path+"?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ids []uint64
	_ = json2.Unmarshal(rsp(resp), &ids)
	return ids
}

// With nothing chosen, an event is centred on the poster and reaches as far as a post from
// there would: the density cap, which is the flat 30 minutes when density cannot be measured.
func TestLocalReach_DefaultsToPosterAndDensityCap(t *testing.T) {
	stubRouting(t, false)
	uid := CreateTestUser(t, uniquePrefix("lr_default"), "User")
	_, token := CreateTestSession(t, uid)

	r := readReach(t, "communityevents", createEvent(t, token, ""))

	assert.InDelta(t, edinLat, *r.Lat, 0.001)
	assert.InDelta(t, edinLng, *r.Lng, 0.001)
	assert.Equal(t, 30, *r.Reachminutes)
	assert.InDelta(t, 14.7, *r.Reachmiles, 0.01)
}

// A named place overrides the poster's location, and the slider is held to its range.
func TestLocalReach_NamedPlaceAndClampedMinutes(t *testing.T) {
	stubRouting(t, false)
	uid := CreateTestUser(t, uniquePrefix("lr_named"), "User")
	_, token := CreateTestSession(t, uid)

	r := readReach(t, "communityevents", createEvent(t, token,
		fmt.Sprintf(`,"lat":%f,"lng":%f,"reachminutes":90`, londonLat, londonLng)))

	assert.InDelta(t, londonLat, *r.Lat, 0.001)
	assert.Equal(t, localreach.MaxMinutes, *r.Reachminutes)
}

// A member sees events whose reach covers them and not ones elsewhere, and always sees their own.
func TestLocalReach_ListShowsOnlyEventsInReach(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("lr_list")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	_, posterToken := CreateTestSession(t, posterID)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	near := createEvent(t, posterToken, "")
	far := createEvent(t, posterToken, fmt.Sprintf(`,"lat":%f,"lng":%f`, londonLat, londonLng))

	ids := listIDs(t, "/api/communityevent", viewerToken)
	assert.Contains(t, ids, near)
	assert.NotContains(t, ids, far, "an event centred in London must not reach Edinburgh")

	assert.Contains(t, listIDs(t, "/api/communityevent", posterToken), far, "the poster always sees their own")
}

// Routing failing at save time leaves no radius; the fallback radius applies rather than
// hiding the event or showing it everywhere.
func TestLocalReach_FallbackRadiusWhenRoutingFailed(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("lr_fallback")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	_, posterToken := CreateTestSession(t, posterID)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	// About 10 and 20 miles north of the viewer.
	ten := createEvent(t, posterToken, fmt.Sprintf(`,"lat":%f,"lng":%f`, edinLat+0.145, edinLng))
	twenty := createEvent(t, posterToken, fmt.Sprintf(`,"lat":%f,"lng":%f`, edinLat+0.29, edinLng))
	database.DBConn.Exec("UPDATE communityevents SET reachmiles = NULL WHERE id IN (?, ?)", ten, twenty)

	ids := listIDs(t, "/api/communityevent", viewerToken)
	assert.Contains(t, ids, ten)
	assert.NotContains(t, ids, twenty)
}

// The centre is usually the poster's home, so only someone who can edit the event is told it.
func TestLocalReach_CentreHiddenFromOthers(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("lr_hidden")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	_, posterToken := CreateTestSession(t, posterID)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	id := createEvent(t, posterToken, "")

	get := func(token string) map[string]interface{} {
		resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/communityevent/%d?jwt=%s", id, token), nil))
		var out map[string]interface{}
		_ = json2.Unmarshal(rsp(resp), &out)
		return out
	}

	assert.NotNil(t, get(posterToken)["lat"])
	other := get(viewerToken)
	assert.Nil(t, other["lat"])
	assert.Nil(t, other["lng"])
	assert.NotNil(t, other["reachmiles"], "how far it reaches is not private")
}

// A moderator changing only the reach must not move the event to where the moderator lives.
func TestLocalReach_UpdateKeepsCentre(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("lr_update")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	_, posterToken := CreateTestSession(t, posterID)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	database.DBConn.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", modID)
	_, modToken := CreateTestSession(t, modID)

	id := createEvent(t, posterToken, fmt.Sprintf(`,"lat":%f,"lng":%f`, londonLat, londonLng))

	code, out := reachRequest(t, "PATCH", "/api/communityevent", modToken, fmt.Sprintf(`{"id":%d,"reachminutes":10}`, id))
	assert.Equal(t, 200, code, "%v", out)

	r := readReach(t, "communityevents", id)
	assert.InDelta(t, londonLat, *r.Lat, 0.001)
	assert.Equal(t, 10, *r.Reachminutes)
}

// Online opportunities are shown everywhere; in-person ones only within their reach.
func TestLocalReach_OnlineVolunteeringIsNational(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("lr_vol")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	_, posterToken := CreateTestSession(t, posterID)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	_, viewerToken := CreateTestSession(t, viewerID)

	create := func(extra string) uint64 {
		code, out := reachRequest(t, "POST", "/api/volunteering", posterToken,
			`{"title":"Reach test vol","location":"London","description":"Help out"`+extra+`}`)
		assert.Equal(t, 200, code, "%v", out)
		id := uint64(out["id"].(float64))
		database.DBConn.Exec("UPDATE volunteering SET pending = 0 WHERE id = ?", id)
		t.Cleanup(func() { database.DBConn.Exec("DELETE FROM volunteering WHERE id = ?", id) })
		return id
	}

	inPerson := create(fmt.Sprintf(`,"lat":%f,"lng":%f`, londonLat, londonLng))
	online := create(fmt.Sprintf(`,"online":true,"lat":%f,"lng":%f`, londonLat, londonLng))

	ids := listIDs(t, "/api/volunteering", viewerToken)
	assert.NotContains(t, ids, inPerson)
	assert.Contains(t, ids, online)
}
