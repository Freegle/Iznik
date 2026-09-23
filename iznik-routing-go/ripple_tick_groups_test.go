package main

import (
	"reflect"
	"testing"
)

// snapMembers turns active members into (group, drive-seconds) via each member's nearest
// road node; members whose street is not reached at all (severed bank, offshore) are dropped.
// groupIDsWithinSeconds then answers "which groups are reached at this tick's drive-time" by
// thresholding - no geometry. Together they make the per-tick group tint on the explorer the
// same decision the targeting makes.
func TestSnapMembersAndTickGroups(t *testing.T) {
	g := makeLineGraph(7) // nodes 1..7 west->east, walk times increase with distance

	// Reach everything from the west end so every node has a known walk time.
	iso := Isochrone(g, float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), 1e9)

	nearSecs := iso.ReachedNodes[2]
	farSecs := iso.ReachedNodes[6]
	if !(nearSecs < farSecs) {
		t.Fatalf("fixture: expected node 2 nearer than node 6 (%v vs %v)", nearSecs, farSecs)
	}

	members := []memberLoc{
		{groupID: 100, lat: float64(g.Nodes[2].Lat), lng: float64(g.Nodes[2].Lng)}, // near group
		{groupID: 200, lat: float64(g.Nodes[6].Lat), lng: float64(g.Nodes[6].Lng)}, // far group
		{groupID: 300, lat: 59.0, lng: 1.0},                                        // no road node: dropped
	}

	snaps := snapMembers(g, iso.ReachedNodes, members)
	if len(snaps) != 2 {
		t.Fatalf("snapMembers kept %d members, want 2 (offshore dropped)", len(snaps))
	}

	// At a tick budget between near and far, only the near group is reached.
	midBudget := (nearSecs + farSecs) / 2
	if got := groupIDsWithinSeconds(snaps, midBudget); !reflect.DeepEqual(got, []int64{100}) {
		t.Fatalf("mid-tick groups = %v, want [100]", got)
	}
	// At the full budget both are reached, sorted, deduped.
	if got := groupIDsWithinSeconds(snaps, farSecs); !reflect.DeepEqual(got, []int64{100, 200}) {
		t.Fatalf("full-tick groups = %v, want [100 200]", got)
	}
	// Before anyone is reached: empty but non-nil.
	if got := groupIDsWithinSeconds(snaps, nearSecs/2); got == nil || len(got) != 0 {
		t.Fatalf("zero-tick groups = %v, want empty non-nil", got)
	}
}

// A member whose nearest node exists but is NOT in the reached set (far bank of a severed
// crossing) must not appear in the snaps at any budget.
func TestSnapMembers_UnreachedNodeDropped(t *testing.T) {
	g := makeLineGraph(7)
	full := Isochrone(g, float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), 1e9).ReachedNodes

	// Simulate severance: remove the eastern node from the reached set.
	reached := make(map[NodeID]float32, len(full))
	for id, s := range full {
		if id != 7 {
			reached[id] = s
		}
	}
	members := []memberLoc{
		{groupID: 400, lat: float64(g.Nodes[7].Lat), lng: float64(g.Nodes[7].Lng)},
	}
	snaps := snapMembers(g, reached, members)
	if len(snaps) != 0 {
		t.Fatalf("severed member snapped anyway: %v", snaps)
	}
}
