package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// Edinburgh: somewhere real, so the handler gets past its lat/lng guard and the routing
// server has road network to work with.
const townNearEdinburgh = "lat=55.9533&lng=-3.1883&minutes=30"

// townNearTownID is well above any real towns row, so seeding it cannot collide with data
// a future fixture adds.
const townNearTownID = 990001

// seedTownNearEdinburgh puts one town inside the handler's candidate box.
//
// Without it none of this exercises anything: the schema-only test database has an EMPTY
// towns table, the handler returns its "no candidates" response before it ever calls the
// routing server, and every assertion about routing quietly skips. That is how the first
// version of this file passed while testing nothing.
func seedTownNearEdinburgh(t *testing.T) {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT IGNORE INTO towns (id, name, lat, lng) VALUES (?, ?, ?, ?)",
		townNearTownID, "Testburgh", 55.95, -3.19)
	t.Cleanup(func() {
		db.Exec("DELETE FROM towns WHERE id = ?", townNearTownID)
	})
}

func townNear(t *testing.T, query string) map[string]interface{} {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/town/near?"+query, nil), 60000)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	assert.Nil(t, json2.Unmarshal(rsp(resp), &body))
	return body
}

// The browse map shades the member's reach, so /town/near has to be able to return its
// shape. It rides on this call because the routing pass that produces it is the one this
// call already makes.
func TestTownNearReturnsReachPolygonWhenAsked(t *testing.T) {
	seedTownNearEdinburgh(t)
	body := townNear(t, townNearEdinburgh+"&polygon=1")

	// frontier_median_miles proves the routing server answered. Without it there is no
	// isochrone to have taken a shape from, and the polygon's absence is correct rather
	// than a regression - so tie the assertion to routing actually being available.
	// Deliberately NOT a skip: with a town seeded, the only way to get here is the routing
	// server being down, and a silent skip is what hid this test doing nothing at all.
	if _, routed := body["frontier_median_miles"].(float64); !routed {
		t.Fatalf("routing server did not answer, so the polygon could not be tested: %v", keysOf(body))
	}

	poly, ok := body["reach_polygon"].(map[string]interface{})
	assert.True(t, ok, "reach_polygon must be present when routing answered: %v", keysOf(body))

	geom, ok := poly["geometry"].(map[string]interface{})
	assert.True(t, ok, "reach_polygon must carry a geometry: %v", poly)
	assert.Equal(t, "Polygon", geom["type"])

	rings, ok := geom["coordinates"].([]interface{})
	assert.True(t, ok, "geometry must carry coordinates")
	assert.NotEmpty(t, rings)

	ring, ok := rings[0].([]interface{})
	assert.True(t, ok)
	assert.GreaterOrEqual(t, len(ring), 4, "a drawable ring needs at least 4 points")

	first := ring[0].([]interface{})
	last := ring[len(ring)-1].([]interface{})
	assert.Equal(t, first, last, "ring must be closed")

	// The whole point of simplifying server-side is that this is small enough to ship on
	// every slider change. A 30-minute reach traces tens of thousands of vertices exactly;
	// anything approaching that here means the simplification was skipped.
	assert.Less(t, len(ring), 5000, "reach polygon was not simplified: %d points", len(ring))

	t.Logf("reach polygon: %d points", len(ring))
}

// Callers that only want the radius and the town names (the Feed settings slider, and the
// browse slider's own cap lookup) must not be made to pay for a shape they never draw.
func TestTownNearOmitsReachPolygonUnlessAsked(t *testing.T) {
	seedTownNearEdinburgh(t)
	for _, q := range []string{townNearEdinburgh, townNearEdinburgh + "&polygon=0", townNearEdinburgh + "&polygon=yes"} {
		body := townNear(t, q)
		assert.NotContains(t, body, "reach_polygon", "query %q should not return a polygon", q)
	}
}

// Asking for the shape must not disturb the numbers the same response carries, which is
// what the slider actually stores.
func TestTownNearPolygonDoesNotChangeTheOtherFields(t *testing.T) {
	seedTownNearEdinburgh(t)
	without := townNear(t, townNearEdinburgh)
	with := townNear(t, townNearEdinburgh+"&polygon=1")

	// Without routing these are all absent and the comparison is vacuously true, which is
	// exactly the failure mode this file already had once.
	assert.Contains(t, without, "frontier_median_miles", "routing did not answer, so there was nothing to compare")

	for _, k := range []string{"cap_minutes", "density_band", "reach_radius_miles", "frontier_median_miles", "frontier_max_miles", "towns"} {
		assert.Equal(t, without[k], with[k], "field %s changed when the polygon was requested", k)
	}
}

// A request with no usable location has no reach to draw, and must not invent one.
func TestTownNearNoPolygonWithoutALocation(t *testing.T) {
	body := townNear(t, "lat=0&lng=0&minutes=30&polygon=1")
	assert.NotContains(t, body, "reach_polygon")
}

func keysOf(m map[string]interface{}) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
