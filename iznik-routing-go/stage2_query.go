package main

// Stage 2 query: reach as a labeling of the partition.
//
// Phase 1: snap the origin (a junction, or a chain node with departure-side
// offsets walked on demand) and run exact local Dijkstras inside the seed
// regions, collecting their exits.
// Phase 2: Dijkstra over the boundary graph — per-region entry×boundary matrix
// rows as clique edges, cross chains as inter-region edges — bounded by T.
// Phase 3: label every region with a reached entry: fully-in iff
// min_e(arrival_e + ecc_e) <= T (conservative), else partial with entry
// arrivals stored.
//
// Membership is exact: arrival(junction) = min(direct-from-origin local
// arrival, min over entries(arrival_e + internalDist(entry, junction))) —
// internalDist from a lazy per-REGION (post-independent) entry→node table;
// arrival(chain node v) = min over chain ends(arrival(end) + arrival-direction
// offset), plus the origin-on-the-same-chain direct case.
//
// Convention: labels, boundary structures and OriginArr are keyed by OVERLAY
// index; only snapping and the chain table live in base-node space.

import (
	"container/heap"
	"time"
)

// RegionLabel is one region's reach state for a post.
type RegionLabel struct {
	Full     bool
	EntryArr []float32 // aligned to rm.LeafEntries(leaf); +Inf = entry unreached
}

// ReachLabels is the stored per-post reach representation.
type ReachLabels struct {
	T       float32
	Reached map[int32]*RegionLabel
	// OriginArr: exact internal arrivals within the origin's seed region(s),
	// keyed by overlay idx.
	OriginArr map[uint32]float32
	// Seeds: overlay idx -> departure cost (origin snap).
	Seeds map[uint32]float32
	// Origin chain info for the same-chain direct case (base-node space).
	originChain NodeID // the absorbed origin node, 0 if origin was a junction
	seedBase    float32
	// BoundaryDist: raw phase-2 arrivals at boundary nodes (debug/diagnostics).
	BoundaryDist map[uint32]float32
	// Phase timings for the gate measurements.
	LocalMs, BoundaryMs, LabelMs float64
}

// chainDepartOffsets walks from an absorbed chain node v to both chain ends
// following OUT-edges (departure direction), returning each end junction and
// the drive seconds v→end (-1 = that direction not drivable).
func chainDepartOffsets(g *Graph, ov *Overlay, v NodeID) (NodeID, float32, NodeID, float32) {
	var ends [2]NodeID
	var secs [2]float32
	found := 0
	for i := range g.EdgesFrom(v) {
		e := &g.Edges[g.EdgeStart[v]+int32(i)]
		if usableBits(*e) == 0 || e.Seconds[Drive] < 0 {
			continue
		}
		sum := e.Seconds[Drive]
		ok := true
		prev, cur := v, e.To
		for ov.Idx[cur] == 0 {
			var next *Edge
			for j := range g.EdgesFrom(cur) {
				e2 := &g.Edges[g.EdgeStart[cur]+int32(j)]
				if e2.To != prev {
					next = e2
					break
				}
			}
			if next == nil || next.Seconds[Drive] < 0 {
				ok = false
				break
			}
			sum += next.Seconds[Drive]
			prev, cur = cur, next.To
		}
		if !ok || ov.Idx[cur] == 0 {
			continue
		}
		if found < 2 {
			ends[found] = cur
			secs[found] = sum
			found++
		}
	}
	switch found {
	case 0:
		return 0, -1, 0, -1
	case 1:
		return ends[0], secs[0], 0, -1
	default:
		return ends[0], secs[0], ends[1], secs[1]
	}
}

// boundaryIndex precomputes lookups for the boundary Dijkstra; built once per
// artifact load and shared by all queries.
type crossRange struct{ start, count int32 }

type boundaryIndex struct {
	leafOf   map[uint32]int32 // boundary overlay idx -> leaf
	entryIdx map[uint32]int32 // boundary overlay idx -> index into leaf entries
	cross    map[uint32]crossRange
	crossTo  []uint32
	crossSec []float32
}

