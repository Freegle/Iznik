package main

// Reach engine query: reach as a labeling of the partition.
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
	"sync"
	"sync/atomic"
	"time"
)

// RegionLabel is one region's reach state for a post.
type RegionLabel struct {
	Full     bool
	EntryArr []float32 // aligned to rm.LeafEntries(leaf); +Inf = entry unreached
	EntryMet []float32 // road metres for EntryArr (live queries only; nil when decoded)
}

// ReachLabels is the stored per-post reach representation. Live queries also
// carry road METRES along every time-optimal path (DistM semantics) so
// consumers can show road distance; the stored FRL2 form keeps seconds only
// (membership needs nothing else) and decoded labels leave the metre maps nil.
type ReachLabels struct {
	T       float32
	Reached map[int32]*RegionLabel
	// OriginArr: exact internal arrivals within the origin's seed region(s),
	// keyed by overlay idx.
	OriginArr map[uint32]float32
	// OriginMet: road metres for OriginArr's arrivals (live queries only).
	OriginMet map[uint32]float32
	// Seeds: overlay idx -> departure cost (origin snap).
	Seeds map[uint32]float32
	// SeedMet: road metres of the departure walks (live queries only).
	SeedMet map[uint32]float32
	// Origin chain info for the same-chain direct case (base-node space).
	originChain NodeID // the absorbed origin node, 0 if origin was a junction
	seedBase    float32
	// BoundaryDist: raw phase-2 arrivals at boundary nodes (debug/diagnostics).
	BoundaryDist map[uint32]float32
	// Phase timings for the gate measurements.
	LocalMs, BoundaryMs, LabelMs float64
}

// chainDepartOffsets walks from an absorbed chain node v to both chain ends
// following OUT-edges (departure direction), returning each end junction, the
// drive seconds v→end (-1 = that direction not drivable) and the road metres
// of the walk.
func chainDepartOffsets(g *Graph, ov *Overlay, v NodeID) (NodeID, float32, float32, NodeID, float32, float32) {
	var ends [2]NodeID
	var secs [2]float32
	var mets [2]float32
	found := 0
	hop := func(a, b NodeID) float32 {
		na, nb := g.Nodes[a], g.Nodes[b]
		return float32(haversineM(float64(na.Lat), float64(na.Lng), float64(nb.Lat), float64(nb.Lng)))
	}
	for i := range g.EdgesFrom(v) {
		e := &g.Edges[g.EdgeStart[v]+int32(i)]
		sum := e.Sec()
		msum := hop(v, e.To)
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
			if next == nil {
				ok = false
				break
			}
			sum += next.Sec()
			msum += hop(cur, next.To)
			prev, cur = cur, next.To
		}
		if !ok || ov.Idx[cur] == 0 {
			continue
		}
		if found < 2 {
			ends[found] = cur
			secs[found] = sum
			mets[found] = msum
			found++
		}
	}
	switch found {
	case 0:
		return 0, -1, -1, 0, -1, -1
	case 1:
		return ends[0], secs[0], mets[0], 0, -1, -1
	default:
		return ends[0], secs[0], mets[0], ends[1], secs[1], mets[1]
	}
}

// chainMetresFromEnd walks from an end junction INTO the chain along its
// out-edges (the arrival direction — valid on oneway chains, where the
// absorbed node has no out-edge back toward the entry end), returning the
// road metres from the end to absorbed node v (-1 if no walk reaches v).
func chainMetresFromEnd(g *Graph, ov *Overlay, end, v NodeID) float32 {
	hop := func(a, b NodeID) float32 {
		na, nb := g.Nodes[a], g.Nodes[b]
		return float32(haversineM(float64(na.Lat), float64(na.Lng), float64(nb.Lat), float64(nb.Lng)))
	}
	for i := range g.EdgesFrom(end) {
		e := &g.Edges[g.EdgeStart[end]+int32(i)]
		if ov.Idx[e.To] != 0 {
			continue // direct junction-junction edge: not a chain walk
		}
		msum := hop(end, e.To)
		prev, cur := end, e.To
		for ov.Idx[cur] == 0 {
			if cur == v {
				return msum
			}
			var next *Edge
			for j := range g.EdgesFrom(cur) {
				e2 := &g.Edges[g.EdgeStart[cur]+int32(j)]
				if e2.To != prev {
					next = e2
					break
				}
			}
			if next == nil {
				break
			}
			msum += hop(cur, next.To)
			prev, cur = cur, next.To
		}
	}
	return -1
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
	crossMet []float32
}

