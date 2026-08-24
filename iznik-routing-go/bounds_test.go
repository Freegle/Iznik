package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Sandwich bounds for the catchment polygon (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md):
// the routing server derives them on the SAME rasterisation grid as the exact polygon, by
// morphological dilation/erosion, so the superset/subset guarantees hold by construction —
// unlike deriving them in MySQL from the (frequently invalid) stored geometry. The bounds are
// aggressively simplified: their whole point is to be tiny next to the exact grid outline.

// Every reached node must lie inside the OUTER bound (superset of the exact reach).
func TestIsochroneBounds_OuterContainsAllReachedNodes(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	res := AutoResolution(15*60, Walk)

	bounds := IsochroneBounds(g, result.ReachedNodes, res)
	if bounds.Outer == nil {
		t.Fatal("expected an outer bound for a non-empty reach")
	}
	outer := bounds.Outer.Geometry.Coordinates[0]
	if len(outer) < 4 {
		t.Fatalf("outer ring has %d points, expected ≥4", len(outer))
	}

	misses := 0
	for id := range result.ReachedNodes {
		n := g.Nodes[id]
		if !pointInPolygon(float64(n.Lng), float64(n.Lat), outer) {
			misses++
		}
	}
	if misses > 0 {
		t.Errorf("%d/%d reached nodes fall outside the outer bound — it must be a superset", misses, len(result.ReachedNodes))
	}
}

// Every vertex of the INNER bound must lie inside the exact polygon (subset of the reach).
// Vertices suffice as a strong sample: both shapes come from the same grid, and the erosion
// margin exceeds the simplification tolerance by design.
func TestIsochroneBounds_InnerWithinExactPolygon(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	res := AutoResolution(15*60, Walk)

	exact := IsochronePolygon(g, result.ReachedNodes, res)
	bounds := IsochroneBounds(g, result.ReachedNodes, res)
	if bounds.Inner == nil {
		// A small reach can legitimately erode to nothing; this fixture (15-min walk in
		// Bristol) is large enough that it must not.
		t.Fatal("expected an inner bound for a 15-minute walk reach")
	}

	exactRing := exact.Geometry.Coordinates[0]
	inner := bounds.Inner.Geometry.Coordinates[0]
	outside := 0
	for _, pt := range inner {
		if !pointInPolygon(pt[0], pt[1], exactRing) {
			outside++
		}
	}
	if outside > 0 {
		t.Errorf("%d/%d inner-bound vertices fall outside the exact polygon — it must be a subset", outside, len(inner))
	}

	// And the origin (deep inside the reach) should be cheap-accepted by the inner bound.
	if !pointInPolygon(-2.5879, 51.4545, inner) {
		t.Error("origin is not inside the inner bound")
	}
}

// The bounds only earn their keep if they are much smaller than the exact outline.
func TestIsochroneBounds_AreSimplified(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	res := AutoResolution(15*60, Walk)

	exact := IsochronePolygon(g, result.ReachedNodes, res)
	bounds := IsochroneBounds(g, result.ReachedNodes, res)
	if bounds.Outer == nil {
		t.Fatal("expected an outer bound")
	}

	exactN := len(exact.Geometry.Coordinates[0])
	outerN := len(bounds.Outer.Geometry.Coordinates[0])
	if outerN*2 >= exactN {
		t.Errorf("outer bound has %d points vs exact %d — expected at most half", outerN, exactN)
	}
	if bounds.Inner != nil {
		innerN := len(bounds.Inner.Geometry.Coordinates[0])
		if innerN*2 >= exactN {
			t.Errorf("inner bound has %d points vs exact %d — expected at most half", innerN, exactN)
		}
	}
}

// An empty reach produces no bounds rather than panicking.
func TestIsochroneBounds_EmptyReach(t *testing.T) {
	g := getTestGraph(t)
	bounds := IsochroneBounds(g, map[NodeID]float32{}, 0.001)
	if bounds.Outer != nil || bounds.Inner != nil {
		t.Error("empty reach must produce no bounds")
	}
}

// The point-form catchment endpoint ships the sandwich bounds alongside the exact
// polygon, so iznik-batch can store verified bounds without deriving them in MySQL.
func TestCatchmentEndpointReturnsBounds(t *testing.T) {
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/catchment?lat=51.4545&lng=-2.5879&minutes=15&mode=walk", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}
	var body struct {
		Catchment      *GeoJSONPolygon `json:"catchment"`
		CatchmentOuter *GeoJSONPolygon `json:"catchment_outer"`
		CatchmentInner *GeoJSONPolygon `json:"catchment_inner"`
	}
	raw, _ := io.ReadAll(resp.Body)
	if err := json.Unmarshal(raw, &body); err != nil {
		t.Fatal(err)
	}
	if body.Catchment == nil || len(body.Catchment.Geometry.Coordinates) == 0 {
		t.Fatal("no exact catchment polygon")
	}
	if body.CatchmentOuter == nil || len(body.CatchmentOuter.Geometry.Coordinates) == 0 {
		t.Fatal("expected catchment_outer bounds in the point-form response")
	}
	if body.CatchmentInner == nil || len(body.CatchmentInner.Geometry.Coordinates) == 0 {
		t.Fatal("expected catchment_inner bounds for a 15-minute walk reach")
	}
	exactN := len(body.Catchment.Geometry.Coordinates[0])
	outerN := len(body.CatchmentOuter.Geometry.Coordinates[0])
	if outerN >= exactN {
		t.Errorf("outer bound (%d pts) should be smaller than the exact polygon (%d pts)", outerN, exactN)
	}
}
