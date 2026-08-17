package main

import (
	"testing"
)

// Helpers -------------------------------------------------------------------

// driveSecs returns the drive seconds of the edge from->to, or -2 if absent.
func driveSecs(g *Graph, from, to NodeID) float32 {
	for _, e := range g.EdgesFrom(from) {
		if e.To == to {
			return e.Seconds[Drive]
		}
	}
	return -2
}

func modeSecs(g *Graph, from, to NodeID, m Mode) float32 {
	for _, e := range g.EdgesFrom(from) {
		if e.To == to {
			return e.Seconds[m]
		}
	}
	return -2
}

// A straight residential chain 1-2-3 with ~111m spacing; node 2 optionally
// carries a highway=* node tag.  Returns the built graph.
func chainWithMidNodeTag(tag string) *Graph {
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000, Highway: tag},
		{OSMID: 3, Lat: 51.502, Lng: -1.000},
	}
	ways := []RawWaySpec{{NodeIDs: []int64{1, 2, 3}, Highway: "residential"}}
	return BuildGraphFromRaw(nodes, ways, nil)
}

// Node-feature penalties ----------------------------------------------------

func TestSignalPenaltyAddedToDriveSecondsOnly(t *testing.T) {
	g := chainWithMidNodeTag("traffic_signals")

	into := driveSecs(g, 1, 2)   // to-node is the signal
	onward := driveSecs(g, 2, 3) // to-node is plain

	if drivePenalties.Signal <= 0 {
		t.Fatalf("expected a positive signal penalty, got %f", drivePenalties.Signal)
	}
	want := onward + drivePenalties.Signal
	if absF32(into-want) > 0.01 {
		t.Errorf("edge into signal node: got %f, want %f (plain %f + signal %f)",
			into, want, onward, drivePenalties.Signal)
	}

	// Walk and cycle must be unaffected by drive penalties.
	if absF32(modeSecs(g, 1, 2, Walk)-modeSecs(g, 2, 3, Walk)) > 0.01 {
		t.Errorf("walk seconds should not carry the signal penalty")
	}
	if absF32(modeSecs(g, 1, 2, Cycle)-modeSecs(g, 2, 3, Cycle)) > 0.01 {
		t.Errorf("cycle seconds should not carry the signal penalty")
	}

	// The penalty attaches to travel INTO the node from either direction.
	back := driveSecs(g, 3, 2)
	if absF32(back-(driveSecs(g, 2, 1)+drivePenalties.Signal)) > 0.01 {
		t.Errorf("reverse edge into signal node should carry the penalty too")
	}
}

func TestCrossingPenaltyAddedToDriveSeconds(t *testing.T) {
	g := chainWithMidNodeTag("crossing")
	into := driveSecs(g, 1, 2)
	onward := driveSecs(g, 2, 3)
	if drivePenalties.Crossing <= 0 {
		t.Fatalf("expected a positive crossing penalty")
	}
	if absF32(into-(onward+drivePenalties.Crossing)) > 0.01 {
		t.Errorf("edge into crossing node: got %f, want %f", into, onward+drivePenalties.Crossing)
	}
}

func TestMiniRoundaboutPenaltyAddedToDriveSeconds(t *testing.T) {
	g := chainWithMidNodeTag("mini_roundabout")
	into := driveSecs(g, 1, 2)
	onward := driveSecs(g, 2, 3)
	if drivePenalties.Roundabout <= 0 {
		t.Fatalf("expected a positive mini-roundabout penalty")
	}
	if absF32(into-(onward+drivePenalties.Roundabout)) > 0.01 {
		t.Errorf("edge into mini-roundabout: got %f, want %f", into, onward+drivePenalties.Roundabout)
	}
}

func TestUnrelatedNodeTagAddsNoPenalty(t *testing.T) {
	g := chainWithMidNodeTag("street_lamp")
	if absF32(driveSecs(g, 1, 2)-driveSecs(g, 2, 3)) > 0.01 {
		t.Errorf("street_lamp node must not change drive seconds")
	}
}

// Roundabout way penalty ----------------------------------------------------