func buildBoundaryIndex(rm *RegionMatrices, part *ReachPartition) *boundaryIndex {
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
	bi.crossMet = make([]float32, len(rm.CrossFrom))
	next := int32(0)
	for from, c := range counts {
		bi.cross[from] = crossRange{start: next, count: 0}
		next += c
	}
	for i, from := range rm.CrossFrom {
		cr := bi.cross[from]
		bi.crossTo[cr.start+cr.count] = rm.CrossTo[i]
		bi.crossSec[cr.start+cr.count] = rm.CrossSecs[i]
		if rm.CrossMet != nil {
			bi.crossMet[cr.start+cr.count] = rm.CrossMet[i]
		}
		cr.count++
		bi.cross[from] = cr
	}
	return bi
}

// ReachEngine bundles the loaded artifacts.
type ReachEngine struct {
	G    *Graph
	Ov   *Overlay
	Part *ReachPartition
	RM   *RegionMatrices
	BI   *boundaryIndex

	tables *regionTableCache

	// leafTabs, when set, is the precomputed leaf-tables artifact
	// (leaftables.snap, mmap'd): every leaf's entry→node dist/met table served
	// straight from page cache instead of built by a lazy Dijkstra. Atomic
	// because the self-heal path attaches it while the engine is serving. nil
	// = lazy builds, exactly the pre-artifact behaviour.
	leafTabs atomic.Pointer[LeafTables]

	// partFP identifies the partition build the engine is serving: leaf ids
	// are bisection-order artifacts, so any stored label is only meaningful
	// against the exact partition it was computed on. FNV-1a over LeafOf.
	partFP uint64

	// labelCache: recent QueryLabels results keyed by (origin snap node,
	// whole-minute budget). Labels are immutable once built, so repeated
	// origins (a member browsing page after page) cost a lookup, not a
	// query. Guarded by labelMu; ~KBs per entry.
	labelMu    sync.Mutex
	labelOrder []labelKey
	labelCache map[labelKey]*ReachLabels
}

type labelKey struct {
	origin NodeID
	mins   int32
}

const labelCacheCap = 256

func NewReachEngine(g *Graph, ov *Overlay, part *ReachPartition, rm *RegionMatrices) *ReachEngine {
	return &ReachEngine{
		G: g, Ov: ov, Part: part, RM: rm,
		BI: buildBoundaryIndex(rm, part),
		// 4096 leaves, not 512: the UK partition has ~23,675 leaves averaging ~550
		// overlay nodes (12.9M junctions), so a leaf's lazy table costs ~3-4ms to
		// build and ~70-90KB to keep - 512 covered ~2% of the country and thrashed
		// under mixed traffic, so browse-feed stamping kept re-paying seconds of
		// table builds (measured: 1,000 spread targets = 2.6s cold, 83ms warm) and
		// a member's first feed load could lose a whole chunk to the apiv2 3s
		// timeout - whose crow fallback then made the NEXT refresh render a
		// different order. 4096 ≈ 350MB worst case, sized against the ~7.3GB
		// graph already resident; the populated leaves members actually query
		// stay warm for everyone near them.
		tables:     newRegionTableCache(4096),
		labelCache: make(map[labelKey]*ReachLabels),
		partFP:     partitionFingerprint(part),
	}
}

