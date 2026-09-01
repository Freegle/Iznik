package main

import (
	"math"
	"testing"
)

// lineNodes builds a straight west→east run of n nodes ~70m apart near Bristol.
func lineNodes(n int, osmBase int64) []RawNodeSpec {
	out := make([]RawNodeSpec, n)
	for i := 0; i < n; i++ {
		out[i] = RawNodeSpec{OSMID: osmBase + int64(i), Lat: 51.45, Lng: -2.60 + float64(i)*0.001}
	}
	return out
}

func osmIDs(specs []RawNodeSpec) []int64 {
	ids := make([]int64, len(specs))
	for i, s := range specs {
		ids[i] = s.OSMID
	}
	return ids
}

// overlayIdxOf fails the test unless base node (by build order, 1-based) is a junction.
func overlayIdxOf(t *testing.T, ov *Overlay, base NodeID) uint32 {
	t.Helper()
	oi := ov.IdxOf(base)
	if oi == 0 {
		t.Fatalf("base node %d expected to be an overlay junction, is absorbed", base)
	}
	return oi
}

// findOverlayEdge returns the overlay edge from→to, failing if absent.
func findOverlayEdge(t *testing.T, ov *Overlay, from, to uint32) OverlayEdge {
	t.Helper()
	for _, e := range ov.EdgesFrom(from) {
		if e.To == to {
			return e
		}
	}
	t.Fatalf("no overlay edge %d→%d", from, to)
	return OverlayEdge{}
}

// baseDriveSecs sums drive seconds along consecutive base nodes, following
// out-edges (fails if a hop is missing or not drivable).
func baseDriveSecs(t *testing.T, g *Graph, path []NodeID) float32 {
	t.Helper()
	var sum float32
	for i := 0; i+1 < len(path); i++ {
		found := false
		for _, e := range g.EdgesFrom(path[i]) {
			if e.To == path[i+1] {
				if e.Sec() < 0 {
					t.Fatalf("hop %d→%d not drivable", path[i], path[i+1])
				}
				sum += e.Sec()
				found = true
				break
			}
		}
		if !found {
			t.Fatalf("no base edge %d→%d", path[i], path[i+1])
		}
	}
	return sum
}

