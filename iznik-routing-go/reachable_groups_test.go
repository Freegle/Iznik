package main

import (
	"math"
	"reflect"
	"testing"
)

// A group counts as reached only if it has an active member whose OWN location is
// road-reachable from the origin - i.e. the member's nearest road node is in the Dijkstra
// reached set. A member on the far side of a severance (nearest node not reached) does not
// pull their group in, even though a polygon overlap test might. A member with no nearby
// road node at all (e.g. offshore) is ignored.
func TestFreeglerReachableGroupIDs_MemberOwnReachability(t *testing.T) {
	g := makeLineGraph(7) // colinear nodes west->east, ~111m apart, 1-based ids

	// Reach only the western half of the line.
	minLng, maxLng := math.MaxFloat64, -math.MaxFloat64
	var westID, eastID NodeID
	for id := 1; id < len(g.Nodes); id++ {
		lng := float64(g.Nodes[id].Lng)
		if lng < minLng {
			minLng, westID = lng, NodeID(id)
		}
		if lng > maxLng {
			maxLng, eastID = lng, NodeID(id)
		}
	}
	mid := (minLng + maxLng) / 2
	reached := map[NodeID]float32{}
	for id := 1; id < len(g.Nodes); id++ {
		if float64(g.Nodes[id].Lng) <= mid {
			reached[NodeID(id)] = 0
		}
	}

	members := []memberLoc{
		{groupID: 100, lat: float64(g.Nodes[westID].Lat), lng: float64(g.Nodes[westID].Lng)}, // reached
		{groupID: 200, lat: float64(g.Nodes[eastID].Lat), lng: float64(g.Nodes[eastID].Lng)}, // not reached
		{groupID: 300, lat: 59.0, lng: 1.0},                                                  // offshore: no node
	}

	got := freeglerReachableGroupIDs(g, reached, members, Walk)
	want := []int64{100}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("freeglerReachableGroupIDs = %v, want %v", got, want)
	}
}

// One active reachable member is enough (>=1), and a group is listed once however many
// members it has.
func TestFreeglerReachableGroupIDs_OneReachableMemberSuffices(t *testing.T) {
	g := makeLineGraph(5)
	all := Isochrone(g, float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), 1e9, Walk).ReachedNodes

	members := []memberLoc{
		{groupID: 42, lat: 59.0, lng: 1.0},                                        // offshore, unreachable
		{groupID: 42, lat: float64(g.Nodes[1].Lat), lng: float64(g.Nodes[1].Lng)}, // reachable
	}
	got := freeglerReachableGroupIDs(g, all, members, Walk)
	if !reflect.DeepEqual(got, []int64{42}) {
		t.Fatalf("freeglerReachableGroupIDs = %v, want [42]", got)
	}
}
