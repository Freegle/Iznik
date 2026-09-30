package test

import (
	json2 "encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// The browse map shades the member's drive-time reach. /town/near can return that shape
// because the routing pass it already runs to derive the mile radius has traced it anyway.
//
// These tests STUB the routing server rather than calling the real one. What is being tested
// here is apiv2's plumbing: does it ask for the polygon only when the caller wants it, and does
// it hand back what the routing server returned. The geometry itself is the routing server's
// job and is covered by iznik-routing-go's own tests.
//
// Stubbing is not squeamishness. The real routing server needs the 2.5GB UK graph, which CI
// does not have: it answers from a small fixture graph, so a request from Edinburgh comes back
// with a null frontier and no polygon. An earlier version of this file skipped in that case and
// therefore tested nothing; the version after that failed hard and broke CI. Neither is the
// answer - not depending on the graph is.

const townNearEdinburgh = "lat=55.9533&lng=-3.1883&minutes=30"

// townNearTownID is well above any real places row, so seeding cannot collide with data a
// future fixture adds.
const townNearTownID = 990001

// seedTownNearEdinburgh puts one place inside the handler's candidate box.
//
// Required, not incidental: the schema-only test database has an EMPTY places table, and with no
// candidate places the handler returns its "no candidates" response BEFORE it ever calls the
// routing server. Without this, every assertion below would pass against a response that never
// exercised the code under test.
func seedTownNearEdinburgh(t *testing.T) {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT IGNORE INTO places (id, name, lat, lng, population, position) VALUES (?, ?, ?, ?, ?, ST_SRID(POINT(?, ?), 3857))",
		townNearTownID, "Testburgh", 55.95, -3.19, 50000, -3.19, 55.95)
	t.Cleanup(func() {
		db.Exec("DELETE FROM places WHERE id = ?", townNearTownID)
	})
}

// stubReachPolygon is what the stub routing server returns as the traced reach.
var stubReachPolygon = map[string]interface{}{
	"type": "Feature",
	"geometry": map[string]interface{}{
		"type": "Polygon",
		"coordinates": []interface{}{
			[]interface{}{
				[]interface{}{-3.30, 55.90},
				[]interface{}{-3.10, 55.90},
				[]interface{}{-3.10, 56.00},
				[]interface{}{-3.30, 56.00},
				[]interface{}{-3.30, 55.90},
			},
		},
	},
}

// stubRoadMiles is the road distance the stub's /v1/drive-metrics gives every target.
const stubRoadMiles = 3.4

// stubRouting stands in for the routing server's /v1/ripple-eval. It records the request body
// each call, and returns a polygon only when one was asked for - the same contract the real
// server honours. It also answers /v1/drive-metrics (the nearest-town road distance), which is
// not recorded. Returns the recorder.
func stubRouting(t *testing.T, withPolygon bool) *[]map[string]interface{} {
	t.Helper()
	var seen []map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var req map[string]interface{}
		_ = json2.Unmarshal(body, &req)

		if r.URL.Path == "/v1/drive-metrics" {
			targets, _ := req["targets"].([]interface{})
			results := make([]map[string]interface{}, len(targets))
			for i, tg := range targets {
				id := tg.(map[string]interface{})["id"]
				results[i] = map[string]interface{}{"id": id, "mins": 8.0, "miles": stubRoadMiles}
			}
			w.Header().Set("Content-Type", "application/json")
			_ = json2.NewEncoder(w).Encode(map[string]interface{}{"results": results})
			return
		}
		seen = append(seen, req)

		// One result per requested point, so the handler's length check passes.
		n := 0
		if pts, ok := req["points"].([]interface{}); ok {
			n = len(pts)
		}
		results := make([]map[string]interface{}, n)
		for i := range results {
			results[i] = map[string]interface{}{"drive_min": 12.5}
		}

		resp := map[string]interface{}{
			"results":               results,
			"frontier_median_miles": 14.7,
			"frontier_max_miles":    22.4,
		}
		// The real server ships `polygon` only when polygon_simplify_m was positive.
		if simplify, ok := req["polygon_simplify_m"].(float64); ok && simplify > 0 && withPolygon {
			resp["polygon"] = stubReachPolygon
		}

		w.Header().Set("Content-Type", "application/json")
		_ = json2.NewEncoder(w).Encode(resp)
	}))
	t.Cleanup(srv.Close)
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	return &seen
}