func buildBoundaryIndex(rm *RegionMatrices, part *Stage2Partition) *boundaryIndex {
	bi := &boundaryIndex{
		leafOf:   make(map[uint32]int32),
		entryIdx: make(map[uint32]int32),
		cross:    make(map[uint32]crossRange),
	}
	nLeaves := len(part.LeafNodes)
	for l := 0; l < nLeaves; l++ {
		for i, oi := range rm.Entries[rm.EntryOff[l]:rm.EntryOff[l+1]] {
			bi.leafOf[oi] = int32(l)
			bi.entryIdx[oi] = int32(i)
		}
		for _, oi := range rm.Exits[rm.ExitOff[l]:rm.ExitOff[l+1]] {
			bi.leafOf[oi] = int32(l)
		}
	}
	// Group cross edges by from-node.
	counts := make(map[uint32]int32)
	for _, from := range rm.CrossFrom {
		counts[from]++
	}
	bi.crossTo = make([]uint32, len(rm.CrossFrom))
	bi.crossSec = make([]float32, len(rm.CrossFrom))
	next := int32(0)
	for from, c := range counts {
		bi.cross[from] = crossRange{start: next, count: 0}
		next += c
	}
	for i, from := range rm.CrossFrom {
		cr := bi.cross[from]
		bi.crossTo[cr.start+cr.count] = rm.CrossTo[i]
		bi.crossSec[cr.start+cr.count] = rm.CrossSecs[i]
		cr.count++
		bi.cross[from] = cr
	}
	return bi
}

// Stage2Engine bundles the loaded artifacts.
type Stage2Engine struct {
	G    *Graph
	Ov   *Overlay
	Part *Stage2Partition
	RM   *RegionMatrices
	BI   *boundaryIndex

	tables *regionTableCache
}

func NewStage2Engine(g *Graph, ov *Overlay, part *Stage2Partition, rm *RegionMatrices) *Stage2Engine {
	return &Stage2Engine{
		G: g, Ov: ov, Part: part, RM: rm,
		BI:     buildBoundaryIndex(rm, part),
		tables: newRegionTableCache(64),
	}
}

// regionTableCache: lazy per-region entry→node internal distance tables.
// Post-independent, so shared across queries; LRU-capped.
type regionTableCache struct {
	cap   int
	order []int32
	m     map[int32]*regionTable
}

type regionTable struct {
	ls   *leafSubgraph
	dist [][]float32 // per entry (aligned to rm.LeafEntries), len(ls.nodes)
}

func newRegionTableCache(cap int) *regionTableCache {
	return &regionTableCache{cap: cap, m: make(map[int32]*regionTable)}
}

func (c *regionTableCache) get(e *Stage2Engine, leaf int32) *regionTable {
	if t, ok := c.m[leaf]; ok {
		return t
	}
	ls := buildLeafSubgraph(e.Ov, e.Part, leaf)
	ents := e.RM.LeafEntries(leaf)
	t := &regionTable{ls: ls, dist: make([][]float32, len(ents))}
	for i, ent := range ents {
		d := make([]float32, len(ls.nodes))
		ls.dijkstraFrom(ls.localOf[ent], d)
		t.dist[i] = d
	}
	c.m[leaf] = t
	c.order = append(c.order, leaf)
	if len(c.order) > c.cap {
		old := c.order[0]
		c.order = c.order[1:]
		delete(c.m, old)
	}
	return t
}

