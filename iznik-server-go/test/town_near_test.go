package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/town"
	"github.com/stretchr/testify/assert"
)

func townDriveMin(v float64) *float64 { return &v }

// SelectNear names the biggest places in the outer half of the reach, so the hint moves outwards
// as the slider widens but still names places people know rather than villages on the edge.
// With nothing in the outer half it names the biggest reachable places. Unreachable (nil) and
// beyond-budget places are excluded.
func TestTownSelectNear(t *testing.T) {
	cands := []town.TownCand{
		{ID: 1, Name: "HomeTown", Population: 56000, DriveMin: townDriveMin(2)},
		{ID: 2, Name: "City", Population: 245000, DriveMin: townDriveMin(28)},
		{ID: 3, Name: "Village", Population: 4000, DriveMin: townDriveMin(8)},
		{ID: 4, Name: "EdgeVillage", Population: 3100, DriveMin: townDriveMin(29)},
		{ID: 5, Name: "MarketTown", Population: 37000, DriveMin: townDriveMin(18)},
		{ID: 6, Name: "Unreachable", Population: 900000, DriveMin: nil},
		{ID: 7, Name: "TooFar", Population: 500000, DriveMin: townDriveMin(40)},
	}
	// 30 min: outer half is 15-30, biggest first; the edge village loses to the city at a limit of 2.
	assert.Equal(t, []string{"City", "MarketTown"}, town.SelectNear(cands, 30, 2))
	assert.Equal(t, []string{"City", "MarketTown", "EdgeVillage"}, town.SelectNear(cands, 30, 5))
	// 10 min: outer half is 5-10, which holds only the village - the home town is not repeated.
	assert.Equal(t, []string{"Village"}, town.SelectNear(cands, 10, 5))
	// 3 min: nothing in the outer half, so the biggest reachable place - the member's own town.
	assert.Equal(t, []string{"HomeTown"}, town.SelectNear(cands, 3, 5))
	// None reachable in a tight budget, and empty input.
	assert.Empty(t, town.SelectNear(cands, 1, 5))
	assert.Empty(t, town.SelectNear(nil, 30, 5))
}

// The slider's top stop comes from cap_minutes, so the field has to be on EVERY
// answer with a usable location - including the ones where the routing server or
// the towns box gives nothing back and the hint hides. A client that only got it
// on a lucky call would silently fall back to the flat 30 and go on offering a
// rural member less than the reach engine now gives them.
func TestTownNearAlwaysCarriesTheSliderCap(t *testing.T) {
	// Somewhere real (Edinburgh), so the handler gets past its lat/lng guard.
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/town/near?lat=55.9533&lng=-3.1883&minutes=30", nil), 30000)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	assert.Nil(t, json2.Unmarshal(rsp(resp), &body))

	capMinutes, ok := body["cap_minutes"].(float64)
	assert.True(t, ok, "cap_minutes must be present: %v", body)
	assert.Greater(t, capMinutes, 0.0)
	// 20/30/45 are the configured bands; anything else means the policy drifted.
	assert.Contains(t, []float64{20, 30, 45}, capMinutes)

	band, ok := body["density_band"].(string)
	assert.True(t, ok, "density_band must be present: %v", body)
	assert.Contains(t, []string{"dense", "medium", "sparse", "unknown"}, band)

	// No location means no cap to describe, and the handler must not invent one.
	// Decode into a FRESH map: unmarshalling into a populated one merges keys, so
	// reusing `body` would carry the first response's cap into this assertion.
	resp, err = getApp().Test(httptest.NewRequest("GET", "/api/town/near?lat=0&lng=0&minutes=30", nil), 30000)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var noLocation map[string]interface{}
	assert.Nil(t, json2.Unmarshal(rsp(resp), &noLocation))
	assert.NotContains(t, noLocation, "cap_minutes")
}
