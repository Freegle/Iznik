package main

// Reach engine (connectivity-native reach) — overlay contraction.
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
	"math"
	"sort"
	"time"
)

// OverlayEdge is a contracted chain (or a direct junction-junction edge) in
// the overlay graph, directed, with per-mode summed travel seconds and the
// summed great-circle hop metres (matching DistM accumulation in Isochrone).
// OverlayEdge is a contracted chain between two junctions.
//
// It was {To uint32; Seconds [3]float32; Metres float32} = 20 bytes. Only drive
// is served, and the reach engine's own leaf subgraph already discarded every
// non-drive overlay edge before using one, so the artifact keeps drive alone and
// drops the 10,321,375 UK overlay edges no car can use: 31,461,366 x 20B becomes
// 21,139,991 x 8B.
//
// Note the alignment trap: narrowing Metres on its own would save nothing,
// because 4 + 12 + 2 still rounds up to 20. The whole struct has to shrink
// together, and 4 + 2 + 2 is exactly 8 with no padding.
//
// Secs is drive seconds x10 (measured max 963.96s over the UK, so uint16
// deciseconds has 6.8x headroom) and Metres is whole metres (measured max
// 23,261m against a 65,535 ceiling).
type OverlayEdge struct {
	To     uint32 // overlay index (1-based)
	Secs   uint16
	Metres uint16
}

// Sec returns the contracted chain's drive seconds.
func (e OverlayEdge) Sec() float32 { return float32(e.Secs) / 10 }

// Met returns the contracted chain's road metres.
func (e OverlayEdge) Met() float32 { return float32(e.Metres) }

// maxOverlayDeciseconds and maxOverlayMetres are the quantisation ceilings. The
// build refuses anything above them rather than wrapping silently.
const (
	maxOverlayDeciseconds = 65534.0
	maxOverlayMetres      = 65535.0
)

// Overlay is the junction-only contraction of a Graph, in CSR form, plus the
// chain table mapping every absorbed base node back to its two chain ends.
type Overlay struct {
	// BaseNode[oi] = base-graph NodeID for overlay index oi (1-based; [0] sentinel).
	BaseNode []NodeID
	// Ref[baseID] packs two facts that are mutually exclusive by construction,
	// into the one array that used to be two.
	//
	//	0                      neither: isolated, or degree-0
	//	high bit clear, != 0   a junction; the value is its overlay index
	//	high bit set           an absorbed chain node; the low bits are the base
	//	                       NodeID of chain end A
	//
	// A node is a junction exactly when assignJunction gave it an overlay index,
	// and an absorbed chain node exactly when walk() claimed it, and the pass-2
	// isChain classification those two read is disjoint (pass 4 clears isChain
	// before promoting a cycle anchor). Two arrays of 56,874,452 uint32 each was
	// 227.5MB to say one thing per node. Read through IdxOf and ChainA; write
	// only through setJunction and setChainA, which assert the exclusivity so a
	// later change to the builder cannot quietly corrupt it.
	Ref []uint32
	// CSR over overlay indices.
	EdgeStart []int32
	Edges     []OverlayEdge
	// ChainEndB, OffFromA and OffFromB are indexed by base NodeID and are
	// meaningful only for absorbed chain nodes: the junction at the far end of
	// the chain, and the DRIVE seconds from each end to the node measured along
	// the chain direction end->node (offUnusable if that direction is oneway
	// against you).
	ChainEndB []NodeID
	OffFromA  []uint16
	OffFromB  []uint16
}

// refAbsorbed is the high bit marking an absorbed chain node in Ref. Both
// payloads fit under it: the largest overlay index is the junction count
// (12,927,438 on the UK) and the largest base NodeID is the node count
// (56,874,437), each far below 2^31.
const refAbsorbed uint32 = 1 << 31

// IdxOf returns the overlay index of a base node, or 0 when it is not a junction.
func (ov *Overlay) IdxOf(v NodeID) uint32 {
	r := ov.Ref[v]
	if r&refAbsorbed != 0 {
		return 0
	}
	return r
}

// ChainA returns the base NodeID of chain end A for an absorbed node, or 0 when
// the node is not an absorbed chain node.
func (ov *Overlay) ChainA(v NodeID) NodeID {
	r := ov.Ref[v]
	if r&refAbsorbed == 0 {
		return 0
	}
	return r &^ refAbsorbed
}

// setJunction records a base node as a junction with the given overlay index.
func (ov *Overlay) setJunction(v NodeID, oi uint32) {
	if ov.Ref[v]&refAbsorbed != 0 {
		log.Fatalf("reach: node %d is both a junction and an absorbed chain node", v)
	}
	ov.Ref[v] = oi
}

// setChainA records a base node as absorbed, with a as its chain end A.
func (ov *Overlay) setChainA(v, a NodeID) {
	if r := ov.Ref[v]; r != 0 && r&refAbsorbed == 0 {
		log.Fatalf("reach: node %d is both a junction and an absorbed chain node", v)
	}
	ov.Ref[v] = uint32(a) | refAbsorbed
}

