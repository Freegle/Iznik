package main

import (
	"math"
	"testing"
)

// For an offer rippling INTO a group, groupProximity must return the in-group point nearest
// the offer (P) and then the in-group point furthest FROM P (Q) — not from the offer. On a
// west→east line with the offer off the west end and the group = the eastern nodes, P is the
// group's west end and Q is its east end, and P→Q is longer than offer→P.
func TestGroupProximity_ClosestThenFurthestFromClosest(t *testing.T) {
	g := makeLineGraph(7) // NodeIDs 1..7 west→east
	group := []NodeID{3, 4, 5, 6, 7}
	offerLat, offerLng := float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng) // node 1 = west, outside group

	closest, furthest, ok := groupProximity(g, offerLat, offerLng, group, Walk, 1e9)
	if !ok {
		t.Fatal("groupProximity returned ok=false")
	}
	// P = node 3 (the group's west end, nearest the offer)
	if closest.Lat != float64(g.Nodes[3].Lat) || closest.Lng != float64(g.Nodes[3].Lng) {
		t.Errorf("closest (P) should be node 3, got %+v", closest)
	}
	// Q = node 7 (furthest from node 3 within the group)
	if furthest.Lat != float64(g.Nodes[7].Lat) || furthest.Lng != float64(g.Nodes[7].Lng) {
		t.Errorf("furthest (Q) should be node 7, got %+v", furthest)
	}
	// The message contrast holds: offer→P < P→Q here.
	if !(furthest.DriveMin > closest.DriveMin) {
		t.Errorf("expected P→Q (%.2f) > offer→P (%.2f)", furthest.DriveMin, closest.DriveMin)
	}
}

// groupDiameter estimates a group's own road "width" — the longest road drive-time between two
// of its points. On a west→east line whose group is nodes 3..7, the widest span is end-to-end:
// node 3 ↔ node 7, and the reported time must be positive and equal that span. The reported road
// distance (miles) must also be positive and match the direct (great-circle) distance between
// the two ends, since the line graph is a straight run of colinear points — the fastest-path
// route IS the direct route.
func TestGroupDiameter_LineGraphSpansEnds(t *testing.T) {
	g := makeLineGraph(7)
	group := []NodeID{3, 4, 5, 6, 7}

	from, to, miles, ok := groupDiameter(g, group, Walk, 1e9)
	if !ok {
		t.Fatal("groupDiameter returned ok=false")
	}
	isEnd := func(p ProxPoint, id NodeID) bool {
		return p.Lat == float64(g.Nodes[id].Lat) && p.Lng == float64(g.Nodes[id].Lng)
	}
	// endpoints must be the two ends of the group (nodes 3 and 7), in either order
	ends := (isEnd(from, 3) && isEnd(to, 7)) || (isEnd(from, 7) && isEnd(to, 3))
	if !ends {
		t.Errorf("expected endpoints at nodes 3 and 7, got from=%+v to=%+v", from, to)
	}
	if to.DriveMin <= 0 {
		t.Errorf("expected a positive diameter, got %.3f", to.DriveMin)
	}
	if miles <= 0 {
		t.Errorf("expected a positive road distance, got %.5f miles", miles)
	}
	wantMiles := haversineM(
		float64(g.Nodes[3].Lat), float64(g.Nodes[3].Lng),
		float64(g.Nodes[7].Lat), float64(g.Nodes[7].Lng),
	) / metresPerMile
	if math.Abs(miles-wantMiles)/wantMiles > 0.001 {
		t.Errorf("expected miles ~= %.5f (direct distance), got %.5f", wantMiles, miles)
	}
}

// pathMetres must sum the exact hop distances recorded in a shortest-path-tree `prev` map, walked
// from a target back to the sweep's origin — not an approximation of the direct distance.
func TestPathMetres_SumsAlongTree(t *testing.T) {
	g := makeLineGraph(4) // NodeIDs 1..4 west→east, colinear
	prev := map[NodeID]NodeID{
		2: 1,
		3: 2,
		4: 3,
	}
	got := pathMetres(g, prev, 4)
	want := haversineM(float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), float64(g.Nodes[2].Lat), float64(g.Nodes[2].Lng)) +
		haversineM(float64(g.Nodes[2].Lat), float64(g.Nodes[2].Lng), float64(g.Nodes[3].Lat), float64(g.Nodes[3].Lng)) +
		haversineM(float64(g.Nodes[3].Lat), float64(g.Nodes[3].Lng), float64(g.Nodes[4].Lat), float64(g.Nodes[4].Lng))
	if got != want {
		t.Errorf("pathMetres = %.10f, want exact sum %.10f", got, want)
	}
	// A node with no entry in prev (the tree root) sums to zero.
	if got := pathMetres(g, prev, 1); got != 0 {
		t.Errorf("pathMetres for root node = %.5f, want 0", got)
	}
}
