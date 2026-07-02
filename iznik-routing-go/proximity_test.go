package main

import "testing"

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