// offUnusable marks a chain direction that cannot be driven (the old -1).
const offUnusable uint16 = 65535

// maxOffDeciseconds is the quantisation ceiling for a chain offset. Measured
// max over the UK graph is 961.20s, so this has 6.8x headroom.
const maxOffDeciseconds = 65534.0

// OffA returns the drive seconds from chain end A to absorbed node v, and
// whether that direction is drivable at all.
func (ov *Overlay) OffA(v NodeID) (float32, bool) {
	o := ov.OffFromA[v]
	if o == offUnusable {
		return 0, false
	}
	return float32(o) / 10, true
}

// OffB is OffA for the other chain end.
func (ov *Overlay) OffB(v NodeID) (float32, bool) {
	o := ov.OffFromB[v]
	if o == offUnusable {
		return 0, false
	}
	return float32(o) / 10, true
}

// quantOff encodes a chain offset in deciseconds, or offUnusable for a
// direction that is not drivable.
func quantOff(secs float32, ok bool, from, to NodeID) uint16 {
	if !ok || secs < 0 {
		return offUnusable
	}
	d := math.Round(float64(secs) * 10)
	if d > maxOffDeciseconds {
		log.Fatalf("reach: chain offset %d->%d is %.1fs, over the %.1fs quantisation ceiling",
			from, to, secs, maxOffDeciseconds/10)
	}
	return uint16(d)
}

// EdgesFrom returns the overlay edges outgoing from overlay index oi.
func (ov *Overlay) EdgesFrom(oi uint32) []OverlayEdge {
	return ov.Edges[ov.EdgeStart[oi]:ov.EdgeStart[oi+1]]
}

// NodeCount returns the number of overlay (junction) nodes.
func (ov *Overlay) NodeCount() int { return len(ov.BaseNode) - 1 }

// usableBits returns a bitmask of the modes an edge is usable by. It runs on
// the build-time three-mode edges: which modes can traverse an edge is what
// decides whether a node is a junction or a chain interior, so contracting on
// drive alone would reshape the overlay and change the partition fingerprint.
func usableBits(e ModalEdge) uint8 {
	var b uint8
	for m := 0; m < 3; m++ {
		if e.Seconds[m] >= 0 {
			b |= 1 << uint(m)
		}
	}
	return b
}

