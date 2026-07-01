package main

// ch_proto.go - Contraction Hierarchy prototype for Drive mode only.
//
// Classic Geisberger et al. CH:
//
//  BuildCH:  Contract nodes in order of edge-difference heuristic.
//            Add shortcuts to preserve shortest paths.
//            Build overlay edge lists.
//
//  CHQuery:  Bidirectional upward Dijkstra.
//            Forward from src:  follow UpEdges (rank increases).
//            Backward from dst: follow BwdEdges (reverse-overlay edges going to higher rank).
//            Meeting node: any node settled in both gives candidate.
//
//  PHAST:    Phase 1: upward Dijkstra (UpEdges only).
//            Phase 2: downward sweep in descending rank, relax PhastDownEdges.

import (
	"container/heap"
	"math"
	"sort"
	"time"
)

// chEdge is a directed edge in the CH overlay graph.
type chEdge struct {
	To     NodeID
	Weight float32
}

// CH holds the preprocessed contraction hierarchy for Drive mode.
type CH struct {
	// Rank[id] = contraction order (0 = first contracted = lowest rank).
	Rank []int32

	// UpEdges[u] = overlay edges u->v where rank[u] < rank[v].
	// Forward upward search from src uses UpEdges.
	UpEdges [][]chEdge

	// BwdEdges[v] = overlay edges x->v where rank[x] > rank[v], stored at v.
	// Backward upward search from dst: from node v, expand to x with cost bwdDist[x] = bwdDist[v] + w(x->v).
	// This enables going upward in the reversed graph: in G_rev, edge (v->x) where rank[x]>rank[v],
	// corresponds to original edge (x->v) with rank[x]>rank[v] = a down-edge in original.
	BwdEdges [][]chEdge

	// PhastDownEdges[u] = overlay edges u->v where rank[u] > rank[v].
	// PHAST phase2: process nodes descending rank; for each u relax u->v downward.
	PhastDownEdges [][]chEdge

	// NodesByDescRank: drive nodes sorted in descending rank order for PHAST phase2.
	NodesByDescRank []NodeID

	// DriveNodeIDs: nodes participating in drive subgraph.
	DriveNodeIDs []NodeID

	// NodeCount = number of valid nodes (1..NodeCount).
	NodeCount int

	// Preprocessing stats.
	BuildTime     time.Duration
	OrigEdgeCount int
	ShortcutCount int
}

// simEdge is used internally during CH construction.
type simEdge struct {
	to     NodeID
	weight float32
}

