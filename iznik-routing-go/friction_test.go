package main

import "testing"

// makeLineGraph builds a straight west→east chain of n nodes ~100m apart,
// joined by a bidirectional residential road. Node i (0-based) has NodeID i+1.
func makeLineGraph(n int) *Graph {
	nodes := make([]RawNodeSpec, 0, n)
	ids := make([]int64, 0, n)
	for i := 0; i < n; i++ {
		nodes = append(nodes, RawNodeSpec{
			OSMID: int64(i + 1),
			Lat:   51.4500,
			Lng:   -2.6000 + float64(i)*0.0015, // ~100m per step at UK lat
		})
		ids = append(ids, int64(i+1))
	}
	ways := []RawWaySpec{{NodeIDs: ids, Highway: "residential"}}
	return BuildGraphFromRaw(nodes, ways, nil)
}

// Fallback guarantee: with NO connectivity data (Conn==0 everywhere — e.g. Scotland,
// or before the CSV is loaded), the friction isochrone must reproduce the plain one
// exactly. This is the clean-degradation contract.
func TestFrictionIsochrone_NoConnectivity_MatchesPlain(t *testing.T) {
	g := makeTestGrid(nil)
	lat, lng := 51.4545, -2.5879

	plain := Isochrone(g, lat, lng, 15*60, Walk)
	fric := FrictionIsochrone(g, lat, lng, 15*60, Walk,
		FrictionParams{Ref: 60, Traverse: 1, Min: 0.2, Max: 5})

	if len(fric.ReachedNodes) != len(plain.ReachedNodes) {
		t.Fatalf("no-connectivity friction should match plain: plain=%d friction=%d",
			len(plain.ReachedNodes), len(fric.ReachedNodes))
	}
	for id, pc := range plain.ReachedNodes {
		fc, ok := fric.ReachedNodes[id]
		if !ok {
			t.Fatalf("node %d reached by plain but not friction", id)
		}
		if fc != pc {
			t.Fatalf("node %d cost differs: plain=%f friction=%f", id, pc, fc)
		}
	}
}

// Path-integral property: a high-connectivity (high traversal-friction) node raises
// the cost to reach everything BEYOND it, while leaving nodes BEFORE it unchanged.
// This is what distinguishes path-integrated friction from a destination-only multiplier.
func TestFrictionIsochrone_HighFrictionNodeSlowsDownstreamOnly(t *testing.T) {
	g := makeLineGraph(7) // NodeIDs 1..7 west→east
	// Put a high-friction area at node 4 (the middle). Ref=50, so (100/50)^1 = 2.0×.
	g.Nodes[4].Conn = 100
	lat, lng := float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng) // origin at westmost node

	// Budget large enough that both reach the eastmost node.
	budget := float32(60 * 60)
	plain := Isochrone(g, lat, lng, budget, Walk)
	fric := FrictionIsochrone(g, lat, lng, budget, Walk,
		FrictionParams{Ref: 50, Traverse: 1, Min: 0.2, Max: 5})

	// Nodes BEFORE the friction (1,2,3) are unchanged.
	for _, id := range []NodeID{1, 2, 3} {
		if fric.ReachedNodes[id] != plain.ReachedNodes[id] {
			t.Errorf("upstream node %d should be unchanged: plain=%f friction=%f",
				id, plain.ReachedNodes[id], fric.ReachedNodes[id])
		}
	}
	// Nodes AT/BEYOND the friction (4,5,6,7) cost strictly more under friction.
	for _, id := range []NodeID{4, 5, 6, 7} {
		pc, ok1 := plain.ReachedNodes[id]
		fc, ok2 := fric.ReachedNodes[id]
		if !ok1 || !ok2 {
			t.Fatalf("node %d not reached by both (plain=%v friction=%v)", id, ok1, ok2)
		}
		if !(fc > pc) {
			t.Errorf("downstream node %d should cost more under friction: plain=%f friction=%f",
				id, pc, fc)
		}
	}
}

// Willingness asymmetry: travel tolerance rides on the COLLECTOR's home area, so reach
// is asymmetric. An urban offer reaches a rural collector (rural folk travel in), but a
// rural offer does NOT reach an urban collector (urban folk won't travel out) — at the
// same distance. Modelled as a destination-side budget multiplier that decreases with
// connectivity.
func TestFrictionIsochrone_Willingness_AsymmetricUrbanRural(t *testing.T) {
	g := makeLineGraph(6) // NodeIDs 1..6 west→east
	g.Nodes[1].Conn = 100 // urban: low willingness to travel out
	g.Nodes[6].Conn = 25  // rural: high willingness to travel in
	// middle nodes Conn=0 → willingness 1 (neutral)

	uLat, uLng := float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng)
	rLat, rLng := float64(g.Nodes[6].Lat), float64(g.Nodes[6].Lng)

	// D = plain end-to-end walk cost (symmetric both ways).
	D := Isochrone(g, uLat, uLng, 1e9, Walk).ReachedNodes[6]
	if D <= 0 {
		t.Fatal("degenerate line graph")
	}

	// Willingness only (Traverse=0). Ref=50 ⇒ willingness(urban 100)=0.5, willingness(rural 25)=2.0.
	// limit=D: from urban include rural iff D≤2D (yes); from rural include urban iff D≤0.5D (no).
	p := FrictionParams{Ref: 50, Willing: 1, WMin: 0.2, WMax: 3}

	fromUrban := FrictionIsochrone(g, uLat, uLng, D, Walk, p)
	fromRural := FrictionIsochrone(g, rLat, rLng, D, Walk, p)

	if _, ok := fromUrban.ReachedNodes[6]; !ok {
		t.Errorf("urban offer should reach rural collector (rural willing to travel in)")
	}
	if _, ok := fromRural.ReachedNodes[1]; ok {
		t.Errorf("rural offer should NOT reach urban collector (urban won't travel out)")
	}
}

