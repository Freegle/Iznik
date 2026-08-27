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
func (p *partitioner) inertialFlowSplit(lo, hi int32) (int32, int, int, float64, float64) {
	ug := p.ug
	sub := p.order[lo:hi]
	n := len(sub)

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

	// Window membership + local index via pooled epoch-stamped scratch,
	// private to this call while held (sem bounds concurrent splits).
	sc := p.scratch.Get().(*splitScratch)
	defer p.scratch.Put(sc)
	sc.nextEpoch()
	for i, u := range sub {
		sc.pos[u] = int32(i)
		sc.epoch[u] = sc.cur
	}

	bestCut := math.MaxInt
	bestAxis := -1
	// Winning axis capture: node ordering + positional side flags.
	bestNodes := make([]int32, 0, n)
	var bestSide []bool

	nsrc := int(float64(n) * p.alpha)
	if nsrc < 1 {
		nsrc = 1
	}

	// A per-axis node ordering, independent of the shared order slice.
	axisNodes := make([]int32, n)
	copy(axisNodes, sub)

	for axis := 0; axis < 4; axis++ {
		sort.Slice(axisNodes, func(i, j int) bool { return proj(axis, axisNodes[i]) < proj(axis, axisNodes[j]) })
		for i, u := range axisNodes {
			sc.pos[u] = int32(i)
		}
		side, cut := p.dinicCut(axisNodes, sc, nsrc)
		if cut < bestCut {
			bestCut = cut
			bestAxis = axis
			bestNodes = append(bestNodes[:0], axisNodes...)
			bestSide = side
		}
	}

	// Apply the winning side: stable-partition the shared window into A then B
	// (in original window order) and refresh pos for our own nodes only.
	inA := func(u int32) bool { return false }
	{
		// Mark A-side nodes using sc.pos as a positional side lookup for the
		// winning ordering.
		for i, u := range bestNodes {
			sc.pos[u] = int32(i)
		}
		inA = func(u int32) bool { return bestSide[sc.pos[u]] }
	}
	a := make([]int32, 0, n)
	b := make([]int32, 0, n)
	for _, u := range sub {
		if inA(u) {
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

	// Cut edge coordinate summary (for estuary/urban characterisation).
	var cLat, cLng float64
	cn := 0
	for _, u := range sub {
		ua := inA(u)
		for _, v := range ug.edgeTo[ug.edgeStart[u]:ug.edgeStart[u+1]] {
			if sc.epoch[v] != sc.cur {
				continue
			}
			va := inA(v)
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
	return mid, bestCut, bestAxis, cLat, cLng
}

// dinicCut runs unit-capacity Dinic between the first nsrc (sources) and last
// nsrc (sinks) of nodes (in projection order), returning the min-cut side
// assignment indexed by position in nodes (true = source side) and the cut
// value. sc carries window membership (epoch) and local positions.
func (p *partitioner) dinicCut(nodes []int32, sc *splitScratch, nsrc int) ([]bool, int) {
	ug := p.ug
	sub := nodes
	n := len(sub)
	S, T := int32(n), int32(n+1) // virtual super source/sink

	// Build local arc lists. Arc pairs are adjacent (i, i^1).
	type arc struct {
		to  int32
		cap int32
	}
	// Estimate arcs: internal edges *2 + 2*nsrc.
	arcs := make([]arc, 0, 4*n)
	head := make([][]int32, n+2)
	addArc := func(u, v int32, c int32) {
		head[u] = append(head[u], int32(len(arcs)))
		arcs = append(arcs, arc{v, c})
		head[v] = append(head[v], int32(len(arcs)))
		arcs = append(arcs, arc{u, c}) // undirected: both directions capacity c
	}
	for i, u := range sub {
		for _, v := range ug.edgeTo[ug.edgeStart[u]:ug.edgeStart[u+1]] {
			if sc.epoch[v] != sc.cur {
				continue
			}
			lv := sc.pos[v]
			if int32(i) < lv { // add each undirected edge once
				addArc(int32(i), lv, 1)
			}
		}
	}
	const inf = int32(1 << 30)
	for i := 0; i < nsrc; i++ {
		addArc(S, int32(i), inf)
		// Note: reverse arc S<-node gets cap inf too; harmless (S has no in-arcs used).
	}
	for i := n - nsrc; i < n; i++ {
		addArc(int32(i), T, inf)
	}

	level := make([]int32, n+2)
	iter := make([]int32, n+2)
	queue := make([]int32, 0, n+2)

	bfs := func() bool {
		for i := range level {
			level[i] = -1
		}
		queue = queue[:0]
		queue = append(queue, S)
		level[S] = 0
		for qi := 0; qi < len(queue); qi++ {
			u := queue[qi]
			for _, ai := range head[u] {
				a := arcs[ai]
				if a.cap > 0 && level[a.to] < 0 {
					level[a.to] = level[u] + 1
					queue = append(queue, a.to)
				}
			}
		}
		return level[T] >= 0
	}

	var dfs func(u int32, f int32) int32
	dfs = func(u int32, f int32) int32 {
		if u == T {
			return f
		}
		for ; iter[u] < int32(len(head[u])); iter[u]++ {
			ai := head[u][iter[u]]
			a := &arcs[ai]
			if a.cap > 0 && level[a.to] == level[u]+1 {
				d := dfs(a.to, min32(f, a.cap))
				if d > 0 {
					a.cap -= d
					arcs[ai^1].cap += d
					return d
				}
			}
		}
		return 0
	}

	flow := 0
	for bfs() {
		for i := range iter {
			iter[i] = 0
		}
		for {
			f := dfs(S, inf)
			if f == 0 {
				break
			}
			flow += int(f)
		}
	}

	// Min-cut side: nodes reachable from S in the residual graph.
	sideLocal := make([]bool, n)
	for i := range level {
		level[i] = -1
	}
	queue = queue[:0]
	queue = append(queue, S)
	level[S] = 0
	for qi := 0; qi < len(queue); qi++ {
		u := queue[qi]
		for _, ai := range head[u] {
			a := arcs[ai]
			if a.cap > 0 && level[a.to] < 0 {
				level[a.to] = 0
				queue = append(queue, a.to)
			}
		}
	}
	for i := 0; i < n; i++ {
		if level[i] == 0 {
			sideLocal[i] = true
		}
	}
	return sideLocal, flow
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
	if _, err := w.WriteString(stage2SnapMagic); err != nil {
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
	magic := make([]byte, len(stage2SnapMagic))
	if _, err := f.Read(magic); err != nil {
		return nil, err
	}
	if string(magic) != stage2SnapMagic {
		return nil, fmt.Errorf("bad partition magic")
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
