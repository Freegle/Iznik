package main

// Stage 2 partitioner: nested bisection of the drive overlay by Inertial Flow
// (Schild & Sommer): project nodes onto a handful of axes, fix the extreme
// alpha fraction each side as super-source/super-sink, run a unit-capacity
// max-flow (Dinic), and take the min cut — max-flow min-cut duality finds the
// connectivity seam constructively, so estuaries and motorway corridors fall
// out as the region boundaries. Recurse until regions are leaf-sized.
//
// The recursion works on one global order slice (quicksort style): a region is
// a contiguous order[lo:hi] window and bisection reorders within the window,
// so subset membership is pos[v] ∈ [lo,hi) with zero per-call allocation.

import (
	"encoding/json"
	"fmt"
	"log"
	"math"
	"os"
	"runtime"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

// ugraph is the undirected drive subgraph of the overlay, in CSR form over
// compact ug indices 0..n-1.
type ugraph struct {
	overlayOf []uint32 // ug index -> overlay index
	ugOf      []int32  // overlay index -> ug index, -1 if not drive-usable
	edgeStart []int32
	edgeTo    []int32 // undirected: each edge appears from both endpoints
	x, y      []float32
}

// buildDriveUG extracts the undirected drive subgraph from the overlay.
// Parallel chain edges between the same junction pair are kept (they are
// genuinely parallel roads and must each count toward a cut); self-loops are
// dropped (they can never cross a cut).
func buildDriveUG(g *Graph, ov *Overlay) *ugraph {
	on := ov.NodeCount()
	ug := &ugraph{ugOf: make([]int32, on+1)}
	for i := range ug.ugOf {
		ug.ugOf[i] = -1
	}
	// Collect undirected edges: for a two-way chain both directed edges exist;
	// dedupe by keeping u<v plus counting duplicates, and keep oneway chains
	// too (a oneway road still carries connectivity across a seam).
	type upair struct{ u, v uint32 }
	seen := make(map[upair]uint8, len(ov.Edges)/2)
	for oi := uint32(1); oi <= uint32(on); oi++ {
		for _, e := range ov.EdgesFrom(oi) {
			if e.Seconds[Drive] < 0 || e.To == oi {
				continue
			}
			u, v := oi, e.To
			if u > v {
				u, v = v, u
			}
			p := upair{u, v}
			// A two-way chain contributes the pair twice (once per direction):
			// count 2 => one undirected edge. Two genuinely parallel two-way
			// chains contribute 4 => two undirected edges.
			if seen[p] < 250 {
				seen[p]++
			}
		}
	}
	deg := make(map[uint32]int32)
	type uedge struct{ u, v uint32 }
	var edges []uedge
	for p, cnt := range seen {
		// Each undirected edge = ceil(cnt/2): a oneway chain (cnt 1) is one
		// undirected edge; a two-way chain (cnt 2) is one; two parallel
		// two-ways (cnt 4) are two.
		n := int((cnt + 1) / 2)
		for k := 0; k < n; k++ {
			edges = append(edges, uedge{p.u, p.v})
			deg[p.u]++
			deg[p.v]++
		}
	}
	seen = nil

	// Compact node numbering over nodes with degree > 0.
	for oi := uint32(1); oi <= uint32(on); oi++ {
		if deg[oi] > 0 {
			ug.ugOf[oi] = int32(len(ug.overlayOf))
			ug.overlayOf = append(ug.overlayOf, oi)
		}
	}
	n := len(ug.overlayOf)
	ug.edgeStart = make([]int32, n+1)
	for i := 0; i < n; i++ {
		ug.edgeStart[i+1] = ug.edgeStart[i] + deg[ug.overlayOf[i]]
	}
	ug.edgeTo = make([]int32, int(ug.edgeStart[n]))
	fill := make([]int32, n)
	for _, e := range edges {
		u, v := ug.ugOf[e.u], ug.ugOf[e.v]
		ug.edgeTo[ug.edgeStart[u]+fill[u]] = v
		fill[u]++
		ug.edgeTo[ug.edgeStart[v]+fill[v]] = u
		fill[v]++
	}

	// Projection coordinates: equirectangular metres-ish, so the diagonal axes
	// are meaningful.
	ug.x = make([]float32, n)
	ug.y = make([]float32, n)
	const latScale = 111.2 // km per degree
	for i := 0; i < n; i++ {
		nd := g.Nodes[ov.BaseNode[ug.overlayOf[i]]]
		ug.x[i] = float32(float64(nd.Lng) * latScale * math.Cos(54*math.Pi/180))
		ug.y[i] = float32(float64(nd.Lat) * latScale)
	}
	return ug
}

// BisectStat records one bisection for the gate measurements.
type BisectStat struct {
	ID      int     `json:"id"`
	Parent  int     `json:"parent"`
	Depth   int     `json:"depth"`
	Size    int     `json:"size"`
	SizeA   int     `json:"sizeA"`
	SizeB   int     `json:"sizeB"`
	Cut     int     `json:"cut"`
	Axis    int     `json:"axis"`
	CutLat  float64 `json:"cutLat"`
	CutLng  float64 `json:"cutLng"`
	Millis  int64   `json:"ms"`
	LeafA   int     `json:"leafA"` // leaf id if A became a leaf, else -1
	LeafB   int     `json:"leafB"`
	Comp    bool    `json:"component"` // true = component split, not a flow cut
	Balance float64 `json:"balance"`
}

// Stage2Partition is the partition artifact.
type Stage2Partition struct {
	// LeafOf[overlayIdx] = leaf id, -1 if the overlay node is not drive-usable.
	LeafOf []int32
	// LeafNodes[leaf] = overlay indices in the leaf.
	LeafNodes [][]uint32
	Stats     []BisectStat
}

// partitioner carries shared state for the recursive bisection.
type partitioner struct {
	ug      *ugraph
	order   []int32 // global permutation of ug indices
	pos     []int32 // pos[ugIdx] = index in order
	leafMax int
	alpha   float64

	mu     sync.Mutex
	stats  []BisectStat
	leaves [][]int32 // ug indices per leaf
	wg     sync.WaitGroup
	sem    chan struct{}
	statID int

	scratch sync.Pool // *splitScratch, one per concurrently-running split
}

// splitScratch gives a split call O(1)-reset window membership + local index
// without per-call full-size allocation: epoch[v]==cur marks membership.
type splitScratch struct {
	pos   []int32
	epoch []uint32
	cur   uint32
}

func (sc *splitScratch) nextEpoch() {
	sc.cur++
	if sc.cur == 0 { // wrapped: clear and restart
		for i := range sc.epoch {
			sc.epoch[i] = 0
		}
		sc.cur = 1
	}
}

// PartitionOverlay builds the nested partition of the drive overlay.
func PartitionOverlay(g *Graph, ov *Overlay, leafMax int, alpha float64) *Stage2Partition {
	start := time.Now()
	ug := buildDriveUG(g, ov)
	n := len(ug.overlayOf)
	log.Printf("stage2: partition input: %d drive junctions / %d undirected edges", n, len(ug.edgeTo)/2)

	p := &partitioner{
		ug:      ug,
		order:   make([]int32, n),
		pos:     make([]int32, n),
		leafMax: leafMax,
		alpha:   alpha,
		sem:     make(chan struct{}, max(1, runtime.NumCPU()-2)),
	}
	p.scratch.New = func() any {
		return &splitScratch{pos: make([]int32, n), epoch: make([]uint32, n)}
	}
	for i := range p.order {
		p.order[i] = int32(i)
		p.pos[i] = int32(i)
	}

	// Split into connected components first; partition each sizable one.
	comps := p.components()
	log.Printf("stage2: %d drive components (largest %d)", len(comps), comps[0].hi-comps[0].lo)
	for _, c := range comps {
		lo, hi := c.lo, c.hi
		p.wg.Add(1)
		go func() {
			defer p.wg.Done()
			p.bisect(lo, hi, 0, -1)
		}()
	}
	p.wg.Wait()

	out := &Stage2Partition{
		LeafOf:    make([]int32, ov.NodeCount()+1),
		LeafNodes: make([][]uint32, len(p.leaves)),
		Stats:     p.stats,
	}
	for i := range out.LeafOf {
		out.LeafOf[i] = -1
	}
	for leaf, nodes := range p.leaves {
		lst := make([]uint32, len(nodes))
		for i, u := range nodes {
			oi := ug.overlayOf[u]
			lst[i] = oi
			out.LeafOf[oi] = int32(leaf)
		}
		out.LeafNodes[leaf] = lst
	}
	log.Printf("stage2: partition done in %v: %d leaves", time.Since(start).Round(time.Millisecond), len(out.LeafNodes))
	return out
}

type crange struct{ lo, hi int32 }

// components reorders order[] so each connected component is contiguous, and
// returns the ranges sorted by size descending.
func (p *partitioner) components() []crange {
	n := len(p.order)
	compOf := make([]int32, n)
	for i := range compOf {
		compOf[i] = -1
	}
	var comps []crange
	var queue []int32
	next := int32(0)
	nc := int32(0)
	for s := 0; s < n; s++ {
		if compOf[s] >= 0 {
			continue
		}
		lo := next
		queue = queue[:0]
		queue = append(queue, int32(s))
		compOf[s] = nc
		for len(queue) > 0 {
			u := queue[len(queue)-1]
			queue = queue[:len(queue)-1]
			next++
			for _, v := range p.ug.edgeTo[p.ug.edgeStart[u]:p.ug.edgeStart[u+1]] {
				if compOf[v] < 0 {
					compOf[v] = nc
					queue = append(queue, v)
				}
			}
		}
		comps = append(comps, crange{lo, next})
		nc++
	}
	// Rebuild order grouped by component.
	fill := make([]int32, len(comps))
	newOrder := make([]int32, n)
	for u := 0; u < n; u++ {
		c := compOf[u]
		newOrder[comps[c].lo+fill[c]] = int32(u)
		fill[c]++
	}
	copy(p.order, newOrder)
	for i, u := range p.order {
		p.pos[u] = int32(i)
	}
	sort.Slice(comps, func(i, j int) bool { return comps[i].hi-comps[i].lo > comps[j].hi-comps[j].lo })
	return comps
}

// addLeaf registers order[lo:hi] as a leaf and returns its id.
func (p *partitioner) addLeaf(lo, hi int32) int {
	nodes := make([]int32, hi-lo)
	copy(nodes, p.order[lo:hi])
	p.mu.Lock()
	id := len(p.leaves)
	p.leaves = append(p.leaves, nodes)
	p.mu.Unlock()
	return id
}

// bisect splits order[lo:hi]; parent is the parent bisection id (-1 = root).
func (p *partitioner) bisect(lo, hi int32, depth, parent int) {
	size := int(hi - lo)
	if size <= p.leafMax {
		p.addLeaf(lo, hi)
		return
	}

	p.sem <- struct{}{}
	bstart := time.Now()
	mid, cut, axis, cutLat, cutLng := p.inertialFlowSplit(lo, hi)
	<-p.sem

	stat := BisectStat{
		Parent: parent, Depth: depth, Size: size,
		SizeA: int(mid - lo), SizeB: int(hi - mid),
		Cut: cut, Axis: axis, CutLat: cutLat, CutLng: cutLng,
		Millis: time.Since(bstart).Milliseconds(),
		LeafA:  -1, LeafB: -1,
		Balance: float64(min32(mid-lo, hi-mid)) / float64(size),
	}
	p.mu.Lock()
	stat.ID = p.statID
	p.statID++
	p.stats = append(p.stats, stat)
	sid := stat.ID
	p.mu.Unlock()

	setLeaf := func(field *int, id int) {
		p.mu.Lock()
		for i := range p.stats {
			if p.stats[i].ID == sid {
				if field == &stat.LeafA {
					p.stats[i].LeafA = id
				} else {
					p.stats[i].LeafB = id
				}
			}
		}
		p.mu.Unlock()
	}

	if int(mid-lo) <= p.leafMax {
		setLeaf(&stat.LeafA, p.addLeaf(lo, mid))
	} else {
		p.wg.Add(1)
		go func() {
			defer p.wg.Done()
			p.bisect(lo, mid, depth+1, sid)
		}()
	}
	if int(hi-mid) <= p.leafMax {
		setLeaf(&stat.LeafB, p.addLeaf(mid, hi))
	} else {
		p.wg.Add(1)
		go func() {
			defer p.wg.Done()
			p.bisect(mid, hi, depth+1, sid)
		}()
	}
}

// inertialFlowSplit bisects order[lo:hi] and returns the split point (the
// window is reordered so A = order[lo:mid], B = order[mid:hi]), the cut size,
// the winning axis and the mean cut-edge coordinates.
//
// inertialFlowSplit bisects order[lo:hi] and returns the split point (the
// window is reordered so A = order[lo:mid], B = order[mid:hi]), the cut size,
// the winning axis and the mean cut-edge coordinates.
//
// The four projection axes run CONCURRENTLY. Each axis CONTRACTS its extreme
// alpha fractions into a single super-source and super-sink — exact for
// Inertial Flow (edges inside a contracted set can never cross an s-t cut) —
// so the flow network holds only the middle band, roughly halving the arcs
// the big splits sweep. Dinic's BFS goes level-synchronous and parallel once
// the band is large; the blocking-flow phase stays serial per axis.
func (p *partitioner) inertialFlowSplit(lo, hi int32) (int32, int, int, float64, float64) {
	ug := p.ug
	sub := p.order[lo:hi]
	n := len(sub)

	sc := p.scratch.Get().(*splitScratch)
	defer p.scratch.Put(sc)
	sc.nextEpoch()
	for i, u := range sub {
		sc.pos[u] = int32(i)
		sc.epoch[u] = sc.cur
	}

	proj := func(axis int, u int32) float32 {
		switch axis {
		case 0:
			return ug.x[u]
		case 1:
			return ug.y[u]
		case 2:
			return ug.x[u] + ug.y[u]
		default:
			return ug.x[u] - ug.y[u]
		}
	}

	nsrc := int(float64(n) * p.alpha)
	if nsrc < 1 {
		nsrc = 1
	}
	if nsrc > n/3 {
		nsrc = n / 3
	}

	type axisResult struct {
		side    []bool // by window position (original order), true = source side
		cut     int
		aLen    int
		phases  int
		elapsed time.Duration
	}
	results := make([]axisResult, 4)
	var awg sync.WaitGroup
	for axis := 0; axis < 4; axis++ {
		awg.Add(1)
		go func(axis int) {
			defer awg.Done()
			astart := time.Now()
			// Axis ordering of window positions.
			locals := make([]int32, n)
			for i := range locals {
				locals[i] = int32(i)
			}
			sort.Slice(locals, func(a, b int) bool {
				return proj(axis, sub[locals[a]]) < proj(axis, sub[locals[b]])
			})
			// rank[windowPos] = position along the axis.
			rank := make([]int32, n)
			for r, li := range locals {
				rank[li] = int32(r)
			}
			side, cut, phases := p.bandedDinic(sub, sc, rank, int32(nsrc))
			aLen := 0
			for _, s := range side {
				if s {
					aLen++
				}
			}
			results[axis] = axisResult{side, cut, aLen, phases, time.Since(astart)}
		}(axis)
	}
	awg.Wait()

	best := 0
	for axis := 1; axis < 4; axis++ {
		r, b := results[axis], results[best]
		if r.cut < b.cut || (r.cut == b.cut && absInt(r.aLen*2-n) < absInt(b.aLen*2-n)) {
			best = axis
		}
	}
	bestR := results[best]
	if n > 500000 {
		log.Printf("stage2: split n=%d: axis %d wins cut=%d balance=%.2f (phases %d, %v; all cuts %d/%d/%d/%d)",
			n, best, bestR.cut, float64(min(bestR.aLen, n-bestR.aLen))/float64(n), bestR.phases, bestR.elapsed.Round(time.Millisecond),
			results[0].cut, results[1].cut, results[2].cut, results[3].cut)
	}

	// Apply: stable-partition the window into A (source side) then B.
	a := make([]int32, 0, bestR.aLen)
	b := make([]int32, 0, n-bestR.aLen)
	for i, u := range sub {
		if bestR.side[i] {
			a = append(a, u)
		} else {
			b = append(b, u)
		}
	}
	copy(sub, a)
	copy(sub[len(a):], b)
	for i, u := range sub {
		p.pos[u] = lo + int32(i)
	}
	mid := lo + int32(len(a))

	// Cut edge coordinate summary (estuary/urban characterisation).
	var cLat, cLng float64
	cn := 0
	for _, u := range sub {
		ua := p.pos[u] < mid
		for _, v := range ug.edgeTo[ug.edgeStart[u]:ug.edgeStart[u+1]] {
			if sc.epoch[v] != sc.cur {
				continue
			}
			va := p.pos[v] < mid
			if ua != va && u < v {
				cLat += float64(ug.y[u]) / 111.2
				cLng += float64(ug.x[u]) / (111.2 * math.Cos(54*math.Pi/180))
				cn++
			}
		}
	}
	if cn > 0 {
		cLat /= float64(cn)
		cLng /= float64(cn)
	}
	return mid, bestR.cut, best, cLat, cLng
}

// bandedDinic computes the max-flow / min-cut between the first nsrc and last
// nsrc window positions along an axis (given by rank), with both extreme sets
// CONTRACTED into super nodes: the flow network holds only the middle band.
// Returns the cut side per WINDOW POSITION (true = source side), the cut
// value, and the phase count.
func (p *partitioner) bandedDinic(sub []int32, sc *splitScratch, rank []int32, nsrc int32) ([]bool, int, int) {
	ug := p.ug
	n := int32(len(sub))
	loBand, hiBand := nsrc, n-nsrc // ranks [loBand, hiBand) are the band

	// Band numbering by window position.
	bandIdx := make([]int32, n)
	for i := range bandIdx {
		bandIdx[i] = -1
	}
	bcount := int32(0)
	for li := int32(0); li < n; li++ {
		if r := rank[li]; r >= loBand && r < hiBand {
			bandIdx[li] = bcount
			bcount++
		}
	}
	S, T := bcount, bcount+1

	// Arc build: band-band edges once (lu<lv), band-source/sink edges from the
	// band side, and rare direct source-sink edges from the source side.
	deg := make([]int32, bcount+2)
	countArc := func(u, v int32) {
		deg[u]++
		deg[v]++
	}
	for li := int32(0); li < n; li++ {
		u := sub[li]
		ru := rank[li]
		uBand := ru >= loBand && ru < hiBand
		uSrc := ru < loBand
		for _, v := range ug.edgeTo[ug.edgeStart[u]:ug.edgeStart[u+1]] {
			if sc.epoch[v] != sc.cur {
				continue
			}
			lv := sc.pos[v]
			rv := rank[lv]
			vBand := rv >= loBand && rv < hiBand
			switch {
			case uBand && vBand:
				if li < lv {
					countArc(bandIdx[li], bandIdx[lv])
				}
			case uBand && !vBand:
				if rv < loBand {
					countArc(S, bandIdx[li])
				} else {
					countArc(bandIdx[li], T)
				}
			case uSrc && rv >= hiBand:
				countArc(S, T)
			}
		}
	}
	csrStart := make([]int32, bcount+3)
	for i := int32(0); i < bcount+2; i++ {
		csrStart[i+1] = csrStart[i] + deg[i]
	}
	totArcs := csrStart[bcount+2]
	csrArc := make([]int32, totArcs)
	arcTo := make([]int32, totArcs)
	caps := make([]int8, totArcs)
	fill := make([]int32, bcount+2)
	arcN := int32(0)
	addArc := func(u, v int32) {
		a0, a1 := arcN, arcN+1
		arcN += 2
		arcTo[a0] = v
		arcTo[a1] = u
		caps[a0] = 1
		caps[a1] = 1
		csrArc[csrStart[u]+fill[u]] = a0
		fill[u]++
		csrArc[csrStart[v]+fill[v]] = a1
		fill[v]++
	}
	for li := int32(0); li < n; li++ {
		u := sub[li]
		ru := rank[li]
		uBand := ru >= loBand && ru < hiBand
		uSrc := ru < loBand
		for _, v := range ug.edgeTo[ug.edgeStart[u]:ug.edgeStart[u+1]] {
			if sc.epoch[v] != sc.cur {
				continue
			}
			lv := sc.pos[v]
			rv := rank[lv]
			vBand := rv >= loBand && rv < hiBand
			switch {
			case uBand && vBand:
				if li < lv {
					addArc(bandIdx[li], bandIdx[lv])
				}
			case uBand && !vBand:
				if rv < loBand {
					addArc(S, bandIdx[li])
				} else {
					addArc(bandIdx[li], T)
				}
			case uSrc && rv >= hiBand:
				addArc(S, T)
			}
		}
	}

	side, cut, phases := dinicSuperST(int(bcount), csrStart, csrArc, arcTo, caps)

	// Expand to window positions: sources true, sinks false, band by residual.
	out := make([]bool, n)
	for li := int32(0); li < n; li++ {
		r := rank[li]
		switch {
		case r < loBand:
			out[li] = true
		case r >= hiBand:
			out[li] = false
		default:
			out[li] = side[bandIdx[li]]
		}
	}
	return out, cut, phases
}

// dinicSuperST runs unit-capacity Dinic between super source b and super sink
// b+1 over the banded arc network (arc pairs at i, i^1). BFS is
// level-synchronous, and parallel once the band is large. Returns the residual
// source side over band nodes, the flow value and the phase count.
func dinicSuperST(b int, csrStart, csrArc, arcTo []int32, caps []int8) ([]bool, int, int) {
	S, T := int32(b), int32(b+1)
	level := make([]int32, b+2)
	iter := make([]int32, b+2)

	parallel := b > 400_000
	workers := 1
	if parallel {
		workers = max(2, runtime.NumCPU()/4)
	}

	bfs := func() bool {
		for i := range level {
			level[i] = -1
		}
		level[S] = 0
		frontier := []int32{S}
		depth := int32(0)
		for len(frontier) > 0 {
			if level[T] >= 0 {
				return true // sink reached in a previous round: levels final enough
			}
			depth++
			if !parallel || len(frontier) < 4096 {
				var next []int32
				for _, u := range frontier {
					if u == T {
						continue // paths end at the sink
					}
					for pp := csrStart[u]; pp < csrStart[u+1]; pp++ {
						ai := csrArc[pp]
						if caps[ai] > 0 {
							v := arcTo[ai]
							if level[v] < 0 {
								level[v] = depth
								next = append(next, v)
							}
						}
					}
				}
				frontier = next
				continue
			}
			nexts := make([][]int32, workers)
			var fwg sync.WaitGroup
			chunk := (len(frontier) + workers - 1) / workers
			for w := 0; w < workers; w++ {
				lo := w * chunk
				if lo >= len(frontier) {
					break
				}
				hi := min(lo+chunk, len(frontier))
				fwg.Add(1)
				go func(w, lo, hi int) {
					defer fwg.Done()
					var local []int32
					for _, u := range frontier[lo:hi] {
						if u == T {
							continue // paths end at the sink
						}
						for pp := csrStart[u]; pp < csrStart[u+1]; pp++ {
							ai := csrArc[pp]
							if caps[ai] > 0 {
								v := arcTo[ai]
								if atomic.CompareAndSwapInt32(&level[v], -1, depth) {
									local = append(local, v)
								}
							}
						}
					}
					nexts[w] = local
				}(w, lo, hi)
			}
			fwg.Wait()
			var next []int32
			for _, l := range nexts {
				next = append(next, l...)
			}
			frontier = next
		}
		return level[T] >= 0
	}

	var dfs func(u int32) bool
	dfs = func(u int32) bool {
		if u == T {
			return true
		}
		for ; iter[u] < csrStart[u+1]-csrStart[u]; iter[u]++ {
			ai := csrArc[csrStart[u]+iter[u]]
			if caps[ai] <= 0 {
				continue
			}
			v := arcTo[ai]
			if level[v] != level[u]+1 {
				continue
			}
			if dfs(v) {
				caps[ai]--
				caps[ai^1]++
				return true
			}
		}
		return false
	}

	flow, phases := 0, 0
	for bfs() {
		phases++
		for i := range iter {
			iter[i] = 0
		}
		for dfs(S) {
			flow++
		}
	}

	// Residual reachability from S = cut side A.
	side := make([]bool, b)
	seen := make([]bool, b+2)
	queue := []int32{S}
	seen[S] = true
	for qi := 0; qi < len(queue); qi++ {
		u := queue[qi]
		for pp := csrStart[u]; pp < csrStart[u+1]; pp++ {
			ai := csrArc[pp]
			if caps[ai] > 0 {
				v := arcTo[ai]
				if !seen[v] {
					seen[v] = true
					if v < int32(b) {
						side[v] = true
					}
					queue = append(queue, v)
				}
			}
		}
	}
	return side, flow, phases
}

func absInt(v int) int {
	if v < 0 {
		return -v
	}
	return v
}

func min32(a, b int32) int32 {
	if a < b {
		return a
	}
	return b
}

// stage2PartitionRun is the CLI entry: builds (or loads) the partition and
// dumps measurement stats.
func stage2PartitionRun(leafMax int, alpha float64) {
	g, ov := stage2LoadOrBuild()
	part := PartitionOverlay(g, ov, leafMax, alpha)

	// Leaf size distribution.
	sizes := make([]int, len(part.LeafNodes))
	for i, l := range part.LeafNodes {
		sizes[i] = len(l)
	}
	sort.Ints(sizes)
	pct := func(p float64) int {
		if len(sizes) == 0 {
			return 0
		}
		return sizes[int(p*float64(len(sizes)-1))]
	}
	var cuts []int
	maxDepth := 0
	for _, s := range part.Stats {
		if !s.Comp {
			cuts = append(cuts, s.Cut)
		}
		if s.Depth > maxDepth {
			maxDepth = s.Depth
		}
	}
	sort.Ints(cuts)
	cpct := func(p float64) int {
		if len(cuts) == 0 {
			return 0
		}
		return cuts[int(p*float64(len(cuts)-1))]
	}
	fmt.Printf("stage2 partition: %d leaves, leaf size p10/p50/p90/max = %d/%d/%d/%d, depth max %d\n",
		len(part.LeafNodes), pct(0.10), pct(0.50), pct(0.90), pct(1.0), maxDepth)
	fmt.Printf("stage2 partition: bisection cut p50/p90/max = %d/%d/%d over %d bisections\n",
		cpct(0.50), cpct(0.90), cpct(1.0), len(cuts))

	if err := os.MkdirAll("data/stage2", 0o755); err != nil {
		log.Fatal(err)
	}
	if err := savePartition("data/stage2/partition.snap", part); err != nil {
		log.Fatalf("stage2: save partition: %v", err)
	}
	f, err := os.Create("data/stage2/partition-stats.json")
	if err != nil {
		log.Fatal(err)
	}
	defer f.Close()
	enc := json.NewEncoder(f)
	enc.SetIndent("", " ")
	if err := enc.Encode(part.Stats); err != nil {
		log.Fatal(err)
	}
	log.Printf("stage2: stats written to data/stage2/partition-stats.json")
}

const partitionMagic = "FRGP1SNAP" // versioned independently of the graph snapshot

// savePartition / loadPartition use the same raw-slice format as the graph snapshot.
func savePartition(path string, part *Stage2Partition) error {
	f, err := os.Create(path)
	if err != nil {
		return err
	}
	defer f.Close()
	// Flatten leaves.
	var flat []uint32
	offs := make([]int64, len(part.LeafNodes)+1)
	for i, l := range part.LeafNodes {
		flat = append(flat, l...)
		offs[i+1] = int64(len(flat))
	}
	w := f
	if _, err := w.WriteString(partitionMagic); err != nil {
		return err
	}
	if err := writeSlice(w, part.LeafOf); err != nil {
		return err
	}
	if err := writeSlice(w, offs); err != nil {
		return err
	}
	if err := writeSlice(w, flat); err != nil {
		return err
	}
	blob, err := json.Marshal(part.Stats)
	if err != nil {
		return err
	}
	return writeSlice(w, blob)
}

func loadPartition(path string) (*Stage2Partition, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	magic := make([]byte, len(partitionMagic))
	if _, err := f.Read(magic); err != nil {
		return nil, err
	}
	if string(magic) != partitionMagic {
		return nil, fmt.Errorf("partition artifact version mismatch (got %q)", magic)
	}
	part := &Stage2Partition{}
	if part.LeafOf, err = readSlice[int32](f); err != nil {
		return nil, err
	}
	var offs []int64
	if offs, err = readSlice[int64](f); err != nil {
		return nil, err
	}
	var flat []uint32
	if flat, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	part.LeafNodes = make([][]uint32, len(offs)-1)
	for i := 0; i+1 < len(offs); i++ {
		part.LeafNodes[i] = flat[offs[i]:offs[i+1]]
	}
	var blob []byte
	if blob, err = readSlice[byte](f); err != nil {
		return nil, err
	}
	if err := json.Unmarshal(blob, &part.Stats); err != nil {
		return nil, err
	}
	return part, nil
}