// partitionFingerprint hashes the leaf assignment itself (FNV-1a over LeafOf),
// so two partition files that assign every overlay node the same region agree,
// and any other pair differ.
func partitionFingerprint(part *ReachPartition) uint64 {
	const offset64, prime64 = 0xcbf29ce484222325, 0x100000001b3
	h := uint64(offset64)
	for _, l := range part.LeafOf {
		v := uint32(l)
		for shift := 0; shift < 32; shift += 8 {
			h ^= uint64(byte(v >> shift))
			h *= prime64
		}
	}
	return h
}

// QueryLabelsCached is QueryLabels behind a small LRU for whole-minute
// budgets. Callers must treat the result as read-only (all callers do).
func (e *ReachEngine) QueryLabelsCached(lat, lng float64, limitSeconds float32) *ReachLabels {
	mins := int32(limitSeconds / 60)
	if float32(mins*60) != limitSeconds {
		return e.QueryLabels(lat, lng, limitSeconds) // fractional: no caching
	}
	origin := nearestDriveNode(e.G, lat, lng)
	if origin == noNode {
		return e.QueryLabels(lat, lng, limitSeconds)
	}
	k := labelKey{origin, mins}
	e.labelMu.Lock()
	if lbl, ok := e.labelCache[k]; ok {
		for i, kk := range e.labelOrder {
			if kk == k {
				e.labelOrder = append(append(e.labelOrder[:i], e.labelOrder[i+1:]...), k)
				break
			}
		}
		e.labelMu.Unlock()
		return lbl
	}
	e.labelMu.Unlock()
	lbl := e.QueryLabels(lat, lng, limitSeconds)
	e.labelMu.Lock()
	e.labelCache[k] = lbl
	e.labelOrder = append(e.labelOrder, k)
	if len(e.labelOrder) > labelCacheCap {
		old := e.labelOrder[0]
		e.labelOrder = e.labelOrder[1:]
		delete(e.labelCache, old)
	}
	e.labelMu.Unlock()
	return lbl
}

// regionTableCache: lazy per-region entry→node internal distance tables, plus
// arbitrary-source rows for stored-label seed evaluation. Post-independent, so
// shared across queries; true LRU (a hit refreshes recency); guarded by a
// coarse mutex (a miss holds the lock while it builds ~ms-scale tables —
// acceptable at current QPS, and trivially shardable later).
type regionTableCache struct {
	mu       sync.Mutex
	cap      int
	order    []int32
	m        map[int32]*regionTable
	srcOrder []srcKey
	src      map[srcKey][]float32
	// srcBytes tracks what the arbitrary-source rows actually hold. The bound
	// used to be a row COUNT (4x the leaf-cache cap, so 16,384 rows), which is
	// blind to leaf size: a row is one float32 per overlay node in the leaf,
	// and leaves reach 10,000 nodes, so the old bound permitted a 655MB cache
	// on a 12GiB budget. Bounding the bytes makes the ceiling mean something.
	srcBytes int
}

// srcCacheMaxBytes caps the arbitrary-source row cache. 128MB holds a few
// thousand of the largest rows, which is far more than the working set of
// origin leaves and stored-label seeds a real query mix touches, and a miss
// only costs a Dijkstra over an already-resident leaf subgraph.
const srcCacheMaxBytes = 128 << 20

type srcKey struct {
	leaf int32
	oi   uint32
}

type regionTable struct {
	// ls is the leaf's CSR subgraph — needed only by the arbitrary-source
	// Dijkstra paths (the origin's own seed leaf, and stored-label seed rows).
	// nil when dist/met come from the precomputed artifact; built on demand
	// via getWithSubgraph, never touched by the entry-table read paths.
	ls      *leafSubgraph
	nodes   []uint32         // == part.LeafNodes[leaf]: local index = position
	localOf map[uint32]int32 // overlay index -> local index
	dist    [][]float32      // per entry (aligned to rm.LeafEntries), len(nodes)
	met     [][]float32      // road metres along the same time-optimal paths
}