// QueryLabels computes the reach labeling from (lat,lng) within limitSeconds.
func (e *Stage2Engine) QueryLabels(lat, lng float64, limitSeconds float32) *ReachLabels {
	out := &ReachLabels{
		T:         limitSeconds,
		Reached:   make(map[int32]*RegionLabel),
		OriginArr: make(map[uint32]float32),
		Seeds:     make(map[uint32]float32),
	}

	origin := nearestNodeForMode(e.G, lat, lng, Drive)
	if origin == noNode {
		return out
	}
	seed := initialCostFor(Drive)
	out.seedBase = seed
	if oi := e.Ov.Idx[origin]; oi != 0 {
		out.Seeds[oi] = seed
	} else {
		out.originChain = origin
		a, sa, b, sb := chainDepartOffsets(e.G, e.Ov, origin)
		if sa >= 0 {
			out.Seeds[e.Ov.Idx[a]] = seed + sa
		}
		if sb >= 0 {
			boi := e.Ov.Idx[b]
			if cur, ok := out.Seeds[boi]; !ok || seed+sb < cur {
				out.Seeds[boi] = seed + sb
			}
		}
	}

	// Phase 1: local exact Dijkstra within each seed's region.
	t0 := time.Now()
	type exitArr struct {
		oi  uint32
		arr float32
	}
	var exitArrs []exitArr
	seededLeaves := map[int32]struct{}{}
	for oi, s := range out.Seeds {
		leaf := e.Part.LeafOf[oi]
		if leaf < 0 {
			continue
		}
		seededLeaves[leaf] = struct{}{}
		t := e.tables.get(e, leaf)
		dist := make([]float32, len(t.ls.nodes))
		t.ls.dijkstraFrom(t.ls.localOf[oi], dist)
		for i, d := range dist {
			if d == f32Inf {
				continue
			}
			arr := s + d
			if arr > limitSeconds {
				continue
			}
			node := t.ls.nodes[i]
			if cur, ok := out.OriginArr[node]; !ok || arr < cur {
				out.OriginArr[node] = arr
			}
		}
		for _, x := range e.RM.LeafBoundary(leaf) {
			if d := dist[t.ls.localOf[x]]; d != f32Inf && s+d <= limitSeconds {
				exitArrs = append(exitArrs, exitArr{x, s + d})
			}
		}
	}
	out.LocalMs = float64(time.Since(t0).Microseconds()) / 1000

	// Phase 2: boundary Dijkstra.
	t1 := time.Now()
	dist := make(map[uint32]float32)
	h := &miniHeap32{}
	push := func(oi uint32, c float32) {
		if c > limitSeconds {
			return
		}
		if cur, ok := dist[oi]; !ok || c < cur {
			dist[oi] = c
			heap.Push(h, heapItem32{oi, c})
		}
	}
	for _, xa := range exitArrs {
		push(xa.oi, xa.arr)
	}
	for oi, s := range out.Seeds {
		if _, ok := e.BI.leafOf[oi]; ok {
			push(oi, s)
		}
	}
	for h.Len() > 0 {
		cur := heap.Pop(h).(heapItem32)
		if d, ok := dist[cur.oi]; !ok || cur.c > d {
			continue
		}
		// Cross edges (this node as an exit).
		if cr, ok := e.BI.cross[cur.oi]; ok {
			for i := cr.start; i < cr.start+cr.count; i++ {
				push(e.BI.crossTo[i], cur.c+e.BI.crossSec[i])
			}
		}
		// Matrix row (this node as an entry): relaxes EVERY boundary node of
		// its leaf, sibling entries included.
		if ei, ok := e.BI.entryIdx[cur.oi]; ok {
			leaf := e.BI.leafOf[cur.oi]
			bnd := e.RM.LeafBoundary(leaf)
			nx := len(bnd)
			base := e.RM.MatOff[leaf] + int64(int(ei)*nx)
			for xi, x := range bnd {
				m := e.RM.Mat[base+int64(xi)]
				if m != f32Inf {
					push(x, cur.c+m)
				}
			}
		}
	}
	out.BoundaryMs = float64(time.Since(t1).Microseconds()) / 1000
	out.BoundaryDist = dist

	// Phase 3: labels.
	t2 := time.Now()
	for oi, arr := range dist {
		ei, isEntry := e.BI.entryIdx[oi]
		if !isEntry {
			continue
		}
		leaf := e.BI.leafOf[oi]
		lbl := out.Reached[leaf]
		if lbl == nil {
			lbl = newRegionLabel(e, leaf)
			out.Reached[leaf] = lbl
		}
		lbl.EntryArr[ei] = arr
	}
	for leaf, lbl := range out.Reached {
		ecc := e.RM.LeafEcc(leaf)
		for i, arr := range lbl.EntryArr {
			if arr != f32Inf && arr+ecc[i] <= limitSeconds {
				lbl.Full = true
				break
			}
		}
	}
	// The origin's own region(s) are reached by definition (OriginArr covers
	// the interior even when no entry was reached from outside).
	for leaf := range seededLeaves {
		if _, ok := out.Reached[leaf]; !ok {
			out.Reached[leaf] = newRegionLabel(e, leaf)
		}
	}
	out.LabelMs = float64(time.Since(t2).Microseconds()) / 1000
	return out
}