// BuildCH constructs a contraction hierarchy over the drive subgraph of g.
func BuildCH(g *Graph) *CH {
	t0 := time.Now()
	N := g.NodeCount()

	// Build bidirectional adjacency for the drive subgraph.
	outAdj := make([][]simEdge, N+1)
	inAdj := make([][]simEdge, N+1)
	origEdgeCount := 0

	for u := NodeID(1); u <= NodeID(N); u++ {
		for _, e := range g.EdgesFrom(u) {
			if e.Seconds[Drive] < 0 {
				continue
			}
			w := e.Seconds[Drive]
			outAdj[u] = append(outAdj[u], simEdge{e.To, w})
			inAdj[e.To] = append(inAdj[e.To], simEdge{u, w})
			origEdgeCount++
		}
	}

	driveNode := make([]bool, N+1)
	for u := NodeID(1); u <= NodeID(N); u++ {
		if len(outAdj[u]) > 0 || len(inAdj[u]) > 0 {
			driveNode[u] = true
		}
	}

	contracted := make([]bool, N+1)
	const hopLimit = 5

	// edgeDiff estimates (shortcuts_added - edges_removed) for contracting u.
	edgeDiff := func(u NodeID) int {
		inEdges := inAdj[u]
		outEdges := outAdj[u]

		inDeg, outDeg := 0, 0
		for _, ie := range inEdges {
			if !contracted[ie.to] && ie.to != u {
				inDeg++
			}
		}
		for _, oe := range outEdges {
			if !contracted[oe.to] && oe.to != u {
				outDeg++
			}
		}

		shortcuts := 0
		for _, ie := range inEdges {
			v := ie.to
			if contracted[v] || v == u {
				continue
			}
			vu := ie.weight

			maxNeeded := float32(0)
			for _, oe := range outEdges {
				if !contracted[oe.to] && oe.to != u {
					if c := vu + oe.weight; c > maxNeeded {
						maxNeeded = c
					}
				}
			}
			if maxNeeded == 0 {
				continue
			}

			witness := chWitnessSearch(v, u, maxNeeded, hopLimit, contracted, outAdj)
			for _, oe := range outEdges {
				w2 := oe.to
				if contracted[w2] || w2 == u || w2 == v {
					continue
				}
				need := vu + oe.weight
				if wd, ok := witness[w2]; !ok || wd > need-1e-4 {
					shortcuts++
				}
			}
		}
		return shortcuts - (inDeg + outDeg)
	}

	// Lazy priority queue for node ordering (min edge-difference first).
	pq := &chOrderHeap{}
	for u := NodeID(1); u <= NodeID(N); u++ {
		if driveNode[u] {
			heap.Push(pq, chOrderItem{u, edgeDiff(u)})
		}
	}

	rank := make([]int32, N+1)
	curRank := int32(0)
	shortcutCount := 0

	for pq.Len() > 0 {
		top := heap.Pop(pq).(chOrderItem)
		u := top.node
		if contracted[u] {
			continue
		}
		// Lazy re-evaluation.
		newP := edgeDiff(u)
		if newP > top.priority {
			heap.Push(pq, chOrderItem{u, newP})
			continue
		}

		// Contract u at curRank.
		rank[u] = curRank
		curRank++
		contracted[u] = true

		inEdges := inAdj[u]
		outEdges := outAdj[u]

		for _, ie := range inEdges {
			v := ie.to
			if contracted[v] || v == u {
				continue
			}
			vu := ie.weight

			maxNeeded := float32(0)
			for _, oe := range outEdges {
				if !contracted[oe.to] && oe.to != u {
					if c := vu + oe.weight; c > maxNeeded {
						maxNeeded = c
					}
				}
			}
			if maxNeeded == 0 {
				continue
			}

			witness := chWitnessSearch(v, u, maxNeeded, hopLimit, contracted, outAdj)
			for _, oe := range outEdges {
				w2 := oe.to
				if contracted[w2] || w2 == u || w2 == v {
					continue
				}
				need := vu + oe.weight
				if wd, ok := witness[w2]; !ok || wd > need-1e-4 {
					outAdj[v] = append(outAdj[v], simEdge{w2, need})
					inAdj[w2] = append(inAdj[w2], simEdge{v, need})
					shortcutCount++
				}
			}
		}
	}

	// Build overlay edge lists.
	// For each edge (u->v) in the final outAdj (original + shortcuts):
	//   if rank[u] < rank[v]: up-edge -> UpEdges[u]
	//   if rank[u] > rank[v]: down-edge -> PhastDownEdges[u], BwdEdges[v]
	upEdges := make([][]chEdge, N+1)
	bwdEdges := make([][]chEdge, N+1)
	phastDown := make([][]chEdge, N+1)

	for u := NodeID(1); u <= NodeID(N); u++ {
		if !driveNode[u] {
			continue
		}
		for _, e := range outAdj[u] {
			v := e.to
			if !driveNode[v] || v == u {
				continue
			}
			if rank[u] < rank[v] {
				// Up-edge u->v.
				upEdges[u] = append(upEdges[u], chEdge{v, e.weight})
			} else {
				// Down-edge u->v (rank[u] > rank[v]).
				// For PHAST phase2: u (higher rank) propagates to v (lower rank).
				phastDown[u] = append(phastDown[u], chEdge{v, e.weight})
				// For backward CH query from dst: at node v, we can reach u (higher rank)
				// in the reversed graph. Cost bwdDist[u] = bwdDist[v] + w(u->v).
				bwdEdges[v] = append(bwdEdges[v], chEdge{u, e.weight})
			}
		}
	}

	// Collect drive node IDs sorted by descending rank for PHAST phase2.
	driveNodeIDs := make([]NodeID, 0, int(curRank))
	for u := NodeID(1); u <= NodeID(N); u++ {
		if driveNode[u] {
			driveNodeIDs = append(driveNodeIDs, u)
		}
	}
	byDescRank := make([]NodeID, len(driveNodeIDs))
	copy(byDescRank, driveNodeIDs)
	sort.Slice(byDescRank, func(i, j int) bool {
		return rank[byDescRank[i]] > rank[byDescRank[j]]
	})

	return &CH{
		Rank:            rank,
		UpEdges:         upEdges,
		BwdEdges:        bwdEdges,
		PhastDownEdges:  phastDown,
		NodesByDescRank: byDescRank,
		DriveNodeIDs:    driveNodeIDs,
		NodeCount:       N,
		BuildTime:       time.Since(t0),
		OrigEdgeCount:   origEdgeCount,
		ShortcutCount:   shortcutCount,
	}
}

