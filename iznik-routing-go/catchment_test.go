package main

import "testing"

// makeLineGraph builds a straight west→east chain of n nodes ~100m apart, joined by a
// bidirectional residential road. Node i (0-based) has NodeID i+1.
func makeLineGraph(n int) *Graph {
	nodes := make([]RawNodeSpec, 0, n)
	ids := make([]int64, 0, n)
	for i := 0; i < n; i++ {
		nodes = append(nodes, RawNodeSpec{
			OSMID: int64(i + 1),
			Lat:   51.4500,
			Lng:   -2.6000 + float64(i)*0.0015,
		})
		ids = append(ids, int64(i+1))
	}
	ways := []RawWaySpec{{NodeIDs: ids, Highway: "residential"}}
	return BuildGraphFromRaw(nodes, ways, nil)
}

// A catchment seeded from the whole boundary must include areas reachable from ANY seed. A
// single-source isochrone from one end misses the far end within a tight budget; seeding both
// ends covers both.
func TestMultiSourceIsochrone_CoversAllSeeds(t *testing.T) {
	g := makeLineGraph(7) // NodeIDs 1..7 west→east
	budget := Isochrone(g, float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), 1e9).ReachedNodes[3]
	if budget <= 0 {
		t.Fatal("degenerate line graph")
	}
	single := multiSourceIsochrone(g, []NodeID{1}, budget)
	multi := multiSourceIsochrone(g, []NodeID{1, 7}, budget)

	if _, ok := single.ReachedNodes[7]; ok {
		t.Error("single-source from node 1 should NOT reach node 7 within the tight budget")
	}
	if _, ok := multi.ReachedNodes[7]; !ok {
		t.Error("multi-source seeded at node 7 must include node 7")
	}
	if _, ok := multi.ReachedNodes[5]; !ok {
		t.Error("multi-source should reach node 5 (near the node-7 seed)")
	}
}
