package main

// Stage 2 (connectivity-native reach) — overlay contraction.
//
// The overlay is the junction-only view of the road graph: every node that is,
// for every transport mode it carries, a pure degree-2 pass-through between the
// same two neighbours is absorbed into a chain edge. Per-mode edge seconds are
// summed along the chain — exact, because drive penalties are already folded
// into per-edge Seconds at graph build time (see BuildGraph) and there is no
// turn model. Absorbed nodes keep an exact point-lookup path via the chain
// table: arrival(v) = min(arrival(endA)+OffFromA[v], arrival(endB)+OffFromB[v]),
// each offset measured along the chain's own direction, so oneway chains stay
// oneway (-1 = unreachable from that end).
//
// The per-mode rule matters: 500k+ nodes are any-mode degree-2 but genuine
// branch points for another mode (a footpath leaving a road mid-chain). Those
// stay junctions — contraction is conservative, never lossy.
//
// No geometry is stored on chain edges: stage 2 never rasterises. Grids and
// polygons remain projections owned by the existing catchment pipeline.

import (
	"log"
	"sort"
	"time"
)

// OverlayEdge is a contracted chain (or a direct junction-junction edge) in
// the overlay graph, directed, with per-mode summed travel seconds and the
// summed great-circle hop metres (matching DistM accumulation in Isochrone).
type OverlayEdge struct {
	To      uint32 // overlay index (1-based)
	Seconds [3]float32
	Metres  float32
}

// Overlay is the junction-only contraction of a Graph, in CSR form, plus the
// chain table mapping every absorbed base node back to its two chain ends.
type Overlay struct {
	// BaseNode[oi] = base-graph NodeID for overlay index oi (1-based; [0] sentinel).
	BaseNode []NodeID
	// Idx[baseID] = overlay index (1-based), 0 if the base node is absorbed or isolated.
	Idx []uint32
	// CSR over overlay indices.
	EdgeStart []int32
	Edges     []OverlayEdge
	// Chain table, indexed by base NodeID. For absorbed nodes: the junction at
	// each end of the chain and the DRIVE seconds from that end to the node,
	// measured along the chain direction end→node; -1 if not traversable from
	// that end (oneway). Zero ChainEndA means "not an absorbed chain node"
	// (junction, isolated, or degree-0).
	ChainEndA []NodeID
	ChainEndB []NodeID
	OffFromA  []float32
	OffFromB  []float32
}

// EdgesFrom returns the overlay edges outgoing from overlay index oi.
func (ov *Overlay) EdgesFrom(oi uint32) []OverlayEdge {
	return ov.Edges[ov.EdgeStart[oi]:ov.EdgeStart[oi+1]]
}

// NodeCount returns the number of overlay (junction) nodes.
func (ov *Overlay) NodeCount() int { return len(ov.BaseNode) - 1 }

// usableBits returns a bitmask of the modes an edge is usable by.
func usableBits(e Edge) uint8 {
	var b uint8
	for m := 0; m < 3; m++ {
		if e.Seconds[m] >= 0 {
			b |= 1 << uint(m)
		}
	}
	return b
}

// nbInfo accumulates, per base node, up to three distinct neighbours with
// per-mode in/out usability bits. More than three distinct neighbours, or a
// duplicate (parallel) edge for the same mode+direction+neighbour, marks the
// node an automatic junction via the flags field.
type nbInfo struct {
	nb      [3]NodeID
	outBits [3]uint8
	inBits  [3]uint8
	n       uint8
	flags   uint8 // 1 = overflow (>3 neighbours), 2 = parallel edge
}

const (
	nbOverflow uint8 = 1
	nbParallel uint8 = 2
)

func (ni *nbInfo) add(other NodeID, bits uint8, out bool) {
	if ni.flags&nbOverflow != 0 {
		return
	}
	slot := -1
	for i := 0; i < int(ni.n); i++ {
		if ni.nb[i] == other {
			slot = i
			break
		}
	}
	if slot < 0 {
		if ni.n >= 3 {
			ni.flags |= nbOverflow
			return
		}
		slot = int(ni.n)
		ni.nb[slot] = other
		ni.n++
	}
	if out {
		if ni.outBits[slot]&bits != 0 {
			ni.flags |= nbParallel
		}
		ni.outBits[slot] |= bits
	} else {
		if ni.inBits[slot]&bits != 0 {
			ni.flags |= nbParallel
		}
		ni.inBits[slot] |= bits
	}
}

