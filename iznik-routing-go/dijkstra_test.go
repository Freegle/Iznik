package main

import (
	"testing"
)

// testGraph is the shared 50×50 grid used across all algorithm tests.
var testGraph *Graph

func getTestGraph(t *testing.T) *Graph {
	t.Helper()
	if testGraph != nil {
		return testGraph
	}
	testGraph = makeTestGrid(nil)
	return testGraph
}

func TestIsochrone_Walk15min(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60)

	if len(result.ReachedNodes) < 100 {
		t.Errorf("15-min walk from Bristol centre: expected ≥100 nodes, got %d", len(result.ReachedNodes))
	}
	t.Logf("15-min walk: %d nodes reachable", len(result.ReachedNodes))

	for id, secs := range result.ReachedNodes {
		if secs > 15*60+1 {
			t.Errorf("node %d has time %f > limit", id, secs)
			break
		}
	}
}

func TestIsochrone_LongerBudgetReachesMore(t *testing.T) {
	g := getTestGraph(t)
	// A car crosses the whole 3.5km fixture grid in well under 15 minutes, so
	// the budgets have to be small enough that the reach is still growing.
	rShort := Isochrone(g, 51.4545, -2.5879, 60)
	rLong := Isochrone(g, 51.4545, -2.5879, 120)

	t.Logf("drive 60s=%d 120s=%d", len(rShort.ReachedNodes), len(rLong.ReachedNodes))
	if len(rLong.ReachedNodes) <= len(rShort.ReachedNodes) {
		t.Errorf("a longer budget should reach more; 60s=%d 120s=%d",
			len(rShort.ReachedNodes), len(rLong.ReachedNodes))
	}
}

func TestNearestNode_CloseToGridCentre(t *testing.T) {
	g := getTestGraph(t)
	// Query at the test grid's centre (row 24, col 25 ≈ lat 51.454, lng −2.588).
	// The nearest node should be within one grid cell (≈100 m).
	queryLat, queryLng := 51.4545, -2.5879
	id := nearestDriveNode(g, queryLat, queryLng)
	if id == noNode {
		t.Fatal("nearestNode returned noNode")
	}
	n := g.Nodes[id]
	d := haversineM(queryLat, queryLng, float64(n.Lat), float64(n.Lng))
	if d > 150 {
		t.Errorf("nearest node is %fm away from query, expected <150m", d)
	}
	t.Logf("nearest node: id=%d lat=%.5f lng=%.5f dist=%.0fm", id, n.Lat, n.Lng, d)
}

// TestIsochrone_ReportsUnsnappedOrigin covers the failure that hid a missing
// island for a year: ask for an isochrone somewhere the graph has no roads and
// the answer is an empty set, indistinguishable from a genuinely tiny reach.
//
// The Isle of Man was absent from the OSM extract the graph was built from, so
// every reach, ripple and nearby-browse query for its 825 members returned
// nothing, with no error and nothing in the logs. OriginFound is what separates
// "we looked and there is nothing near you" from "you are not on our map".
func TestIsochrone_ReportsUnsnappedOrigin(t *testing.T) {
	g := getTestGraph(t)

	on := Isochrone(g, 51.4545, -2.5879, 15*60)
	if !on.OriginFound {
		t.Errorf("origin on the graph: OriginFound = false, want true")
	}
	if len(on.ReachedNodes) == 0 {
		t.Errorf("origin on the graph: reached nothing")
	}

	// Douglas, Isle of Man - far beyond the ~11km the snap search covers.
	off := Isochrone(g, 54.1509, -4.4814, 30*60)
	if off.OriginFound {
		t.Errorf("origin with no road within snapping range: OriginFound = true, want false")
	}
	if len(off.ReachedNodes) != 0 {
		t.Errorf("unsnapped origin reached %d nodes, want 0", len(off.ReachedNodes))
	}
}