func newRegionTableCache(cap int) *regionTableCache {
	return &regionTableCache{cap: cap, m: make(map[int32]*regionTable), src: make(map[srcKey][]float32)}
}

func (c *regionTableCache) get(e *ReachEngine, leaf int32) *regionTable {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.getLocked(e, leaf)
}

func (c *regionTableCache) getLocked(e *ReachEngine, leaf int32) *regionTable {
	if t, ok := c.m[leaf]; ok {
		// True LRU: refresh recency on hit so hot regions survive eviction.
		for i, l := range c.order {
			if l == leaf {
				c.order = append(append(c.order[:i], c.order[i+1:]...), leaf)
				break
			}
		}
		return t
	}

	nodes := e.Part.LeafNodes[leaf]
	ents := e.RM.LeafEntries(leaf)
	t := &regionTable{nodes: nodes, dist: make([][]float32, len(ents)), met: make([][]float32, len(ents))}

	// Precomputed artifact first: the whole table is already on disk (mmap'd),
	// so admitting a leaf costs only the localOf map and a few slice headers —
	// microseconds and ~zero heap — instead of one Dijkstra per entry.
	if lt := e.leafTabs.Load(); lt != nil {
		if dist, met, en, nn, ok := lt.table(leaf); ok && en == len(ents) && nn == len(nodes) {
			t.localOf = make(map[uint32]int32, len(nodes))
			for i, oi := range nodes {
				t.localOf[oi] = int32(i)
			}
			for i := range ents {
				t.dist[i] = dist[i*nn : (i+1)*nn]
				t.met[i] = met[i*nn : (i+1)*nn]
			}
			c.admitLocked(leaf, t)
			return t
		}
	}

	// Lazy fallback: no artifact (or a shape mismatch, which the fingerprint
	// makes near-impossible) — build the subgraph and Dijkstra each entry.
	ls := buildLeafSubgraph(e.Ov, e.Part, leaf)
	t.ls = ls
	t.localOf = ls.localOf
	for i, ent := range ents {
		d := make([]float32, len(ls.nodes))
		m := make([]float32, len(ls.nodes))
		ls.dijkstraFromM(ls.localOf[ent], d, m)
		t.dist[i] = d
		t.met[i] = m
	}
	c.admitLocked(leaf, t)
	return t
}

// admitLocked stores a freshly built table and applies the LRU cap. Callers
// must hold mu.
func (c *regionTableCache) admitLocked(leaf int32, t *regionTable) {
	c.m[leaf] = t
	c.order = append(c.order, leaf)
	if len(c.order) > c.cap {
		old := c.order[0]
		c.order = c.order[1:]
		delete(c.m, old)
	}
}

// getWithSubgraph is get for the callers that need the leaf's CSR subgraph
// (arbitrary-source Dijkstras: the origin's own seed leaf, stored-label seed
// rows). An artifact-backed table has no subgraph until one of these asks;
// it is built once under the lock and immutable after, so the plain get
// path never synchronises on it.
func (c *regionTableCache) getWithSubgraph(e *ReachEngine, leaf int32) *regionTable {
	c.mu.Lock()
	defer c.mu.Unlock()
	t := c.getLocked(e, leaf)
	if t.ls == nil {
		t.ls = buildLeafSubgraph(e.Ov, e.Part, leaf)
	}
	return t
}