// nbInfo accumulates, per base node, up to three distinct neighbours with
// per-mode in/out usability bits. More than three distinct neighbours, or ANY
// second edge to the same neighbour in the same direction (parallel edges —
// even with disjoint mode sets, e.g. a road plus a separately-mapped footway
// between the same two nodes), marks the node an automatic junction: the
// chain walk can only follow one of the parallels, and following the wrong
// one silently drops the other's modes from the contracted edge (found as a
// missing drivable edge in the Southend parity divergence).
type nbInfo struct {
	nb      [3]NodeID
	outBits [3]uint8
	inBits  [3]uint8
	outCnt  [3]uint8
	inCnt   [3]uint8
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
		if ni.outCnt[slot] > 0 {
			ni.flags |= nbParallel
		}
		ni.outCnt[slot]++
		ni.outBits[slot] |= bits
	} else {
		if ni.inCnt[slot] > 0 {
			ni.flags |= nbParallel
		}
		ni.inCnt[slot]++
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
		for _, e := range g.ModalEdgesFrom(v) {
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
		Ref:       make([]uint32, n+1),
		ChainEndB: make([]NodeID, n+1),
		OffFromA:  make([]uint16, n+1),
		OffFromB:  make([]uint16, n+1),
	}
	ov.BaseNode = append(ov.BaseNode, 0) // sentinel

	assignJunction := func(v NodeID) uint32 {
		if oi := ov.IdxOf(v); oi != 0 {
			return oi
		}
		ov.BaseNode = append(ov.BaseNode, v)
		oi := uint32(len(ov.BaseNode) - 1)
		ov.setJunction(v, oi)
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
		secs     float32
		metres   float32
	}
	var tempEdges []tempEdge
	// chainNodes is reused per walk.
	var chainNodes []NodeID

	// findEdge returns the edge from→to (first match), or nil.
	findEdge := func(from, to NodeID) *ModalEdge {
		es := g.ModalEdgesFrom(from)
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

	// Accumulate the QUANTISED drive time, so a contracted chain equals what a
	// flat edge-by-edge search over the served graph adds up to. Walk and cycle
	// are not summed any more: nothing stores or serves them, and their
	// usability (which is what shapes the overlay) comes from usableBits on the
	// individual edges, not from these sums.
	sumInto := func(acc *float32, e *ModalEdge) {
		q := e.DriveQuant()
		if *acc < 0 || q < 0 {
			*acc = -1
		} else {
			*acc += q
		}
	}

	// walk follows the chain starting at junction a along edge a→first until
	// the next junction, emitting the overlay edge and (on the claiming pass)
	// the chain offsets for interior nodes.
	walk := func(a NodeID, first *ModalEdge) {
		secs := float32(0)
		sumInto(&secs, first)
		metres := hopMetres(a, first.To)
		prev, cur := a, first.To
		chainNodes = chainNodes[:0]
		for isChain[cur] {
			chainNodes = append(chainNodes, cur)
			// The chain node has exactly two neighbours; step to the one that
			// is not prev. Its out-edges carry the forward direction.
			var next *ModalEdge
			for i := range g.ModalEdgesFrom(cur) {
				e := &g.ModalEdges[g.ModalEdgeStart[cur]+int32(i)]
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
		tempEdges = append(tempEdges, tempEdge{from: ov.IdxOf(a), to: assignJunction(b), secs: secs, metres: metres})

		if len(chainNodes) == 0 {
			return
		}
		// Claiming rule: the first directed walk over a chain assigns offsets
		// for both ends; the opposite walk (if the chain is two-way) only adds
		// its overlay edge. A chain is claimed iff its first interior node has
		// ChainEndA set.
		if ov.ChainA(chainNodes[0]) != 0 {
			return
		}
		// Forward offsets: seconds a→v along out-edges (drive).
		fsec := float32(0)
		fok := true
		prevN := a
		for _, v := range chainNodes {
			e := findEdge(prevN, v)
			if e == nil || e.DriveQuant() < 0 {
				fok = false
			}
			if fok {
				fsec += e.DriveQuant()
			}
			ov.setChainA(v, a)
			ov.ChainEndB[v] = b
			ov.OffFromA[v] = quantOff(fsec, fok, a, v)
			prevN = v
		}
		// Backward offsets: seconds b→v along the reverse direction, if it exists.
		bsec := float32(0)
		bok := true
		prevN = b
		for i := len(chainNodes) - 1; i >= 0; i-- {
			v := chainNodes[i]
			e := findEdge(prevN, v)
			if e == nil || e.DriveQuant() < 0 {
				bok = false
			}
			if bok {
				bsec += e.DriveQuant()
			}
			ov.OffFromB[v] = quantOff(bsec, bok, b, v)
			prevN = v
		}
	}

	for oi := uint32(1); oi < uint32(len(ov.BaseNode)); oi++ {
		a := ov.BaseNode[oi]
		for i := range g.ModalEdgesFrom(a) {
			e := &g.ModalEdges[g.ModalEdgeStart[a]+int32(i)]
			if usableBits(*e) == 0 {
				continue
			}
			walk(a, e)
		}
	}

	// ── Pass 4: pure chain cycles (no junction anywhere on the loop) ─────────
	// Promote one node per unclaimed cycle to junction and walk it.
	for v := NodeID(1); v <= NodeID(n); v++ {
		if isChain[v] && ov.ChainA(v) == 0 {
			isChain[v] = false
			assignJunction(v)
			for i := range g.ModalEdgesFrom(v) {
				e := &g.ModalEdges[g.ModalEdgeStart[v]+int32(i)]
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
	// Keep only the drive-usable chains, quantised. Every consumer of ov.Edges
	// already skipped the rest (buildLeafSubgraph, BuildRegionMatrices and the
	// partition builder all tested Seconds[Drive] < 0 first), so dropping them
	// here removes work as well as bytes.
	kept := 0
	for _, te := range tempEdges {
		if te.secs >= 0 {
			kept++
		}
	}
	ov.Edges = make([]OverlayEdge, 0, kept)
	pos := 0
	for oi := 1; oi <= on; oi++ {
		ov.EdgeStart[oi] = int32(len(ov.Edges))
		for pos < len(tempEdges) && tempEdges[pos].from == uint32(oi) {
			te := tempEdges[pos]
			pos++
			if te.secs < 0 {
				continue
			}
			d := math.Round(float64(te.secs) * 10)
			if d > maxOverlayDeciseconds {
				log.Fatalf("reach: overlay chain %d->%d drive time %.1fs exceeds the %.1fs quantisation ceiling",
					oi, te.to, te.secs, maxOverlayDeciseconds/10)
			}
			m := math.Round(float64(te.metres))
			if m > maxOverlayMetres {
				log.Fatalf("reach: overlay chain %d->%d is %.0fm, over the %.0fm quantisation ceiling",
					oi, te.to, te.metres, maxOverlayMetres)
			}
			if m < 0 {
				m = 0
			}
			ov.Edges = append(ov.Edges, OverlayEdge{To: te.to, Secs: uint16(d), Metres: uint16(m)})
		}
	}
	ov.EdgeStart[on+1] = int32(len(ov.Edges))

	log.Printf("reach: overlay built in %v: %d junctions / %d chain edges (base %d nodes / %d edges)",
		time.Since(start).Round(time.Millisecond), on, len(ov.Edges), n, len(g.Edges))
	return ov
}
