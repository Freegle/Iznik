package main

import (
	"fmt"
	"testing"
)

// buildSnapFixture builds:
//   - a "mainland" chain of 1100 residential nodes (IDs 1..1100) along lng -1.000
//   - an "island" chain of 1050 nodes (IDs 1101..2150) along lng -3.000,
//     disconnected from the mainland (a real island, big network)
//   - a 3-node disconnected fragment (IDs 2151..2153) along lng -0.9985,
//     right next to the mainland probe point (a marina loop / private estate)
func buildSnapFixture() *Graph {
	var nodes []RawNodeSpec
	var ways []RawWaySpec

	mainIDs := make([]int64, 1100)
	for i := 0; i < 1100; i++ {
		id := int64(i + 1)
		mainIDs[i] = id
		nodes = append(nodes, RawNodeSpec{OSMID: id, Lat: 51.5 + float64(i)*0.0002, Lng: -1.000})
	}
	ways = append(ways, RawWaySpec{NodeIDs: mainIDs, Highway: "residential"})

	islandIDs := make([]int64, 1050)
	for i := 0; i < 1050; i++ {
		id := int64(10000 + i)
		islandIDs[i] = id
		nodes = append(nodes, RawNodeSpec{OSMID: id, Lat: 51.5 + float64(i)*0.0002, Lng: -3.000})
	}
	ways = append(ways, RawWaySpec{NodeIDs: islandIDs, Highway: "residential"})

	fragIDs := []int64{20001, 20002, 20003}
	for i, id := range fragIDs {
		nodes = append(nodes, RawNodeSpec{OSMID: id, Lat: 51.5001 + float64(i)*0.0001, Lng: -0.9985})
	}
	ways = append(ways, RawWaySpec{NodeIDs: fragIDs, Highway: "service"})

	return BuildGraphFromRaw(nodes, ways, nil)
}

func TestDriveSnappingSkipsTinyFragments(t *testing.T) {
	g := buildSnapFixture()

	// Probe sits between the fragment (about 25m away) and the mainland chain
	// (about 90m away).  Naive nearest-node snapping picks the fragment and
	// makes the whole road network unreachable; drive snapping must skip it.
	probeLat, probeLng := 51.5002, -0.99885

	id := nearestNodeForMode(g, probeLat, probeLng, Drive)
	if id == noNode {
		t.Fatalf("expected a drive snap, got none")
	}
	if id > 1100 {
		t.Errorf("drive snap picked node %d (fragment), want a mainland node", id)
	}

	// End to end: an isochrone from the probe must reach well down the chain.
	iso := Isochrone(g, probeLat, probeLng, 600, Drive)
	if len(iso.ReachedNodes) < 50 {
		t.Errorf("drive isochrone from probe reached only %d nodes - snapped to a fragment?", len(iso.ReachedNodes))
	}
}

func TestDriveSnappingStillReachesIslands(t *testing.T) {
	g := buildSnapFixture()
	// A probe next to the island must snap to the island network (component
	// size 1050 >= threshold): islands are not fragments.
	id := nearestNodeForMode(g, 51.55, -3.0003, Drive)
	if id == noNode {
		t.Fatalf("expected an island drive snap, got none")
	}
	if id <= 1100 || id > 2150 {
		t.Errorf("island probe snapped to node %d, want an island node (1101..2150)", id)
	}
}

func TestWalkSnappingUnchangedByFragmentFilter(t *testing.T) {
	g := buildSnapFixture()
	// Walk mode keeps plain nearest-node behaviour (the walk network is far
	// less prone to fragment traps, and walkers can use paths we don't model).
	id := nearestNodeForMode(g, 51.5002, -0.99885, Walk)
	if id == noNode {
		t.Fatalf("expected a walk snap")
	}
	if id <= 2150 {
		t.Errorf("walk probe should still snap to the nearest node (fragment), got %d", id)
	}
}

func ExampleBuildGraphFromRaw_componentSizes() {
	g := buildSnapFixture()
	n := 0
	if g.DriveSnappable != nil {
		for _, ok := range g.DriveSnappable {
			if ok {
				n++
			}
		}
	}
	fmt.Println(n)
	// Output: 2150
}