// sourceRow returns intra-region distances from an arbitrary junction of the
// leaf (used for stored-label seeds), nil if the source is not in the leaf.
func (c *regionTableCache) sourceRow(e *ReachEngine, leaf int32, srcOi uint32) []float32 {
	c.mu.Lock()
	defer c.mu.Unlock()
	k := srcKey{leaf, srcOi}
	if row, ok := c.src[k]; ok {
		return row
	}
	t := c.getLocked(e, leaf)
	if t.ls == nil {
		// Artifact-backed table: the arbitrary-source row still needs the CSR
		// subgraph. Built once under mu (we hold it), immutable after.
		t.ls = buildLeafSubgraph(e.Ov, e.Part, leaf)
	}
	li, in := t.ls.localOf[srcOi]
	if !in {
		return nil
	}
	row := make([]float32, len(t.ls.nodes))
	t.ls.dijkstraFrom(li, row)
	c.src[k] = row
	c.srcOrder = append(c.srcOrder, k)
	c.srcBytes += 4 * len(row)
	for c.srcBytes > srcCacheMaxBytes && len(c.srcOrder) > 1 {
		old := c.srcOrder[0]
		c.srcOrder = c.srcOrder[1:]
		if prev, ok := c.src[old]; ok {
			c.srcBytes -= 4 * len(prev)
			delete(c.src, old)
		}
	}
	return row
}

// QueryLabels computes the reach labeling from (lat,lng) within limitSeconds.
func (e *ReachEngine) QueryLabels(lat, lng float64, limitSeconds float32) *ReachLabels {
	return e.QueryLabelsFromNode(nearestDriveNode(e.G, lat, lng), limitSeconds)
}

