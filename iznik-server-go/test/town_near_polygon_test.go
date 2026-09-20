package test

import (
	json2 "encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
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

// townNearTownID is well above any real towns row, so seeding cannot collide with data a
// future fixture adds.
const townNearTownID = 990001

// seedTownNearEdinburgh puts one town inside the handler's candidate box.
//
// Required, not incidental: the schema-only test database has an EMPTY towns table, and with no
// candidate towns the handler returns its "no candidates" response BEFORE it ever calls the
// routing server. Without this, every assertion below would pass against a response that never
// exercised the code under test.
func seedTownNearEdinburgh(t *testing.T) {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT IGNORE INTO towns (id, name, lat, lng) VALUES (?, ?, ?, ?)",
		townNearTownID, "Testburgh", 55.95, -3.19)
	t.Cleanup(func() {
		db.Exec("DELETE FROM towns WHERE id = ?", townNearTownID)
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

// stubRouting stands in for the routing server's /v1/ripple-eval. It records the request body
// each call, and returns a polygon only when one was asked for - the same contract the real
// server honours. Returns the recorder.
func stubRouting(t *testing.T, withPolygon bool) *[]map[string]interface{} {
	t.Helper()
	var seen []map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var req map[string]interface{}
		_ = json2.Unmarshal(body, &req)
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