func TestRoundaboutEdgesCarryEntryPenaltyAndAreOneway(t *testing.T) {
	// A plain one-way residential and an identical-length roundabout way.
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000},
		{OSMID: 11, Lat: 51.500, Lng: -1.100},
		{OSMID: 12, Lat: 51.501, Lng: -1.100},
	}
	ways := []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "residential", Oneway: true},
		{NodeIDs: []int64{11, 12}, Highway: "residential", Junction: "roundabout"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)

	if drivePenalties.Roundabout <= 0 {
		t.Fatalf("expected a positive roundabout edge penalty")
	}
	plain := driveSecs(g, 1, 2)
	rbt := driveSecs(g, 3, 4) // sequential IDs: 11->3, 12->4
	if absF32(rbt-(plain+drivePenalties.Roundabout)) > 0.01 {
		t.Errorf("roundabout edge: got %f, want %f", rbt, plain+drivePenalties.Roundabout)
	}
	// junction=roundabout implies oneway: no reverse edge.
	if driveSecs(g, 4, 3) != -2 {
		t.Errorf("roundabout way should be oneway (no reverse edge)")
	}
}

// Junction penalty ----------------------------------------------------------

func TestJunctionPenaltyAtTJunctionButNotWayBoundary(t *testing.T) {
	// Through road A: 1-2-3.  Side road B: 4-2 (ends at node 2, which is
	// INTERIOR to A) -> node 2 is a real T-junction.
	// Separately, road C: 5-6 and road D: 6-7 share endpoint 6 (a way split
	// mid-road, e.g. a tag change) -> node 6 is NOT a junction.
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000},
		{OSMID: 3, Lat: 51.502, Lng: -1.000},
		{OSMID: 4, Lat: 51.501, Lng: -1.002},
		{OSMID: 5, Lat: 51.500, Lng: -1.010},
		{OSMID: 6, Lat: 51.501, Lng: -1.010},
		{OSMID: 7, Lat: 51.502, Lng: -1.010},
	}
	ways := []RawWaySpec{
		{NodeIDs: []int64{1, 2, 3}, Highway: "residential"},
		{NodeIDs: []int64{4, 2}, Highway: "residential"},
		{NodeIDs: []int64{5, 6}, Highway: "residential"},
		{NodeIDs: []int64{6, 7}, Highway: "residential"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)

	if drivePenalties.Junction <= 0 {
		t.Fatalf("expected a positive junction penalty")
	}
	// 1->2 travels into the T-junction; 5->6 into the way boundary. Same
	// length, same class, so the difference must be exactly the penalty.
	intoJunction := driveSecs(g, 1, 2)
	intoBoundary := driveSecs(g, 5, 6)
	if absF32(intoJunction-(intoBoundary+drivePenalties.Junction)) > 0.01 {
		t.Errorf("T-junction edge: got %f, want %f (boundary %f + junction %f)",
			intoJunction, intoBoundary+drivePenalties.Junction, intoBoundary, drivePenalties.Junction)
	}
	// Onward edge 2->3 leaves the junction: its to-node (3) is plain, no penalty.
	if absF32(driveSecs(g, 2, 3)-intoBoundary) > 0.01 {
		t.Errorf("edge leaving the junction should carry no penalty")
	}
}

func TestJunctionPenaltySkippedOnSRNAndAtSignals(t *testing.T) {
	// Motorway through 1-2-3 with a slip joining at node 2: SRN edges are
	// exempt from the junction penalty (grade-separated).
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000},
		{OSMID: 3, Lat: 51.502, Lng: -1.000},
		{OSMID: 4, Lat: 51.501, Lng: -1.002},
	}
	ways := []RawWaySpec{
		{NodeIDs: []int64{1, 2, 3}, Highway: "motorway", Oneway: true},
		{NodeIDs: []int64{4, 2}, Highway: "motorway_link", Oneway: true},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	// 1->2 rides into a junction node on a motorway: no penalty, so the two
	// motorway edges (same length) must cost the same.
	if absF32(driveSecs(g, 1, 2)-driveSecs(g, 2, 3)) > 0.01 {
		t.Errorf("motorway junction should not attract the junction penalty")
	}

	// A signal-controlled T-junction charges the signal penalty only, not both.
	nodes2 := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000, Highway: "traffic_signals"},
		{OSMID: 3, Lat: 51.502, Lng: -1.000},
		{OSMID: 4, Lat: 51.501, Lng: -1.002},
	}
	ways2 := []RawWaySpec{
		{NodeIDs: []int64{1, 2, 3}, Highway: "residential"},
		{NodeIDs: []int64{4, 2}, Highway: "residential"},
	}
	g2 := BuildGraphFromRaw(nodes2, ways2, nil)
	into := driveSecs(g2, 1, 2)
	onward := driveSecs(g2, 2, 3)
	if absF32(into-(onward+drivePenalties.Signal)) > 0.01 {
		t.Errorf("signal at junction: want signal penalty only, got extra %f", into-onward-drivePenalties.Signal)
	}
}

func absF32(f float32) float32 {
	if f < 0 {
		return -f
	}
	return f
}