func townNear(t *testing.T, query string) map[string]interface{} {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/town/near?"+query, nil), 30000)
	assert.Nil(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	assert.Nil(t, json2.Unmarshal(rsp(resp), &body))
	return body
}

// ?polygon=1 asks the routing server for the shape and hands it straight back.
func TestTownNearReturnsReachPolygonWhenAsked(t *testing.T) {
	seedTownNearEdinburgh(t)
	seen := stubRouting(t, true)

	body := townNear(t, townNearEdinburgh+"&polygon=1")

	// The routing server was asked for a simplified polygon, at a positive tolerance.
	assert.Len(t, *seen, 1, "expected exactly one routing call")
	simplify, ok := (*seen)[0]["polygon_simplify_m"].(float64)
	assert.True(t, ok, "polygon_simplify_m must be sent: %v", (*seen)[0])
	assert.Greater(t, simplify, 0.0)

	// And what came back was passed through unchanged.
	assert.Equal(t, stubReachPolygon, body["reach_polygon"])

	// The response is otherwise the usual one, so the shape is additive.
	assert.Contains(t, body, "cap_minutes")
	assert.Contains(t, body, "frontier_median_miles")
}

// Callers that only want the radius and the town names (Feed settings, and the browse slider's
// own cap lookup) must not make the routing server trace a boundary nobody draws.
func TestTownNearOmitsReachPolygonUnlessAsked(t *testing.T) {
	seedTownNearEdinburgh(t)

	for _, q := range []string{
		townNearEdinburgh,
		townNearEdinburgh + "&polygon=0",
		townNearEdinburgh + "&polygon=yes",
	} {
		seen := stubRouting(t, true)
		body := townNear(t, q)

		assert.NotContains(t, body, "reach_polygon", "query %q should not return a polygon", q)
		assert.Len(t, *seen, 1)
		_, sent := (*seen)[0]["polygon_simplify_m"]
		assert.False(t, sent, "query %q must not ask the routing server for a polygon", q)
	}
}

// A routing server that traced nothing drawable must leave the field off entirely, so the client
// falls back to its own overlay rather than drawing a degenerate shape.
func TestTownNearNoPolygonWhenRoutingReturnsNone(t *testing.T) {
	seedTownNearEdinburgh(t)
	stubRouting(t, false) // asked for, but the server has no shape to give

	body := townNear(t, townNearEdinburgh+"&polygon=1")

	assert.NotContains(t, body, "reach_polygon")
	// The rest of the answer still arrives, so a missing shape costs nothing else.
	assert.Contains(t, body, "frontier_median_miles")
	assert.Contains(t, body, "reach_radius_miles")
}

// Asking for the shape must not disturb the numbers the same response carries, which is what
// the slider actually stores.
func TestTownNearPolygonDoesNotChangeTheOtherFields(t *testing.T) {
	seedTownNearEdinburgh(t)

	stubRouting(t, true)
	without := townNear(t, townNearEdinburgh)

	stubRouting(t, true)
	with := townNear(t, townNearEdinburgh+"&polygon=1")

	// Guard against a vacuous comparison: if routing produced nothing, every field would be
	// absent on both sides and this would pass while testing nothing.
	assert.Contains(t, without, "frontier_median_miles")

	for _, k := range []string{
		"cap_minutes", "density_band", "reach_radius_miles",
		"frontier_median_miles", "frontier_max_miles", "towns",
	} {
		assert.Equal(t, without[k], with[k], "field %s changed when the polygon was requested", k)
	}
}

// A request with no usable location has no reach to draw, and must not invent one.
func TestTownNearNoPolygonWithoutALocation(t *testing.T) {
	body := townNear(t, "lat=0&lng=0&minutes=30&polygon=1")
	assert.NotContains(t, body, "reach_polygon")
}

// A reachable place is named from the places gazetteer.
func TestTownNearNamesReachablePlaces(t *testing.T) {
	seedTownNearEdinburgh(t)
	stubRouting(t, false) // every point comes back 12.5 minutes away

	body := townNear(t, townNearEdinburgh)

	assert.Equal(t, []interface{}{"Testburgh"}, body["towns"])
	assert.NotContains(t, body, "closer_than")
}

// With nothing in reach, the nearest place comes back WITH its road distance. A bare "Close to X"
// read as the reach extending to X: a Wellingborough member was told "Max 1-2 miles by road.
// Close to Northampton", 12 miles away. Road, not crow-flies, because the reach beside it is in
// road miles.
func TestTownNearNearestPlaceCarriesItsDistance(t *testing.T) {
	// The road distance goes through the shared routing breaker; an earlier test that tripped it
	// would otherwise leave this one without a distance.
	roadblur.ResetRoutingBreaker()
	t.Cleanup(roadblur.ResetRoutingBreaker)
	seedTownNearEdinburgh(t)
	stubRouting(t, false) // 12.5 minutes, beyond a 10-minute budget

	body := townNear(t, "lat=55.9533&lng=-3.1883&minutes=10")

	assert.Empty(t, body["towns"])
	assert.Equal(t, "Testburgh", body["closer_than"])
	assert.Equal(t, stubRoadMiles, body["closer_miles"])
}

// When routing cannot give a road distance, the name comes back alone. A straight-line figure
// beside a reach shown in road miles would read as a road distance.
func TestTownNearNearestPlaceWithoutRoadDistance(t *testing.T) {
	roadblur.ResetRoutingBreaker()
	t.Cleanup(roadblur.ResetRoutingBreaker)
	seedTownNearEdinburgh(t)

	// Reach answers as usual (Testburgh 12.5 minutes away, beyond the budget), but drive-metrics
	// has no road to any target.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var req map[string]interface{}
		_ = json2.Unmarshal(body, &req)
		w.Header().Set("Content-Type", "application/json")
		if r.URL.Path == "/v1/drive-metrics" {
			targets, _ := req["targets"].([]interface{})
			results := make([]map[string]interface{}, len(targets))
			for i, tg := range targets {
				results[i] = map[string]interface{}{"id": tg.(map[string]interface{})["id"], "mins": nil, "miles": nil}
			}
			_ = json2.NewEncoder(w).Encode(map[string]interface{}{"results": results})
			return
		}
		pts, _ := req["points"].([]interface{})
		results := make([]map[string]interface{}, len(pts))
		for i := range results {
			results[i] = map[string]interface{}{"drive_min": 12.5}
		}
		_ = json2.NewEncoder(w).Encode(map[string]interface{}{
			"results": results, "frontier_median_miles": 4.0, "frontier_max_miles": 5.0,
		})
	}))
	t.Cleanup(srv.Close)
	t.Setenv("ROUTING_EVAL_URL", srv.URL)

	body := townNear(t, "lat=55.9533&lng=-3.1883&minutes=10")

	assert.Equal(t, "Testburgh", body["closer_than"])
	assert.NotContains(t, body, "closer_miles")
}