func TestOverlayContractsTwoWayChain(t *testing.T) {
	// A(1)-B(2)-C(3)-D(4) residential two-way; T-spur at A and D so both ends
	// are genuine junctions (degree 3).
	nodes := lineNodes(4, 100)
	nodes = append(nodes,
		RawNodeSpec{OSMID: 200, Lat: 51.451, Lng: -2.60},  // spur off A
		RawNodeSpec{OSMID: 210, Lat: 51.449, Lng: -2.60},  // second spur off A (degree 3)
		RawNodeSpec{OSMID: 201, Lat: 51.451, Lng: -2.597}, // spur off D
		RawNodeSpec{OSMID: 211, Lat: 51.449, Lng: -2.597}, // second spur off D (degree 3)
	)
	ways := []RawWaySpec{
		{NodeIDs: osmIDs(nodes[:4]), Highway: "residential"},
		{NodeIDs: []int64{100, 200}, Highway: "residential"},
		{NodeIDs: []int64{100, 210}, Highway: "residential"},
		{NodeIDs: []int64{103, 201}, Highway: "residential"},
		{NodeIDs: []int64{103, 211}, Highway: "residential"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	ov := BuildOverlay(g)

	a, d := NodeID(1), NodeID(4)
	b, c := NodeID(2), NodeID(3)
	oa, od := overlayIdxOf(t, ov, a), overlayIdxOf(t, ov, d)
	if ov.IdxOf(b) != 0 || ov.IdxOf(c) != 0 {
		t.Fatalf("interior nodes B/C should be absorbed, got Idx %d/%d", ov.IdxOf(b), ov.IdxOf(c))
	}

	fwd := findOverlayEdge(t, ov, oa, od)
	wantFwd := baseDriveSecs(t, g, []NodeID{a, b, c, d})
	if math.Abs(float64(fwd.Sec()-wantFwd)) > 1e-4 {
		t.Fatalf("A→D drive secs = %v, want %v", fwd.Sec(), wantFwd)
	}
	bwd := findOverlayEdge(t, ov, od, oa)
	wantBwd := baseDriveSecs(t, g, []NodeID{d, c, b, a})
	if math.Abs(float64(bwd.Sec()-wantBwd)) > 1e-4 {
		t.Fatalf("D→A drive secs = %v, want %v", bwd.Sec(), wantBwd)
	}

	// Chain offsets: arrival at C from A side and from D side.
	if ov.ChainA(c) == 0 {
		t.Fatal("C has no chain entry")
	}
	// Ends may be recorded either way round depending on which walk claimed.
	endA, endB := ov.ChainA(c), ov.ChainEndB[c]
	offA, okA := ov.OffA(c)
	offB, okB := ov.OffB(c)
	if !okA || !okB {
		t.Fatalf("chain offsets for C not drivable both ways: A ok=%v, B ok=%v", okA, okB)
	}
	if endA == d { // normalise so endA==a
		endA, endB = endB, endA
		offA, offB = offB, offA
	}
	if endA != a || endB != d {
		t.Fatalf("chain ends for C = %d/%d, want %d/%d", endA, endB, a, d)
	}
	wantOffA := baseDriveSecs(t, g, []NodeID{a, b, c})
	wantOffB := baseDriveSecs(t, g, []NodeID{d, c})
	// Offsets and the expected sums are both quantised to deciseconds, so the
	// tolerance is the quantisation bound over the chain, not float noise.
	const offTol = 0.15
	if math.Abs(float64(offA-wantOffA)) > offTol || math.Abs(float64(offB-wantOffB)) > offTol {
		t.Fatalf("C offsets = %v/%v, want %v/%v", offA, offB, wantOffA, wantOffB)
	}
}

func TestOverlayOnewayChain(t *testing.T) {
	// A(1)→B(2)→C(3)→D(4) oneway; spurs keep A and D junctions.
	nodes := lineNodes(4, 100)
	nodes = append(nodes,
		RawNodeSpec{OSMID: 200, Lat: 51.451, Lng: -2.60},
		RawNodeSpec{OSMID: 201, Lat: 51.451, Lng: -2.597},
	)
	ways := []RawWaySpec{
		{NodeIDs: osmIDs(nodes[:4]), Highway: "residential", Oneway: true},
		{NodeIDs: []int64{100, 200}, Highway: "residential"},
		{NodeIDs: []int64{103, 201}, Highway: "residential"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	ov := BuildOverlay(g)

	a, d := NodeID(1), NodeID(4)
	oa, od := overlayIdxOf(t, ov, a), overlayIdxOf(t, ov, d)
	if ov.IdxOf(2) != 0 || ov.IdxOf(3) != 0 {
		t.Fatalf("oneway interior nodes should be absorbed")
	}
	findOverlayEdge(t, ov, oa, od)
	for _, e := range ov.EdgesFrom(od) {
		if e.To == oa {
			t.Fatal("oneway chain must not produce a reverse overlay edge")
		}
	}
	// Offsets: reachable from A side only.
	c := NodeID(3)
	endA := ov.ChainA(c)
	offA, okA := ov.OffA(c)
	_, okB := ov.OffB(c)
	if endA != a {
		// The claiming walk always runs with the traversable direction on a
		// pure oneway chain, so endA must be the upstream junction.
		t.Fatalf("oneway chain endA = %d, want %d", endA, a)
	}
	wantOffA := baseDriveSecs(t, g, []NodeID{a, 2, c})
	if !okA {
		t.Fatal("oneway offA should be drivable from upstream")
	}
	if math.Abs(float64(offA-wantOffA)) > 0.15 {
		t.Fatalf("oneway offA = %v, want %v", offA, wantOffA)
	}
	if okB {
		t.Fatal("oneway offB should be marked not drivable from downstream")
	}
}

func TestOverlayPerModeBranchPointStaysJunction(t *testing.T) {
	// Road A(1)-B(2)-C(3); footway leaves from B. B is drive-degree-2 but
	// walk-degree-3: it must stay a junction.
	nodes := lineNodes(3, 100)
	nodes = append(nodes, RawNodeSpec{OSMID: 300, Lat: 51.4505, Lng: -2.599})
	ways := []RawWaySpec{
		{NodeIDs: []int64{100, 101, 102}, Highway: "residential"},
		{NodeIDs: []int64{101, 300}, Highway: "footway"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	ov := BuildOverlay(g)
	if ov.IdxOf(2) == 0 {
		t.Fatal("per-mode branch point was wrongly absorbed")
	}
}

func TestOverlayTJunctionStays(t *testing.T) {
	nodes := lineNodes(3, 100)
	nodes = append(nodes, RawNodeSpec{OSMID: 300, Lat: 51.4505, Lng: -2.599})
	ways := []RawWaySpec{
		{NodeIDs: []int64{100, 101, 102}, Highway: "residential"},
		{NodeIDs: []int64{101, 300}, Highway: "residential"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	ov := BuildOverlay(g)
	if ov.IdxOf(2) == 0 {
		t.Fatal("T-junction was wrongly absorbed")
	}
}

func TestOverlayPureCycleGetsAnchor(t *testing.T) {
	// A disconnected pure two-way cycle of degree-2 nodes: one node must be
	// promoted so the loop exists in the overlay.
	n := 6
	nodes := make([]RawNodeSpec, n)
	for i := 0; i < n; i++ {
		ang := 2 * math.Pi * float64(i) / float64(n)
		nodes[i] = RawNodeSpec{OSMID: int64(500 + i), Lat: 51.40 + 0.001*math.Sin(ang), Lng: -2.55 + 0.001*math.Cos(ang)}
	}
	ids := osmIDs(nodes)
	ids = append(ids, ids[0]) // close the loop
	g := BuildGraphFromRaw(nodes, []RawWaySpec{{NodeIDs: ids, Highway: "residential"}}, nil)
	ov := BuildOverlay(g)
	if ov.NodeCount() == 0 {
		t.Fatal("pure cycle produced an empty overlay")
	}
	// The promoted anchor should have a self-loop edge (or a pair of edges)
	// covering the cycle.
	anchor := uint32(0)
	for oi := uint32(1); oi <= uint32(ov.NodeCount()); oi++ {
		if len(ov.EdgesFrom(oi)) > 0 {
			anchor = oi
			break
		}
	}
	if anchor == 0 {
		t.Fatal("cycle anchor has no overlay edges")
	}
}

// TestOverlayDijkstraMatchesBase runs Dijkstra on the base graph and on the
// overlay from the same junction and requires identical drive arrival times at
// every junction, and offset-reconstructed arrivals to match at absorbed nodes.
func TestOverlayDijkstraMatchesBase(t *testing.T) {
	g := makeTestGrid(nil)
	// Add nothing: grid nodes are all 4-degree junctions except edges/corners;
	// use bristol pbf instead for a chain-rich graph if available.
	ov := BuildOverlay(g)
	// 2000s so the whole grid (including the absorbed corner nodes) is inside
	// the budget once per-junction drive penalties stack up.
	compareOverlayVsBase(t, g, ov, 51.4545, -2.5879, 2000)
}

func TestOverlayDijkstraMatchesBaseBristol(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)
	ov := BuildOverlay(g)
	compareOverlayVsBase(t, g, ov, 51.4545, -2.5879, 900)
}

// overlayDriveDijkstra is a simple map-based Dijkstra over the overlay used by
// tests and the prototype CLI (not performance-critical).
func overlayDriveDijkstra(ov *Overlay, srcOi uint32, seed float32, limit float32) map[uint32]float32 {
	dist := map[uint32]float32{srcOi: seed}
	type qi struct {
		oi uint32
		c  float32
	}
	// Tiny binary heap.
	h := []qi{{srcOi, seed}}
	push := func(x qi) {
		h = append(h, x)
		i := len(h) - 1
		for i > 0 {
			p := (i - 1) / 2
			if h[p].c <= h[i].c {
				break
			}
			h[p], h[i] = h[i], h[p]
			i = p
		}
	}
	pop := func() qi {
		top := h[0]
		last := len(h) - 1
		h[0] = h[last]
		h = h[:last]
		i := 0
		for {
			l, r := 2*i+1, 2*i+2
			s := i
			if l < len(h) && h[l].c < h[s].c {
				s = l
			}
			if r < len(h) && h[r].c < h[s].c {
				s = r
			}
			if s == i {
				break
			}
			h[i], h[s] = h[s], h[i]
			i = s
		}
		return top
	}
	for len(h) > 0 {
		cur := pop()
		if d, ok := dist[cur.oi]; ok && cur.c > d {
			continue
		}
		for _, e := range ov.EdgesFrom(cur.oi) {
			nc := cur.c + e.Sec()
			if nc > limit {
				continue
			}
			if d, ok := dist[e.To]; !ok || nc < d {
				dist[e.To] = nc
				push(qi{e.To, nc})
			}
		}
	}
	return dist
}

// boundaryTieSecs is the float32 association noise between adding a path up
// edge by edge and adding it up chain by chain. Measured worst case over the
// whole Bristol junction set is 0.00055s; a millisecond is a safe bound.
const boundaryTieSecs = 0.001

func compareOverlayVsBase(t *testing.T, g *Graph, ov *Overlay, lat, lng float64, limit float32) {
	t.Helper()
	origin := nearestDriveNode(g, lat, lng)
	if origin == noNode {
		t.Fatal("no drive origin")
	}
	if ov.IdxOf(origin) == 0 {
		t.Fatalf("origin %d is not a junction; test expects a junction origin", origin)
	}

	// Base ground truth WITHOUT the haversine prune: plain bounded Dijkstra.
	base := baseDriveDijkstra(g, origin, driveStartupSecs, limit)
	over := overlayDriveDijkstra(ov, ov.IdxOf(origin), driveStartupSecs, limit)

	// 1. Every junction the base reached must match exactly (within float noise).
	checkedJ := 0
	for id, want := range base {
		oi := ov.IdxOf(id)
		if oi == 0 {
			continue
		}
		got, ok := over[oi]
		if !ok {
			// A node sitting within float noise of the limit may legitimately
			// fall either side of it: the base search adds the path up edge by
			// edge and the overlay adds it up chain by chain, and float32
			// addition is not associative. Measured over 17,514 Bristol
			// junctions the two orders differ by at most 0.00055s, so anything
			// inside a millisecond of the cut is a tie, not a disagreement.
			if float64(want) > float64(limit)-boundaryTieSecs {
				continue
			}
			t.Fatalf("junction base=%d reached by base Dijkstra (%.2fs) but not by overlay", id, want)
		}
		if math.Abs(float64(got-want)) > boundaryTieSecs {
			t.Fatalf("junction base=%d arrival mismatch: overlay %.4f vs base %.4f", id, got, want)
		}
		checkedJ++
	}
	// 2. Absorbed nodes: offset reconstruction must match base arrival.
	checkedC := 0
	for id, want := range base {
		if ov.IdxOf(id) != 0 || ov.ChainA(id) == 0 {
			continue
		}
		got := float32(math.Inf(1))
		if a := ov.ChainA(id); a != 0 {
			if off, okOff := ov.OffA(id); okOff {
				if av, ok := over[ov.IdxOf(a)]; ok && av+off < got {
					got = av + off
				}
			}
		}
		if b := ov.ChainEndB[id]; b != 0 {
			if off, okOff := ov.OffB(id); okOff {
				if bv, ok := over[ov.IdxOf(b)]; ok && bv+off < got {
					got = bv + off
				}
			}
		}
		// The reconstruction may exceed limit even though the base arrival is
		// within it only if the chain-end arrival itself was pruned by the
		// limit; shortest paths to interior nodes always pass a chain end
		// first, so the end must have been reached at a smaller cost.
		if math.Abs(float64(got-want)) > 1e-2 {
			t.Fatalf("absorbed node base=%d arrival mismatch: reconstructed %.4f vs base %.4f (ends %d/%d off %.3f/%.3f)",
				id, got, want, ov.ChainA(id), ov.ChainEndB[id], offOf(ov.OffFromA[id]), offOf(ov.OffFromB[id]))
		}
		checkedC++
	}
	if checkedJ == 0 || checkedC == 0 {
		t.Fatalf("degenerate comparison: %d junctions, %d absorbed nodes checked", checkedJ, checkedC)
	}
	t.Logf("overlay-vs-base OK: %d junctions, %d absorbed nodes (overlay %d/%d nodes of base)",
		checkedJ, checkedC, ov.NodeCount(), g.NodeCount())
}

func TestOverlayModeDisjointParallelStaysJunction(t *testing.T) {
	// A(1)-B(2)-C(3) road, PLUS a footway directly between B and C: B and C
	// have parallel edges with disjoint mode sets. Both must stay junctions —
	// contracting them would let the chain walk follow the footway and
	// silently drop the road's drive seconds (the Southend parity bug).
	nodes := lineNodes(3, 100)
	nodes = append(nodes, RawNodeSpec{OSMID: 300, Lat: 51.451, Lng: -2.60})
	ways := []RawWaySpec{
		{NodeIDs: []int64{100, 101, 102}, Highway: "residential"},
		{NodeIDs: []int64{101, 102}, Highway: "footway"},
		{NodeIDs: []int64{100, 300}, Highway: "residential"},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	ov := BuildOverlay(g)
	if ov.IdxOf(2) == 0 || ov.IdxOf(3) == 0 {
		t.Fatalf("mode-disjoint parallel endpoints must stay junctions (Idx B=%d C=%d)", ov.IdxOf(2), ov.IdxOf(3))
	}
	// The overlay must retain a DRIVABLE B->C edge.
	found := false
	for _, e := range ov.EdgesFrom(ov.IdxOf(2)) {
		if e.To == ov.IdxOf(3) && e.Sec() >= 0 {
			found = true
		}
	}
	if !found {
		t.Fatal("drivable B->C overlay edge lost")
	}
}