// QueryLabelsFromNode is QueryLabels with the origin given as a graph node -
// the group-boundary catchment seeds from specific nodes, not a lat/lng snap.
func (e *ReachEngine) QueryLabelsFromNode(origin NodeID, limitSeconds float32) *ReachLabels {
	out := &ReachLabels{
		T:         limitSeconds,
		Reached:   make(map[int32]*RegionLabel),
		OriginArr: make(map[uint32]float32),
		OriginMet: make(map[uint32]float32),
		Seeds:     make(map[uint32]float32),
		SeedMet:   make(map[uint32]float32),
	}

	if origin == noNode {
		return out
	}
	seed := driveStartupSecs
	out.seedBase = seed
	if oi := e.Ov.Idx[origin]; oi != 0 {
		out.Seeds[oi] = seed
		out.SeedMet[oi] = 0
	} else {
		out.originChain = origin
		a, sa, ma, b, sb, mb := chainDepartOffsets(e.G, e.Ov, origin)
		if sa >= 0 {
			out.Seeds[e.Ov.Idx[a]] = seed + sa
			out.SeedMet[e.Ov.Idx[a]] = ma
		}
		if sb >= 0 {
			boi := e.Ov.Idx[b]
			if cur, ok := out.Seeds[boi]; !ok || seed+sb < cur {
				out.Seeds[boi] = seed + sb
				out.SeedMet[boi] = mb
			}
		}
	}

	// Phase 1: local exact Dijkstra within each seed's region.
	t0 := time.Now()
	type exitArr struct {
		oi       uint32
		arr, met float32
	}
	var exitArrs []exitArr
	seededLeaves := map[int32]struct{}{}
	for oi, s := range out.Seeds {
		leaf := e.Part.LeafOf[oi]
		if leaf < 0 {
			continue
		}
		seededLeaves[leaf] = struct{}{}
		// The seed is an arbitrary junction, not a region entry, so this leaf
		// needs its CSR subgraph for a fresh Dijkstra — the one table read the
		// precomputed artifact cannot serve.
		t := e.tables.getWithSubgraph(e, leaf)
		dist := make([]float32, len(t.ls.nodes))
		metd := make([]float32, len(t.ls.nodes))
		t.ls.dijkstraFromM(t.ls.localOf[oi], dist, metd)
		sm := out.SeedMet[oi]
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
				out.OriginMet[node] = sm + metd[i]
			}
		}
		for _, x := range e.RM.LeafBoundary(leaf) {
			xi := t.ls.localOf[x]
			if d := dist[xi]; d != f32Inf && s+d <= limitSeconds {
				exitArrs = append(exitArrs, exitArr{x, s + d, sm + metd[xi]})
			}
		}
	}
	out.LocalMs = float64(time.Since(t0).Microseconds()) / 1000

	// Phase 2: boundary Dijkstra (seconds decide; metres ride along).
	t1 := time.Now()
	dist := make(map[uint32]float32)
	distM := make(map[uint32]float32)
	h := &miniHeap32{}
	push := func(oi uint32, c, m float32) {
		if c > limitSeconds {
			return
		}
		if cur, ok := dist[oi]; !ok || c < cur {
			dist[oi] = c
			distM[oi] = m
			heap.Push(h, heapItem32{oi, c})
		}
	}
	for _, xa := range exitArrs {
		push(xa.oi, xa.arr, xa.met)
	}
	for oi, s := range out.Seeds {
		if _, ok := e.BI.leafOf[oi]; ok {
			push(oi, s, out.SeedMet[oi])
		}
	}
	for h.Len() > 0 {
		cur := heap.Pop(h).(heapItem32)
		if d, ok := dist[cur.oi]; !ok || cur.c > d {
			continue
		}
		curM := distM[cur.oi]
		// Cross edges (this node as an exit).
		if cr, ok := e.BI.cross[cur.oi]; ok {
			for i := cr.start; i < cr.start+cr.count; i++ {
				push(e.BI.crossTo[i], cur.c+e.BI.crossSec[i], curM+e.BI.crossMet[i])
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
					push(x, cur.c+m, curM+e.RM.MatM[base+int64(xi)])
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
			lbl.EntryMet = make([]float32, len(lbl.EntryArr))
			for i := range lbl.EntryMet {
				lbl.EntryMet[i] = f32Inf
			}
			out.Reached[leaf] = lbl
		}
		lbl.EntryArr[ei] = arr
		lbl.EntryMet[ei] = distM[oi]
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

func newRegionLabel(e *ReachEngine, leaf int32) *RegionLabel {
	ents := e.RM.LeafEntries(leaf)
	lbl := &RegionLabel{EntryArr: make([]float32, len(ents))}
	for i := range lbl.EntryArr {
		lbl.EntryArr[i] = f32Inf
	}
	return lbl
}

// junctionArrival returns the exact arrival at base junction j, +Inf if
// unreached.
func (e *ReachEngine) junctionArrival(lbl *ReachLabels, j NodeID) float32 {
	s, _ := e.junctionArrivalM(lbl, j)
	return s
}

// junctionArrivalM also returns the road metres along the winning path (+Inf
// when metres are unavailable, e.g. decoded stored labels).
func (e *ReachEngine) junctionArrivalM(lbl *ReachLabels, j NodeID) (float32, float32) {
	oi := e.Ov.Idx[j]
	if oi == 0 {
		return f32Inf, f32Inf
	}
	best, bestM := f32Inf, f32Inf
	if a, ok := lbl.OriginArr[oi]; ok && a < best {
		best = a
		bestM = f32Inf
		if m, ok := lbl.OriginMet[oi]; ok {
			bestM = m
		}
	}
	leaf := e.Part.LeafOf[oi]
	if leaf < 0 {
		return best, bestM
	}
	rl, ok := lbl.Reached[leaf]
	if !ok {
		return best, bestM
	}
	t := e.tables.get(e, leaf)
	li, in := t.localOf[oi]
	if !in {
		return best, bestM
	}
	for i, arr := range rl.EntryArr {
		if arr == f32Inf {
			continue
		}
		if d := t.dist[i][li]; d != f32Inf && arr+d < best {
			best = arr + d
			bestM = f32Inf
			if rl.EntryMet != nil {
				bestM = rl.EntryMet[i] + t.met[i][li]
			}
		}
	}
	return best, bestM
}

// Arrival returns the exact drive arrival seconds at (lat,lng), +Inf when out
// of reach. Membership = Arrival(...) <= labels.T.
func (e *ReachEngine) Arrival(lbl *ReachLabels, lat, lng float64) float32 {
	v := nearestDriveNode(e.G, lat, lng)
	if v == noNode {
		return f32Inf
	}
	return e.ArrivalAtBaseNode(lbl, v)
}

// ArrivalAtBaseNode is Arrival for an already-snapped base node.
func (e *ReachEngine) ArrivalAtBaseNode(lbl *ReachLabels, v NodeID) float32 {
	s, _ := e.ArrivalAtBaseNodeM(lbl, v)
	return s
}

// ArrivalAtBaseNodeM also returns road metres along the winning path (+Inf
// when unavailable).
func (e *ReachEngine) ArrivalAtBaseNodeM(lbl *ReachLabels, v NodeID) (float32, float32) {
	if e.Ov.Idx[v] != 0 {
		return e.junctionArrivalM(lbl, v)
	}
	best, bestM := f32Inf, f32Inf
	if a, offA, okA := e.Ov.ChainEndA[v], float32(0), false; func() bool { offA, okA = e.Ov.OffA(v); return a != 0 && okA }() {
		if ja, jm := e.junctionArrivalM(lbl, a); ja+offA < best {
			best = ja + offA
			bestM = f32Inf
			if jm != f32Inf {
				if cm := chainMetresFromEnd(e.G, e.Ov, a, v); cm >= 0 {
					bestM = jm + cm
				}
			}
		}
	}
	if b, offB, okB := e.Ov.ChainEndB[v], float32(0), false; func() bool { offB, okB = e.Ov.OffB(v); return b != 0 && okB }() {
		if jb, jm := e.junctionArrivalM(lbl, b); jb+offB < best {
			best = jb + offB
			bestM = f32Inf
			if jm != f32Inf {
				if cm := chainMetresFromEnd(e.G, e.Ov, b, v); cm >= 0 {
					bestM = jm + cm
				}
			}
		}
	}
	// Origin on the same chain: direct along-chain travel with no junction.
	// End-pair equality is NOT sufficient — two distinct parallel chains can
	// join the same junction pair (found by the UK sweep: a circular lane in
	// Aberdeenshire) — so walk the origin's actual chain to confirm v is on
	// it and price the hop-exact departure cost.
	if o := lbl.originChain; o != 0 && e.Ov.ChainEndA[o] == e.Ov.ChainEndA[v] && e.Ov.ChainEndB[o] == e.Ov.ChainEndB[v] {
		if c, cm := sameChainDepartCostM(e.G, e.Ov, o, v); c >= 0 && lbl.seedBase+c < best {
			best = lbl.seedBase + c
			bestM = cm
		}
	}
	return best, bestM
}

// sameChainDepartCost walks from chain node o along its own chain in both
// drivable directions, returning the drive seconds to v if v lies on the SAME
// chain, else -1. Bounded by the chain length.
func sameChainDepartCost(g *Graph, ov *Overlay, o, v NodeID) float32 {
	s, _ := sameChainDepartCostM(g, ov, o, v)
	return s
}

func sameChainDepartCostM(g *Graph, ov *Overlay, o, v NodeID) (float32, float32) {
	if o == v {
		return 0, 0
	}
	hop := func(a, b NodeID) float32 {
		na, nb := g.Nodes[a], g.Nodes[b]
		return float32(haversineM(float64(na.Lat), float64(na.Lng), float64(nb.Lat), float64(nb.Lng)))
	}
	best, bestM := float32(-1), float32(-1)
	for i := range g.EdgesFrom(o) {
		e := &g.Edges[g.EdgeStart[o]+int32(i)]
		sum := e.Sec()
		msum := hop(o, e.To)
		prev, cur := o, e.To
		for ov.Idx[cur] == 0 {
			if cur == v {
				if best < 0 || sum < best {
					best = sum
					bestM = msum
				}
				break
			}
			var next *Edge
			for j := range g.EdgesFrom(cur) {
				e2 := &g.Edges[g.EdgeStart[cur]+int32(j)]
				if e2.To != prev {
					next = e2
					break
				}
			}
			if next == nil {
				break
			}
			sum += next.Sec()
			msum += hop(cur, next.To)
			prev, cur = cur, next.To
		}
	}
	return best, bestM
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
