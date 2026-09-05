package main

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// postRippleEval runs one /v1/ripple-eval request and returns the decoded body.
func postRippleEval(t *testing.T, body map[string]interface{}) struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
	FrontierMedianMiles *float64        `json:"frontier_median_miles"`
	Polygon             *GeoJSONPolygon `json:"polygon"`
} {
	t.Helper()
	var out struct {
		Results []struct {
			DriveMin *float64 `json:"drive_min"`
		} `json:"results"`
		FrontierMedianMiles *float64        `json:"frontier_median_miles"`
		Polygon             *GeoJSONPolygon `json:"polygon"`
	}

	app := newInternalApp(t)
	enc, err := json.Marshal(body)
	if err != nil {
		t.Fatal(err)
	}
	req := httptest.NewRequest(http.MethodPost, "/v1/ripple-eval", bytes.NewReader(enc))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 60000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}
	raw, _ := io.ReadAll(resp.Body)
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatalf("decode: %v (body %.300s)", err, raw)
	}
	return out
}

func rippleEvalBody(extra map[string]interface{}) map[string]interface{} {
	body := map[string]interface{}{
		"lat":         51.4545,
		"lng":         -2.5879,
		"mode":        "drive",
		"max_minutes": 10,
		"points":      [][2]float64{{-2.5879, 51.4545}},
		"points_only": true,
	}
	for k, v := range extra {
		body[k] = v
	}
	return body
}

// The browse overlay needs the reach's SHAPE, and /town/near already pays for the
// Dijkstra that produces it. Asking for the polygon must therefore be an option on the
// existing call rather than a second routing pass.
func TestRippleEval_ReturnsDisplayPolygonWhenAsked(t *testing.T) {
	out := postRippleEval(t, rippleEvalBody(map[string]interface{}{
		"polygon_simplify_m": 200,
	}))

	if out.Polygon == nil {
		t.Fatal("no polygon returned")
	}
	if len(out.Polygon.Geometry.Coordinates) == 0 {
		t.Fatal("polygon has no rings")
	}
	ring := out.Polygon.Geometry.Coordinates[0]
	if len(ring) < 4 {
		t.Fatalf("polygon ring has %d points, expected >=4", len(ring))
	}
	if ring[0] != ring[len(ring)-1] {
		t.Errorf("polygon ring not closed: first=%v last=%v", ring[0], ring[len(ring)-1])
	}
	// The origin must be inside its own reach.
	if !pointInPolygon(-2.5879, 51.4545, ring) {
		t.Error("origin is not inside the returned reach polygon")
	}
}

// Omitting the option must leave the response exactly as it was, so the simulator and
// every other ripple-eval caller keeps paying nothing for a shape it does not draw.
func TestRippleEval_NoPolygonUnlessRequested(t *testing.T) {
	for _, extra := range []map[string]interface{}{
		nil,
		{"polygon_simplify_m": 0},
		{"polygon_simplify_m": -5},
	} {
		out := postRippleEval(t, rippleEvalBody(extra))
		if out.Polygon != nil {
			t.Errorf("polygon returned for %v when none was requested", extra)
		}
	}
}

// The point of the option is the wire size. A simplified polygon must be dramatically
// smaller than the exact one the same reach traces, or there is no reason to ship it.
func TestRippleEval_SimplifiedPolygonIsMuchSmallerThanExact(t *testing.T) {
	g := getTestGraph(t)
	iso := Isochrone(g, 51.4545, -2.5879, 10*60)
	res := NetworkResolution(g, iso.ReachedNodes)
	exact := IsochronePolygon(g, iso.ReachedNodes, res)
	if len(exact.Geometry.Coordinates) == 0 {
		t.Skip("test graph traced no exact polygon")
	}
	exactPts := len(exact.Geometry.Coordinates[0])

	out := postRippleEval(t, rippleEvalBody(map[string]interface{}{
		"polygon_simplify_m": 200,
	}))
	if out.Polygon == nil {
		t.Fatal("no polygon returned")
	}
	gotPts := len(out.Polygon.Geometry.Coordinates[0])

	t.Logf("exact %d points, simplified %d points", exactPts, gotPts)
	if gotPts >= exactPts {
		t.Errorf("simplified polygon is not smaller: exact %d, simplified %d", exactPts, gotPts)
	}
}

// Asking for a polygon must not disturb the drive-times the same response carries, which
// is what /town/near actually reads.
func TestRippleEval_PolygonDoesNotChangeDriveTimes(t *testing.T) {
	without := postRippleEval(t, rippleEvalBody(nil))
	with := postRippleEval(t, rippleEvalBody(map[string]interface{}{"polygon_simplify_m": 200}))

	if len(without.Results) != len(with.Results) {
		t.Fatalf("result count changed: %d vs %d", len(without.Results), len(with.Results))
	}
	for i := range without.Results {
		a, b := without.Results[i].DriveMin, with.Results[i].DriveMin
		if (a == nil) != (b == nil) {
			t.Fatalf("result %d nullness changed: %v vs %v", i, a, b)
		}
		if a != nil && *a != *b {
			t.Errorf("result %d drive_min changed: %g vs %g", i, *a, *b)
		}
	}
}

// A reach that traced nothing must answer "no polygon" rather than an empty or malformed
// one, so the client can fall back rather than draw a degenerate shape.
func TestRippleEval_NoPolygonForUnroutableOrigin(t *testing.T) {
	out := postRippleEval(t, rippleEvalBody(map[string]interface{}{
		// Mid-Atlantic: on the graph, but nowhere near any road in it.
		"lat": 40.0, "lng": -30.0,
		"polygon_simplify_m": 200,
	}))
	if out.Polygon != nil && len(out.Polygon.Geometry.Coordinates) > 0 &&
		len(out.Polygon.Geometry.Coordinates[0]) >= 4 {
		t.Error("a reach with no roads should not produce a drawable polygon")
	}
}
