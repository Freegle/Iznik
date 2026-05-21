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
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)

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

func TestIsochrone_Drive15min_LargerThanWalk(t *testing.T) {
	g := getTestGraph(t)
	walk := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	drive := Isochrone(g, 51.4545, -2.5879, 15*60, Drive)

	t.Logf("15-min walk=%d nodes, drive=%d nodes", len(walk.ReachedNodes), len(drive.ReachedNodes))
	if len(drive.ReachedNodes) <= len(walk.ReachedNodes) {
		t.Errorf("drive should reach more nodes than walk in same time; walk=%d drive=%d",
			len(walk.ReachedNodes), len(drive.ReachedNodes))
	}
}

func TestIsochrone_30min_LargerThan15min(t *testing.T) {
	g := getTestGraph(t)
	r15 := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	r30 := Isochrone(g, 51.4545, -2.5879, 30*60, Walk)

	t.Logf("walk 15min=%d 30min=%d", len(r15.ReachedNodes), len(r30.ReachedNodes))
	if len(r30.ReachedNodes) <= len(r15.ReachedNodes) {
		t.Errorf("30-min should reach more than 15-min; 15=%d 30=%d",
			len(r15.ReachedNodes), len(r30.ReachedNodes))
	}
}

func TestIsochrone_Cycle_BetweenWalkAndDrive(t *testing.T) {
	g := getTestGraph(t)
	walk := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	cycle := Isochrone(g, 51.4545, -2.5879, 15*60, Cycle)
	drive := Isochrone(g, 51.4545, -2.5879, 15*60, Drive)

	t.Logf("15-min walk=%d cycle=%d drive=%d", len(walk.ReachedNodes), len(cycle.ReachedNodes), len(drive.ReachedNodes))
	if len(cycle.ReachedNodes) <= len(walk.ReachedNodes) {
		t.Errorf("cycle should reach more than walk; walk=%d cycle=%d",
			len(walk.ReachedNodes), len(cycle.ReachedNodes))
	}
	if len(drive.ReachedNodes) <= len(cycle.ReachedNodes) {
		t.Errorf("drive should reach more than cycle; cycle=%d drive=%d",
			len(cycle.ReachedNodes), len(drive.ReachedNodes))
	}
}

func TestNearestNode_CloseToGridCentre(t *testing.T) {
	g := getTestGraph(t)
	// Query at the test grid's centre (row 24, col 25 ≈ lat 51.454, lng −2.588).
	// The nearest node should be within one grid cell (≈100 m).
	queryLat, queryLng := 51.4545, -2.5879
	id := nearestNodeForMode(g, queryLat, queryLng, Walk)
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