// Willingness-basis flip: for a DENSE group, the "community" view (gate incomers by the
// group's own short travel norm) is TIGHTER than the "traveller" view (gate each incomer by
// its own norm, so far rural incomers who travel come in). This simulates dense-Hull residents
// treating far incomers as unexpected.
func TestCatchmentFromNodes_CommunityFlipTightensDenseGroup(t *testing.T) {
	g := makeTestGrid(nil)
	for i := NodeID(1); i < NodeID(len(g.Nodes)); i++ {
		n := g.Nodes[i]
		if mathAbs(float64(n.Lat)-51.4545) < 0.006 && mathAbs(float64(n.Lng)+2.5879) < 0.006 {
			g.Nodes[i].Conn = 95 // dense group core
		} else {
			g.Nodes[i].Conn = 30 // sparser surroundings (residents there travel further)
		}
	}
	seed := nearestNodeForMode(g, 51.4545, -2.5879, Drive)
	p := FrictionParams{Ref: 67, Traverse: 1, Min: 1, Max: 4, Willing: 1, WMin: 0.6, WMax: 1.5}

	community := CatchmentFromNodes(g, []NodeID{seed}, 95, 15*60, Drive, p, true)  // group's norm
	traveller := CatchmentFromNodes(g, []NodeID{seed}, 95, 15*60, Drive, p, false) // each incomer's norm

	if !(len(traveller.ReachedNodes) > len(community.ReachedNodes)) {
		t.Errorf("traveller catchment should be wider than the flipped community catchment: traveller=%d community=%d",
			len(traveller.ReachedNodes), len(community.ReachedNodes))
	}
}

func mathAbs(x float64) float64 {
	if x < 0 {
		return -x
	}
	return x
}

// Multi-source seeding: a catchment seeded from the whole group boundary (not just the
// centroid) must include areas reachable from ANY seed. A single-source isochrone from one
// end misses the far end within a tight budget; seeding both ends covers both.
func TestFrictionIsochroneFromNodes_MultiSourceCoversAllSeeds(t *testing.T) {
	g := makeLineGraph(7) // NodeIDs 1..7 west→east
	p := FrictionParams{Ref: 67} // Traverse=0, Willing=0 → plain isochrone behaviour
	// Budget = cost to reach node 3 from node 1 (so a single source from node 1 stops at ~3).
	budget := Isochrone(g, float64(g.Nodes[1].Lat), float64(g.Nodes[1].Lng), 1e9, Walk).ReachedNodes[3]
	if budget <= 0 {
		t.Fatal("degenerate line graph")
	}
	single := frictionIsochroneFromNodes(g, []NodeID{1}, budget, Walk, p)
	multi := frictionIsochroneFromNodes(g, []NodeID{1, 7}, budget, Walk, p)

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

// Per-group catchment: the area from which posts ripple INTO a group. A rural group (whose
// members travel far to collect) has a LARGER catchment than an urban group (whose members
// won't). Catchment uses the group's own willingness as a uniform budget.
func TestCatchmentIsochrone_RuralGroupLargerThanUrban(t *testing.T) {
	gRural := makeTestGrid(nil)
	for i := NodeID(1); i < NodeID(len(gRural.Nodes)); i++ {
		gRural.Nodes[i].Conn = 30 // rural: high willingness, big catchment
	}
	gUrban := makeTestGrid(nil)
	for i := NodeID(1); i < NodeID(len(gUrban.Nodes)); i++ {
		gUrban.Nodes[i].Conn = 95 // urban: low willingness, small catchment
	}
	p := FrictionParams{Ref: 67, Traverse: 1, Min: 1, Max: 4, Willing: 1, WMin: 0.6, WMax: 1.5}

	rural := CatchmentIsochrone(gRural, 51.4545, -2.5879, 15*60, Drive, p)
	urban := CatchmentIsochrone(gUrban, 51.4545, -2.5879, 15*60, Drive, p)

	if !(len(rural.ReachedNodes) > len(urban.ReachedNodes)) {
		t.Errorf("rural group should have a larger catchment: rural=%d urban=%d",
			len(rural.ReachedNodes), len(urban.ReachedNodes))
	}
}