// ────────────────────────────────────────────────────────────────
// Witness search
// ────────────────────────────────────────────────────────────────

type chWitnessItem struct {
	node NodeID
	cost float32
	hops int
}
type chWitnessHeap []chWitnessItem

func (h chWitnessHeap) Len() int            { return len(h) }
func (h chWitnessHeap) Less(i, j int) bool  { return h[i].cost < h[j].cost }
func (h chWitnessHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *chWitnessHeap) Push(x interface{}) { *h = append(*h, x.(chWitnessItem)) }
func (h *chWitnessHeap) Pop() interface{} {
	old := *h; n := len(old); x := old[n-1]; *h = old[:n-1]; return x
}

func chWitnessSearch(src, excl NodeID, maxDist float32, hopLimit int, contracted []bool, out [][]simEdge) map[NodeID]float32 {
	dist := make(map[NodeID]float32, 16)
	dist[src] = 0

	q := &chWitnessHeap{}
	heap.Push(q, chWitnessItem{src, 0, 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(chWitnessItem)
		if cur.cost > dist[cur.node]+1e-6 {
			continue
		}
		if cur.hops >= hopLimit {
			continue
		}
		for _, e := range out[cur.node] {
			if e.to == excl || contracted[e.to] {
				continue
			}
			nc := cur.cost + e.weight
			if nc > maxDist {
				continue
			}
			if prev, ok := dist[e.to]; !ok || nc < prev {
				dist[e.to] = nc
				heap.Push(q, chWitnessItem{e.to, nc, cur.hops + 1})
			}
		}
	}
	return dist
}

// ────────────────────────────────────────────────────────────────
// Node ordering priority queue
// ────────────────────────────────────────────────────────────────

type chOrderItem struct {
	node     NodeID
	priority int
}
type chOrderHeap []chOrderItem

func (h chOrderHeap) Len() int { return len(h) }
func (h chOrderHeap) Less(i, j int) bool {
	if h[i].priority != h[j].priority {
		return h[i].priority < h[j].priority
	}
	return h[i].node < h[j].node
}
func (h chOrderHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *chOrderHeap) Push(x interface{}) { *h = append(*h, x.(chOrderItem)) }
func (h *chOrderHeap) Pop() interface{} {
	old := *h; n := len(old); x := old[n-1]; *h = old[:n-1]; return x
}

// ────────────────────────────────────────────────────────────────
// CH Bidirectional Upward Dijkstra
// ────────────────────────────────────────────────────────────────

// biItem is a priority queue entry.
type biItem struct {
	node NodeID
	cost float32
}
type chBiHeap []biItem

func (h chBiHeap) Len() int            { return len(h) }
func (h chBiHeap) Less(i, j int) bool  { return h[i].cost < h[j].cost }
func (h chBiHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *chBiHeap) Push(x interface{}) { *h = append(*h, x.(biItem)) }
func (h *chBiHeap) Pop() interface{} {
	old := *h; n := len(old); x := old[n-1]; *h = old[:n-1]; return x
}

// CHQuery runs a bidirectional CH upward Dijkstra from src to dst.
// Returns shortest drive time in seconds, or math.MaxFloat32 if unreachable.
//
// Forward search (from src): follow UpEdges[u] (u->v where rank[u] < rank[v]).
//   fwdDist[v] updated via fwdDist[u] + w(u->v).
//
// Backward search (from dst): follow BwdEdges[v] (stored at v: edges x->v where rank[x] > rank[v]).
//   In G_rev, edge x->v becomes v->x; "upward in G_rev" means rank increases: rank[v] < rank[x].
//   So from node v in backward search, we expand to x (higher rank) with cost bwdDist[x] = bwdDist[v] + w(x->v).
//
// A node u settled in both searches contributes candidate fwdDist[u] + bwdDist[u].
func (ch *CH) CHQuery(src, dst NodeID) float32 {
	if src == dst {
		return 0
	}
	N := ch.NodeCount
	inf := float32(math.MaxFloat32)

	fwdDist := make([]float32, N+1)
	bwdDist := make([]float32, N+1)
	for i := range fwdDist {
		fwdDist[i] = inf
		bwdDist[i] = inf
	}
	fwdDist[src] = 0
	bwdDist[dst] = 0

	fwdSettled := make([]bool, N+1)
	bwdSettled := make([]bool, N+1)

	fwdQ := &chBiHeap{}
	bwdQ := &chBiHeap{}
	heap.Push(fwdQ, biItem{src, 0})
	heap.Push(bwdQ, biItem{dst, 0})

	best := inf

	for fwdQ.Len() > 0 || bwdQ.Len() > 0 {
		fwdTop := inf
		if fwdQ.Len() > 0 {
			fwdTop = (*fwdQ)[0].cost
		}
		bwdTop := inf
		if bwdQ.Len() > 0 {
			bwdTop = (*bwdQ)[0].cost
		}
		if fwdTop >= best && bwdTop >= best {
			break
		}

		expandFwd := fwdQ.Len() > 0 && (bwdQ.Len() == 0 || fwdTop <= bwdTop)

		if expandFwd {
			cur := heap.Pop(fwdQ).(biItem)
			u := cur.node
			if cur.cost > fwdDist[u] || fwdSettled[u] {
				continue
			}
			fwdSettled[u] = true
			if bwdDist[u] < inf {
				if c := fwdDist[u] + bwdDist[u]; c < best {
					best = c
				}
			}
			for _, e := range ch.UpEdges[u] {
				nc := fwdDist[u] + e.Weight
				if nc < fwdDist[e.To] {
					fwdDist[e.To] = nc
					heap.Push(fwdQ, biItem{e.To, nc})
				}
			}
		} else {
			cur := heap.Pop(bwdQ).(biItem)
			v := cur.node
			if cur.cost > bwdDist[v] || bwdSettled[v] {
				continue
			}
			bwdSettled[v] = true
			if fwdDist[v] < inf {
				if c := fwdDist[v] + bwdDist[v]; c < best {
					best = c
				}
			}
			// Backward search: from v, expand to x (higher rank) where edge x->v exists.
			// BwdEdges[v] = {x, w(x->v)} where rank[x] > rank[v].
			// bwdDist[x] = bwdDist[v] + w(x->v).
			for _, e := range ch.BwdEdges[v] {
				nc := bwdDist[v] + e.Weight
				if nc < bwdDist[e.To] {
					bwdDist[e.To] = nc
					heap.Push(bwdQ, biItem{e.To, nc})
				}
			}
		}
	}

	return best
}

// ────────────────────────────────────────────────────────────────
// PHAST: one-to-all via CH
// ────────────────────────────────────────────────────────────────

// PHASTResult holds per-node drive times from a source.
type PHASTResult struct {
	Dist []float32 // Dist[id] = seconds; math.MaxFloat32 = unreachable
}

// PHAST computes shortest drive times from src to all nodes.
//
// Phase 1 (Upward Dijkstra): from src, follow only UpEdges.
//   This settles all nodes at higher rank than src.
//
// Phase 2 (Downward sweep): iterate NodesByDescRank.
//   For each node u in descending rank order, relax PhastDownEdges[u] (u->v, rank[u]>rank[v]).
//   dist[v] = min(dist[v], dist[u] + w(u->v)).
//   Correctness: when processing u, dist[u] is already final because all higher-rank
//   nodes that can improve dist[u] were processed before u.
func (ch *CH) PHAST(src NodeID) PHASTResult {
	N := ch.NodeCount
	inf := float32(math.MaxFloat32)

	dist := make([]float32, N+1)
	for i := range dist {
		dist[i] = inf
	}
	dist[src] = 0

	// Phase 1: upward Dijkstra.
	q := &chBiHeap{}
	heap.Push(q, biItem{src, 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(biItem)
		u := cur.node
		if cur.cost > dist[u] {
			continue
		}
		for _, e := range ch.UpEdges[u] {
			nc := dist[u] + e.Weight
			if nc < dist[e.To] {
				dist[e.To] = nc
				heap.Push(q, biItem{e.To, nc})
			}
		}
	}

	// Phase 2: downward sweep.
	for _, u := range ch.NodesByDescRank {
		if dist[u] >= inf {
			continue
		}
		for _, e := range ch.PhastDownEdges[u] {
			nc := dist[u] + e.Weight
			if nc < dist[e.To] {
				dist[e.To] = nc
			}
		}
	}

	return PHASTResult{Dist: dist}
}

// PHASTIsochrone computes drive-reachable nodes within limitSeconds using PHAST.
// Returns the same IsochroneResult format as the existing Isochrone() function.
func PHASTIsochrone(g *Graph, ch *CH, lat, lng float64, limitSeconds float32) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, Drive)
	if origin == noNode {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}}
	}

	result := ch.PHAST(origin)

	reached := make(map[NodeID]float32)
	for _, id := range ch.DriveNodeIDs {
		d := result.Dist[id]
		if d <= limitSeconds {
			reached[id] = d
		}
	}
	return IsochroneResult{ReachedNodes: reached}
}
