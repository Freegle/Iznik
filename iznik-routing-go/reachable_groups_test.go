package main

import (
	"testing"
)

// boxGroup builds a rectangular groupPoly (closed ring, [lng,lat]) for tests.
func boxGroup(id int64, minLng, minLat, maxLng, maxLat float64) groupPoly {
	ring := [][2]float64{
		{minLng, minLat},
		{maxLng, minLat},
		{maxLng, maxLat},
		{minLng, maxLat},
		{minLng, minLat},
	}
	return newGroupPoly(id, [][][2]float64{ring})
}

func containsID(ids []int64, want int64) bool {
	for _, id := range ids {
		if id == want {
			return true
		}
	}
	return false
}

// A group whose polygon covers the reachable nodes is returned; a group far away
// (no reached node inside it) is not. This is the exact "does any reachable road
// node fall in the group's polygon?" signal the plan wants.
func TestReachableGroupIDs_SeparatedClusters(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	if len(result.ReachedNodes) == 0 {
		t.Fatal("expected some reached nodes from the Bristol test graph")
	}

	near := boxGroup(21656, -2.62, 51.43, -2.55, 51.48) // around the Bristol origin
	far := boxGroup(99999, 0.0, 51.0, 0.2, 51.2)         // ~2.7 deg east, no nodes

	ids := reachableGroupIDs(g, result.ReachedNodes, []groupPoly{near, far})

	if !containsID(ids, 21656) {
		t.Errorf("expected the covering group 21656 to be reachable, got %v", ids)
	}
	if containsID(ids, 99999) {
		t.Errorf("the far group 99999 has no reached node inside it and must not be reachable, got %v", ids)
	}
}

func TestReachableGroupIDs_Empty(t *testing.T) {
	g := getTestGraph(t)
	near := boxGroup(1, -2.62, 51.43, -2.55, 51.48)
	ids := reachableGroupIDs(g, map[NodeID]float32{}, []groupPoly{near})
	if len(ids) != 0 {
		t.Errorf("an empty reached-node set should yield no reachable groups, got %v", ids)
	}
}

// A group id appearing in more than one groupPoly (e.g. a MULTIPOLYGON split into
// parts) must be returned at most once.
func TestReachableGroupIDs_Dedupe(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	part1 := boxGroup(500, -2.62, 51.43, -2.55, 51.48)
	part2 := boxGroup(500, -2.60, 51.44, -2.56, 51.47) // same id, also covers origin
	ids := reachableGroupIDs(g, result.ReachedNodes, []groupPoly{part1, part2})
	count := 0
	for _, id := range ids {
		if id == 500 {
			count++
		}
	}
	if count != 1 {
		t.Errorf("group 500 should appear exactly once, appeared %d times (%v)", count, ids)
	}
}
