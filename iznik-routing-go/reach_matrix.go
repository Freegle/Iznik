package main

// Reach engine region matrices: for every leaf region, the directed boundary
// (entries = nodes with a drive edge in from another leaf; exits = nodes with
// a drive edge out to another leaf), the entry×exit through-time matrix over
// region-INTERNAL drive edges, and a per-entry eccentricity for the
// conservative fully-in test. Ecc is +Inf unless the entry internally reaches
// EVERY drive node of the region — required for soundness: fully-in via
// min_e(arrival_e + ecc_e) <= T must guarantee every node is within budget.
//
// Composition over these matrices is exact (CRP): any shortest path's final
// segment after its last boundary crossing is region-internal, and boundary
// arrivals are computed globally, so min over entries(arrival + internal
// distance) reproduces the flat Dijkstra answer to the float32 bit or near it.

import (
	"container/heap"
	"fmt"
	"log"
	"math"
	"os"
	"runtime"
	"sort"
	"sync"
	"time"
)

// RegionMatrices is the per-leaf boundary artifact plus the global cross-edge
// list (the boundary graph's inter-region edges).
type RegionMatrices struct {
	// Per leaf slices, indexed by leaf id via the offset arrays.
	EntryOff []int32  // len = leaves+1
	ExitOff  []int32  // len = leaves+1
	Entries  []uint32 // overlay indices
	Exits    []uint32
	// Boundary = sorted union of entries and exits. The matrix must span
	// entry -> EVERY boundary node (not just exits): a shortest path can
	// reach a sibling ENTRY internally (found by the Southend parity
	// divergence), and those arrivals both feed the stored labels and relay
	// onward through cross edges.
	BndOff []int32
	Bnd    []uint32
	MatOff []int64   // len = leaves+1; Mat[MatOff[l]:] is entries×boundary row-major
	Mat    []float32 // seconds; +Inf = internally unreachable
	MatM   []float32 // road metres along the time-optimal internal path
	Ecc    []float32 // parallel to Entries; +Inf = entry does not cover region

	// Cross edges between leaves (drive): from exit to entry with chain secs
	// and road metres.
	CrossFrom []uint32
	CrossTo   []uint32
	CrossSecs []float32
	CrossMet  []float32
}

func (rm *RegionMatrices) LeafEntries(l int32) []uint32 {
	return rm.Entries[rm.EntryOff[l]:rm.EntryOff[l+1]]
}
func (rm *RegionMatrices) LeafExits(l int32) []uint32 {
	return rm.Exits[rm.ExitOff[l]:rm.ExitOff[l+1]]
}
func (rm *RegionMatrices) LeafBoundary(l int32) []uint32 {
	return rm.Bnd[rm.BndOff[l]:rm.BndOff[l+1]]
}
func (rm *RegionMatrices) LeafEcc(l int32) []float32 {
	return rm.Ecc[rm.EntryOff[l]:rm.EntryOff[l+1]]
}

// MatAt returns the internal entry→boundary seconds for leaf l.
func (rm *RegionMatrices) MatAt(l int32, entryIdx, bndIdx int) float32 {
	nx := int(rm.BndOff[l+1] - rm.BndOff[l])
	return rm.Mat[rm.MatOff[l]+int64(entryIdx*nx+bndIdx)]
}

var f32Inf = float32(math.Inf(1))

// regionDijkstra runs a Dijkstra from src over the drive edges of one leaf
// (edges whose endpoints both have LeafOf == leaf), writing arrivals into
// dist (local indices via localOf). Reused buffers are the caller's.
type miniHeapItem struct {
	li int32
	c  float32
}
type miniHeap []miniHeapItem

func (h miniHeap) Len() int            { return len(h) }
func (h miniHeap) Less(i, j int) bool  { return h[i].c < h[j].c }
func (h miniHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *miniHeap) Push(x interface{}) { *h = append(*h, x.(miniHeapItem)) }
func (h *miniHeap) Pop() interface{} {
	old := *h
	n := len(old)
	it := old[n-1]
	*h = old[:n-1]
	return it
}

// leafSubgraph is a region-internal CSR built once per leaf and shared by all
// of the leaf's entry Dijkstras.
type leafSubgraph struct {
	nodes   []uint32 // overlay indices, local index = position
	localOf map[uint32]int32
	start   []int32
	to      []int32
	secs    []float32
	mets    []float32 // road metres of the chain edge (same semantics as DistM)
}