func newRegionLabel(e *Stage2Engine, leaf int32) *RegionLabel {
	ents := e.RM.LeafEntries(leaf)
	lbl := &RegionLabel{EntryArr: make([]float32, len(ents))}
	for i := range lbl.EntryArr {
		lbl.EntryArr[i] = f32Inf
	}
	return lbl
}

// junctionArrival returns the exact arrival at base junction j, +Inf if
// unreached.
func (e *Stage2Engine) junctionArrival(lbl *ReachLabels, j NodeID) float32 {
	oi := e.Ov.Idx[j]
	if oi == 0 {
		return f32Inf
	}
	best := f32Inf
	if a, ok := lbl.OriginArr[oi]; ok && a < best {
		best = a
	}
	leaf := e.Part.LeafOf[oi]
	if leaf < 0 {
		return best
	}
	rl, ok := lbl.Reached[leaf]
	if !ok {
		return best
	}
	t := e.tables.get(e, leaf)
	li, in := t.ls.localOf[oi]
	if !in {
		return best
	}
	for i, arr := range rl.EntryArr {
		if arr == f32Inf {
			continue
		}
		if d := t.dist[i][li]; d != f32Inf && arr+d < best {
			best = arr + d
		}
	}
	return best
}

// Arrival returns the exact drive arrival seconds at (lat,lng), +Inf when out
// of reach. Membership = Arrival(...) <= labels.T.
func (e *Stage2Engine) Arrival(lbl *ReachLabels, lat, lng float64) float32 {
	v := nearestNodeForMode(e.G, lat, lng, Drive)
	if v == noNode {
		return f32Inf
	}
	return e.ArrivalAtBaseNode(lbl, v)
}

// ArrivalAtBaseNode is Arrival for an already-snapped base node.
func (e *Stage2Engine) ArrivalAtBaseNode(lbl *ReachLabels, v NodeID) float32 {
	if e.Ov.Idx[v] != 0 {
		return e.junctionArrival(lbl, v)
	}
	best := f32Inf
	if a := e.Ov.ChainEndA[v]; a != 0 && e.Ov.OffFromA[v] >= 0 {
		if ja := e.junctionArrival(lbl, a); ja+e.Ov.OffFromA[v] < best {
			best = ja + e.Ov.OffFromA[v]
		}
	}
	if b := e.Ov.ChainEndB[v]; b != 0 && e.Ov.OffFromB[v] >= 0 {
		if jb := e.junctionArrival(lbl, b); jb+e.Ov.OffFromB[v] < best {
			best = jb + e.Ov.OffFromB[v]
		}
	}
	// Origin on the same chain: direct along-chain travel with no junction.
	if o := lbl.originChain; o != 0 && e.Ov.ChainEndA[o] == e.Ov.ChainEndA[v] && e.Ov.ChainEndB[o] == e.Ov.ChainEndB[v] {
		// Forward direction A→B: cumulative forward sums.
		oA, vA := e.Ov.OffFromA[o], e.Ov.OffFromA[v]
		if oA >= 0 && vA >= oA {
			if c := lbl.seedBase + (vA - oA); c < best {
				best = c
			}
		}
		// Reverse direction B→A: cumulative reverse sums.
		oB, vB := e.Ov.OffFromB[o], e.Ov.OffFromB[v]
		if oB >= 0 && vB >= oB {
			if c := lbl.seedBase + (vB - oB); c < best {
				best = c
			}
		}
	}
	return best
}

// heapItem32 / miniHeap32: tiny heap keyed by overlay idx.
type heapItem32 struct {
	oi uint32
	c  float32
}
type miniHeap32 []heapItem32

func (h miniHeap32) Len() int            { return len(h) }
func (h miniHeap32) Less(i, j int) bool  { return h[i].c < h[j].c }
func (h miniHeap32) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *miniHeap32) Push(x interface{}) { *h = append(*h, x.(heapItem32)) }
func (h *miniHeap32) Pop() interface{} {
	old := *h
	n := len(old)
	it := old[n-1]
	*h = old[:n-1]
	return it
}