// contractible reports whether the node described by ni can be absorbed into a
// chain: for EVERY mode with any usable incident edge it must be a pure
// pass-through between the same two neighbours — either symmetric (in+out to
// both) or a consistent oneway through-pattern (in from one, out to the other).
func (ni *nbInfo) contractible() bool {
	if ni.flags != 0 || ni.n != 2 {
		return false
	}
	sawMode := false
	for m := 0; m < 3; m++ {
		bit := uint8(1) << uint(m)
		o0 := ni.outBits[0]&bit != 0
		o1 := ni.outBits[1]&bit != 0
		i0 := ni.inBits[0]&bit != 0
		i1 := ni.inBits[1]&bit != 0
		if !o0 && !o1 && !i0 && !i1 {
			continue // mode absent at this node
		}
		sawMode = true
		symmetric := o0 && o1 && i0 && i1
		onewayThrough := (i0 && o1 && !o0 && !i1) || (i1 && o0 && !o1 && !i0)
		if !symmetric && !onewayThrough {
			return false
		}
	}
	// A node with edges in no mode at all (shouldn't happen for nodes with
	// neighbours, but be safe) is not a chain node.
	return sawMode
}

// BuildOverlay contracts g into its junction-only overlay.
func BuildOverlay(g *Graph) *Overlay {
	n := g.NodeCount()
	start := time.Now()

	// ── Pass 1: per-node neighbour structure ─────────────────────────────────
	info := make([]nbInfo, n+1)
	for v := NodeID(1); v <= NodeID(n); v++ {
		for _, e := range g.EdgesFrom(v) {
			bits := usableBits(e)
			if bits == 0 {
				continue
			}
			info[v].add(e.To, bits, true)
			info[e.To].add(v, bits, false)
		}
	}

	// ── Pass 2: classify ─────────────────────────────────────────────────────
	// isChain[v] = absorbable; junction = has edges and not absorbable.
	isChain := make([]bool, n+1)
	hasEdges := make([]bool, n+1)
	for v := NodeID(1); v <= NodeID(n); v++ {
		if info[v].n == 0 && info[v].flags == 0 {
			continue // isolated
		}
		hasEdges[v] = true
		if info[v].contractible() {
			isChain[v] = true
		}
	}
	info = nil

	ov := &Overlay{
		Idx:       make([]uint32, n+1),
		ChainEndA: make([]NodeID, n+1),
		ChainEndB: make([]NodeID, n+1),
		OffFromA:  make([]float32, n+1),
		OffFromB:  make([]float32, n+1),
	}
	ov.BaseNode = append(ov.BaseNode, 0) // sentinel

	assignJunction := func(v NodeID) uint32 {
		if ov.Idx[v] != 0 {
			return ov.Idx[v]
		}
		ov.BaseNode = append(ov.BaseNode, v)
		oi := uint32(len(ov.BaseNode) - 1)
		ov.Idx[v] = oi
		return oi
	}
	for v := NodeID(1); v <= NodeID(n); v++ {
		if hasEdges[v] && !isChain[v] {
			assignJunction(v)
		}
	}

	// ── Pass 3: walk chains from every junction out-edge ─────────────────────
	type tempEdge struct {
		from, to uint32
		secs     [3]float32
		metres   float32
	}
	var tempEdges []tempEdge
	// chainNodes is reused per walk.
	var chainNodes []NodeID

	// findEdge returns the edge from→to (first match), or nil.
	findEdge := func(from, to NodeID) *Edge {
		es := g.EdgesFrom(from)
		for i := range es {
			if es[i].To == to {
				return &es[i]
			}
		}
		return nil
	}

	hopMetres := func(a, b NodeID) float32 {
		na, nb := g.Nodes[a], g.Nodes[b]
		return float32(haversineM(float64(na.Lat), float64(na.Lng), float64(nb.Lat), float64(nb.Lng)))
	}

	sumInto := func(acc *[3]float32, e *Edge) {
		for m := 0; m < 3; m++ {
			if acc[m] < 0 || e.Seconds[m] < 0 {
				acc[m] = -1
			} else {
				acc[m] += e.Seconds[m]
			}
		}
	}

	// walk follows the chain starting at junction a along edge a→first until
	// the next junction, emitting the overlay edge and (on the claiming pass)
	// the chain offsets for interior nodes.
	walk := func(a NodeID, first *Edge) {
		secs := [3]float32{}
		sumInto(&secs, first)
		metres := hopMetres(a, first.To)
		prev, cur := a, first.To
		chainNodes = chainNodes[:0]
		for isChain[cur] {
			chainNodes = append(chainNodes, cur)
			// The chain node has exactly two neighbours; step to the one that
			// is not prev. Its out-edges carry the forward direction.
			var next *Edge
			for i := range g.EdgesFrom(cur) {
				e := &g.Edges[g.EdgeStart[cur]+int32(i)]
				if e.To != prev {
					next = e
					break
				}
			}
			if next == nil {
				// Oneway chain walked against its direction cannot happen (we
				// follow out-edges), but a malformed dead-end chain node can:
				// treat cur as the far end by promoting it below via cycle
				// cleanup; drop the walk.
				return
			}
			sumInto(&secs, next)
			metres += hopMetres(cur, next.To)
			prev, cur = cur, next.To
		}
		b := cur
		tempEdges = append(tempEdges, tempEdge{from: ov.Idx[a], to: assignJunction(b), secs: secs, metres: metres})

		if len(chainNodes) == 0 {
			return
		}
		// Claiming rule: the first directed walk over a chain assigns offsets
		// for both ends; the opposite walk (if the chain is two-way) only adds
		// its overlay edge. A chain is claimed iff its first interior node has
		// ChainEndA set.
		if ov.ChainEndA[chainNodes[0]] != 0 {
			return
		}
		// Forward offsets: seconds a→v along out-edges (drive).
		fsec := float32(0)
		fok := true
		prevN := a
		for _, v := range chainNodes {
			e := findEdge(prevN, v)
			if e == nil || e.Seconds[Drive] < 0 {
				fok = false
			}
			if fok {
				fsec += e.Seconds[Drive]
			}
			ov.ChainEndA[v] = a
			ov.ChainEndB[v] = b
			if fok {
				ov.OffFromA[v] = fsec
			} else {
				ov.OffFromA[v] = -1
			}
			prevN = v
		}
		// Backward offsets: seconds b→v along the reverse direction, if it exists.
		bsec := float32(0)
		bok := true
		prevN = b
		for i := len(chainNodes) - 1; i >= 0; i-- {
			v := chainNodes[i]
			e := findEdge(prevN, v)
			if e == nil || e.Seconds[Drive] < 0 {
				bok = false
			}
			if bok {
				bsec += e.Seconds[Drive]
			}
			if bok {
				ov.OffFromB[v] = bsec
			} else {
				ov.OffFromB[v] = -1
			}
			prevN = v
		}
	}

	for oi := uint32(1); oi < uint32(len(ov.BaseNode)); oi++ {
		a := ov.BaseNode[oi]
		for i := range g.EdgesFrom(a) {
			e := &g.Edges[g.EdgeStart[a]+int32(i)]
			if usableBits(*e) == 0 {
				continue
			}
			walk(a, e)
		}
	}

	// ── Pass 4: pure chain cycles (no junction anywhere on the loop) ─────────
	// Promote one node per unclaimed cycle to junction and walk it.
	for v := NodeID(1); v <= NodeID(n); v++ {
		if isChain[v] && ov.ChainEndA[v] == 0 {
			isChain[v] = false
			assignJunction(v)
			for i := range g.EdgesFrom(v) {
				e := &g.Edges[g.EdgeStart[v]+int32(i)]
				if usableBits(*e) == 0 {
					continue
				}
				walk(v, e)
			}
		}
	}

	// ── Build overlay CSR ────────────────────────────────────────────────────
	sort.Slice(tempEdges, func(i, j int) bool { return tempEdges[i].from < tempEdges[j].from })
	on := len(ov.BaseNode) - 1
	ov.EdgeStart = make([]int32, on+2)
	ov.Edges = make([]OverlayEdge, len(tempEdges))
	pos := 0
	for oi := 1; oi <= on; oi++ {
		ov.EdgeStart[oi] = int32(pos)
		for pos < len(tempEdges) && tempEdges[pos].from == uint32(oi) {
			ov.Edges[pos] = OverlayEdge{To: tempEdges[pos].to, Seconds: tempEdges[pos].secs, Metres: tempEdges[pos].metres}
			pos++
		}
	}
	ov.EdgeStart[on+1] = int32(pos)

	log.Printf("stage2: overlay built in %v: %d junctions / %d chain edges (base %d nodes / %d edges)",
		time.Since(start).Round(time.Millisecond), on, len(ov.Edges), n, len(g.Edges))
	return ov
}