func buildLeafSubgraph(ov *Overlay, part *ReachPartition, leaf int32) *leafSubgraph {
	nodes := part.LeafNodes[leaf]
	ls := &leafSubgraph{nodes: nodes, localOf: make(map[uint32]int32, len(nodes))}
	for i, oi := range nodes {
		ls.localOf[oi] = int32(i)
	}
	deg := make([]int32, len(nodes)+1)
	for i, oi := range nodes {
		for _, e := range ov.EdgesFrom(oi) {
			if e.Seconds[Drive] < 0 || e.To == oi {
				continue
			}
			if _, in := ls.localOf[e.To]; in {
				deg[i+1]++
			}
		}
	}
	for i := 0; i < len(nodes); i++ {
		deg[i+1] += deg[i]
	}
	ls.start = deg
	total := deg[len(nodes)]
	ls.to = make([]int32, total)
	ls.secs = make([]float32, total)
	ls.mets = make([]float32, total)
	fill := make([]int32, len(nodes))
	for i, oi := range nodes {
		for _, e := range ov.EdgesFrom(oi) {
			if e.Seconds[Drive] < 0 || e.To == oi {
				continue
			}
			if lv, in := ls.localOf[e.To]; in {
				p := ls.start[i] + fill[i]
				ls.to[p] = lv
				ls.secs[p] = e.Seconds[Drive]
				ls.mets[p] = e.Metres
				fill[i]++
			}
		}
	}
	return ls
}

// dijkstraFrom fills dist (len == len(ls.nodes)) with internal arrivals from
// local source li (seed 0), +Inf where unreachable. When met is non-nil it is
// filled with the road metres along the time-optimal path (DistM semantics).
func (ls *leafSubgraph) dijkstraFrom(li int32, dist []float32) {
	ls.dijkstraFromM(li, dist, nil)
}

func (ls *leafSubgraph) dijkstraFromM(li int32, dist, met []float32) {
	for i := range dist {
		dist[i] = f32Inf
	}
	if met != nil {
		for i := range met {
			met[i] = f32Inf
		}
		met[li] = 0
	}
	dist[li] = 0
	h := &miniHeap{{li, 0}}
	for h.Len() > 0 {
		cur := heap.Pop(h).(miniHeapItem)
		if cur.c > dist[cur.li] {
			continue
		}
		for p := ls.start[cur.li]; p < ls.start[cur.li+1]; p++ {
			nc := cur.c + ls.secs[p]
			v := ls.to[p]
			if nc < dist[v] {
				dist[v] = nc
				if met != nil {
					met[v] = met[cur.li] + ls.mets[p]
				}
				heap.Push(h, miniHeapItem{v, nc})
			}
		}
	}
}

// BuildRegionMatrices computes boundaries, matrices and eccentricities.
func BuildRegionMatrices(ov *Overlay, part *ReachPartition) *RegionMatrices {
	start := time.Now()
	nLeaves := len(part.LeafNodes)
	rm := &RegionMatrices{
		EntryOff: make([]int32, nLeaves+1),
		ExitOff:  make([]int32, nLeaves+1),
		BndOff:   make([]int32, nLeaves+1),
		MatOff:   make([]int64, nLeaves+1),
	}

	// Cross edges + per-leaf entry/exit sets.
	entrySets := make([]map[uint32]struct{}, nLeaves)
	exitSets := make([]map[uint32]struct{}, nLeaves)
	for i := range entrySets {
		entrySets[i] = map[uint32]struct{}{}
		exitSets[i] = map[uint32]struct{}{}
	}
	for oi := uint32(1); oi <= uint32(ov.NodeCount()); oi++ {
		lf := part.LeafOf[oi]
		if lf < 0 {
			continue
		}
		for _, e := range ov.EdgesFrom(oi) {
			if e.Seconds[Drive] < 0 || e.To == oi {
				continue
			}
			lt := part.LeafOf[e.To]
			if lt < 0 || lt == lf {
				continue
			}
			rm.CrossFrom = append(rm.CrossFrom, oi)
			rm.CrossTo = append(rm.CrossTo, e.To)
			rm.CrossSecs = append(rm.CrossSecs, e.Seconds[Drive])
			rm.CrossMet = append(rm.CrossMet, e.Metres)
			exitSets[lf][oi] = struct{}{}
			entrySets[lt][e.To] = struct{}{}
		}
	}
	for l := 0; l < nLeaves; l++ {
		ent := make([]uint32, 0, len(entrySets[l]))
		for oi := range entrySets[l] {
			ent = append(ent, oi)
		}
		sort.Slice(ent, func(i, j int) bool { return ent[i] < ent[j] })
		ext := make([]uint32, 0, len(exitSets[l]))
		for oi := range exitSets[l] {
			ext = append(ext, oi)
		}
		sort.Slice(ext, func(i, j int) bool { return ext[i] < ext[j] })
		bndSet := make(map[uint32]struct{}, len(ent)+len(ext))
		for _, oi := range ent {
			bndSet[oi] = struct{}{}
		}
		for _, oi := range ext {
			bndSet[oi] = struct{}{}
		}
		bnd := make([]uint32, 0, len(bndSet))
		for oi := range bndSet {
			bnd = append(bnd, oi)
		}
		sort.Slice(bnd, func(i, j int) bool { return bnd[i] < bnd[j] })
		rm.EntryOff[l+1] = rm.EntryOff[l] + int32(len(ent))
		rm.ExitOff[l+1] = rm.ExitOff[l] + int32(len(ext))
		rm.BndOff[l+1] = rm.BndOff[l] + int32(len(bnd))
		rm.Entries = append(rm.Entries, ent...)
		rm.Exits = append(rm.Exits, ext...)
		rm.Bnd = append(rm.Bnd, bnd...)
		rm.MatOff[l+1] = rm.MatOff[l] + int64(len(ent)*len(bnd))
	}
	rm.Mat = make([]float32, rm.MatOff[nLeaves])
	rm.MatM = make([]float32, rm.MatOff[nLeaves])
	rm.Ecc = make([]float32, len(rm.Entries))

	// Per-leaf entry Dijkstras, parallel across leaves.
	var wg sync.WaitGroup
	sem := make(chan struct{}, max(1, runtime.NumCPU()-2))
	for l := 0; l < nLeaves; l++ {
		wg.Add(1)
		sem <- struct{}{}
		go func(l int) {
			defer wg.Done()
			defer func() { <-sem }()
			ls := buildLeafSubgraph(ov, part, int32(l))
			ents := rm.LeafEntries(int32(l))
			bnd := rm.LeafBoundary(int32(l))
			dist := make([]float32, len(ls.nodes))
			metd := make([]float32, len(ls.nodes))
			nx := len(bnd)
			for ei, ent := range ents {
				ls.dijkstraFromM(ls.localOf[ent], dist, metd)
				ecc := float32(0)
				for _, d := range dist {
					if d > ecc {
						ecc = d // +Inf propagates when any node is uncovered
					}
				}
				rm.Ecc[int(rm.EntryOff[l])+ei] = ecc
				base := rm.MatOff[l] + int64(ei*nx)
				for xi, ex := range bnd {
					rm.Mat[base+int64(xi)] = dist[ls.localOf[ex]]
					rm.MatM[base+int64(xi)] = metd[ls.localOf[ex]]
				}
			}
		}(l)
	}
	wg.Wait()

	log.Printf("reach: matrices built in %v: %d entries / %d exits / %d matrix cells / %d cross edges over %d leaves",
		time.Since(start).Round(time.Millisecond), len(rm.Entries), len(rm.Exits), len(rm.Mat), len(rm.CrossFrom), nLeaves)
	return rm
}

// saveMatrices / loadMatrices: raw-slice artifact like the graph snapshot.
const matricesMagic = "FRGM2SNAP" // v2: adds MatM (road metres)

func saveMatrices(path string, rm *RegionMatrices) error {
	f, err := os.Create(path)
	if err != nil {
		return err
	}
	defer f.Close()
	if _, err := f.WriteString(matricesMagic); err != nil {
		return err
	}
	for _, s := range [][]int32{rm.EntryOff, rm.ExitOff, rm.BndOff} {
		if err := writeSlice(f, s); err != nil {
			return err
		}
	}
	if err := writeSlice(f, rm.Entries); err != nil {
		return err
	}
	if err := writeSlice(f, rm.Exits); err != nil {
		return err
	}
	if err := writeSlice(f, rm.Bnd); err != nil {
		return err
	}
	if err := writeSlice(f, rm.MatOff); err != nil {
		return err
	}
	if err := writeSlice(f, rm.Mat); err != nil {
		return err
	}
	if err := writeSlice(f, rm.MatM); err != nil {
		return err
	}
	if err := writeSlice(f, rm.Ecc); err != nil {
		return err
	}
	if err := writeSlice(f, rm.CrossFrom); err != nil {
		return err
	}
	if err := writeSlice(f, rm.CrossTo); err != nil {
		return err
	}
	if err := writeSlice(f, rm.CrossSecs); err != nil {
		return err
	}
	return writeSlice(f, rm.CrossMet)
}

func loadMatrices(path string) (*RegionMatrices, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	magic := make([]byte, len(matricesMagic))
	if _, err := f.Read(magic); err != nil {
		return nil, err
	}
	if string(magic) != matricesMagic {
		return nil, fmt.Errorf("matrices artifact version mismatch (got %q)", magic)
	}
	rm := &RegionMatrices{}
	if rm.EntryOff, err = readSlice[int32](f); err != nil {
		return nil, err
	}
	if rm.ExitOff, err = readSlice[int32](f); err != nil {
		return nil, err
	}
	if rm.BndOff, err = readSlice[int32](f); err != nil {
		return nil, err
	}
	if rm.Entries, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	if rm.Exits, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	if rm.Bnd, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	if rm.MatOff, err = readSlice[int64](f); err != nil {
		return nil, err
	}
	if rm.Mat, err = readSlice[float32](f); err != nil {
		return nil, err
	}
	if rm.MatM, err = readSlice[float32](f); err != nil {
		return nil, err
	}
	if rm.Ecc, err = readSlice[float32](f); err != nil {
		return nil, err
	}
	if rm.CrossFrom, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	if rm.CrossTo, err = readSlice[uint32](f); err != nil {
		return nil, err
	}
	if rm.CrossSecs, err = readSlice[float32](f); err != nil {
		return nil, err
	}
	if rm.CrossMet, err = readSlice[float32](f); err != nil {
		return nil, err
	}
	return rm, nil
}
