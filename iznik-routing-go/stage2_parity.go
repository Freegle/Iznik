package main

// Stage 2 parity harness: compare the labeling engine against (a) a flat
// base-graph Dijkstra — must be EXACT — and (b) real prod polygon_cells blobs
// exported over the read-only tunnel — expected to differ only in the two
// structural projection classes:
//   fill:      the stored grid fills polygon interiors (gardens, fields, the
//              unbridged far bank); the graph metric has no road there within
//              budget. Includes the water-bank cases stage 2 exists to fix.
//   boundary:  ±1-cell quantization at the isochrone frontier (arrival within
//              a small margin of T, or the point sits within a cell of the
//              stored area's edge).
// Anything else is "structural" and gets logged with coordinates for manual
// inspection. Prod rows are selected with rejected_groups IS NULL, but
// polygon_cells still includes the origin-group sliver fill
// (unionWithOriginGroupArea), which shows up as fill/structural near the
// origin group's edge — see the run report.

import (
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

type prodPost struct {
	Msgid      int64   `json:"msgid"`
	Lat        float64 `json:"lat"`
	Lng        float64 `json:"lng"`
	Tick       int     `json:"tick"`
	TotalTicks int     `json:"total_ticks"`
	Band       string  `json:"density_band"`
	Schedule   []struct {
		Tick     int     `json:"tick"`
		DriveMin float64 `json:"drive_min"`
	} `json:"schedule"`
	cells []byte
}

func loadProdPosts(dir string) ([]*prodPost, error) {
	metas, err := filepath.Glob(filepath.Join(dir, "*.json"))
	if err != nil {
		return nil, err
	}
	var out []*prodPost
	for _, m := range metas {
		raw, err := os.ReadFile(m)
		if err != nil {
			return nil, err
		}
		p := &prodPost{}
		if err := json.Unmarshal(raw, p); err != nil {
			return nil, fmt.Errorf("%s: %w", m, err)
		}
		hexPath := strings.TrimSuffix(m, ".json") + ".cells.hex"
		hraw, err := os.ReadFile(hexPath)
		if err != nil {
			return nil, err
		}
		p.cells, err = hex.DecodeString(strings.TrimSpace(string(hraw)))
		if err != nil {
			return nil, fmt.Errorf("%s: %w", hexPath, err)
		}
		out = append(out, p)
	}
	return out, nil
}

// driveMinForTick mirrors the batch's entryForTick: the schedule entry with
// the highest tick <= the row's tick.
func (p *prodPost) driveMinForTick() (float64, bool) {
	best, ok := 0.0, false
	bestTick := -1
	for _, e := range p.Schedule {
		if e.Tick <= p.Tick && e.Tick > bestTick {
			bestTick = e.Tick
			best = e.DriveMin
			ok = true
		}
	}
	return best, ok
}

func stage2ParityRun(dir string, engine *Stage2Engine) {
	posts, err := loadProdPosts(dir)
	if err != nil {
		log.Fatalf("stage2 parity: %v", err)
	}
	if len(posts) == 0 {
		log.Fatalf("stage2 parity: no posts in %s (run scripts/stage2-fetch-prod.sh)", dir)
	}
	g := engine.G

	type agg struct {
		samples, agree, fill, boundary, structural int
		exactChecked, exactMismatch                int
	}
	var total agg

	for _, p := range posts {
		driveMin, ok := p.driveMinForTick()
		if !ok {
			log.Printf("post %d: no schedule entry for tick %d — skipped", p.Msgid, p.Tick)
			continue
		}
		T := float32(driveMin * 60)

		qStart := time.Now()
		lbl := engine.QueryLabels(p.Lat, p.Lng, T)
		qDur := time.Since(qStart)

		// (a) Exactness vs flat Dijkstra, sampled.
		origin := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
		if origin == noNode {
			log.Printf("post %d: origin does not snap — skipped", p.Msgid)
			continue
		}
		bStart := time.Now()
		base := baseDriveDijkstra(g, origin, initialCostFor(Drive), T)
		bDur := time.Since(bStart)
		stride := 1
		if len(base) > 40000 {
			stride = len(base) / 40000
		}
		i := 0
		exact, mism := 0, 0
		var worst float64
		for id, want := range base {
			i++
			if i%stride != 0 {
				continue
			}
			got := engine.ArrivalAtBaseNode(lbl, id)
			d := math.Abs(float64(got - want))
			if d > worst {
				worst = d
			}
			if d > 0.01 {
				mism++
				if mism <= 3 {
					nd := g.Nodes[id]
					log.Printf("post %d EXACTNESS MISMATCH node %d (%.5f,%.5f): engine %.3f vs base %.3f",
						p.Msgid, id, nd.Lat, nd.Lng, got, want)
				}
			}
			exact++
		}

		// (b) Prod cells vs engine membership on a lattice sample.
		h, err := ccsParseHeader(p.cells)
		if err != nil {
			log.Printf("post %d: bad cells blob: %v — skipped", p.Msgid, err)
			continue
		}
		cells := uint64(h.Cols) * uint64(h.Rows)
		stridef := int32(1)
		for uint64(stridef)*uint64(stridef)*60000 < cells {
			stridef++
		}
		a := agg{}
		type ex struct {
			lat, lng float64
			prodIn   bool
			arr      float32
			snapM    float64
		}
		var structurals []ex
		for row := h.MinRow; row < h.MinRow+int32(h.Rows); row += stridef {
			for col := h.MinCol; col < h.MinCol+int32(h.Cols); col += stridef {
				lat, lng := ccsCellCentre(col, row)
				prodIn, ok := ccsContains(p.cells, lng, lat)
				if !ok {
					continue
				}
				v := nearestNodeForMode(g, lat, lng, Drive)
				engIn := false
				var arr float32 = f32Inf
				snapM := math.Inf(1)
				if v != noNode {
					nd := g.Nodes[v]
					snapM = haversineM(lat, lng, float64(nd.Lat), float64(nd.Lng))
					arr = engine.ArrivalAtBaseNode(lbl, v)
					engIn = arr <= T
				}
				a.samples++
				if prodIn == engIn {
					a.agree++
					continue
				}
				// Disagreement classification.
				margin := math.Abs(float64(arr - T))
				switch {
				case prodIn && (snapM > 60 || arr == f32Inf):
					a.fill++ // interior fill / no road within snap range
				case margin <= 90 || nearStoredEdge(p.cells, col, row, stridef):
					a.boundary++
				default:
					a.structural++
					if len(structurals) < 5 {
						structurals = append(structurals, ex{lat, lng, prodIn, arr, snapM})
					}
				}
			}
		}
		reached := 0
		full := 0
		labelBytes := 0
		for _, rl := range lbl.Reached {
			reached++
			// Stored form: leaf id (4B) + full flag (1B), plus per reached
			// entry (index uint16 + arrival float32) for partial regions.
			labelBytes += 5
			if rl.Full {
				full++
				continue
			}
			for _, a := range rl.EntryArr {
				if a != f32Inf {
					labelBytes += 6
				}
			}
		}
		agreePct := 100 * float64(a.agree) / float64(max(1, a.samples))
		fmt.Printf("post %d [%s] tick %d T=%.0fmin: samples %d agree %.2f%% (fill %d, boundary %d, structural %d) | exact %d/%d mism (worst Δ %.4fs) | query %.1fms (local %.1f, boundary %.1f) vs flat %.0fms | regions %d (%d full), label ~%dB (cells %dB)\n",
			p.Msgid, p.Band, p.Tick, driveMin, a.samples, agreePct, a.fill, a.boundary, a.structural,
			mism, exact, worst, float64(qDur.Microseconds())/1000, lbl.LocalMs, lbl.BoundaryMs,
			float64(bDur.Microseconds())/1000, reached, full, labelBytes, len(p.cells))
		for _, s := range structurals {
			fmt.Printf("    structural: (%.5f,%.5f) prodIn=%v arr=%.1fs snap=%.0fm\n", s.lat, s.lng, s.prodIn, s.arr, s.snapM)
		}
		total.samples += a.samples
		total.agree += a.agree
		total.fill += a.fill
		total.boundary += a.boundary
		total.structural += a.structural
		total.exactChecked += exact
		total.exactMismatch += mism
	}

	fmt.Printf("\nTOTAL: %d samples, agree %.2f%%, fill %d (%.2f%%), boundary %d (%.2f%%), structural %d (%.3f%%); exactness %d/%d mismatches\n",
		total.samples, 100*float64(total.agree)/float64(max(1, total.samples)),
		total.fill, 100*float64(total.fill)/float64(max(1, total.samples)),
		total.boundary, 100*float64(total.boundary)/float64(max(1, total.samples)),
		total.structural, 100*float64(total.structural)/float64(max(1, total.samples)),
		total.exactMismatch, total.exactChecked)
}

// nearStoredEdge reports whether any cell within `dist` lattice steps of
// (col,row) has the opposite membership — i.e. the point sits at the stored
// area's edge, where ±1-cell quantization differences are expected.
func nearStoredEdge(cells []byte, col, row, dist int32) bool {
	self, ok := ccsContains(cells, (float64(col)+0.5)*ccsCellDegrees, (float64(row)+0.5)*ccsCellDegrees)
	if !ok {
		return false
	}
	for _, d := range [][2]int32{{dist, 0}, {-dist, 0}, {0, dist}, {0, -dist}, {dist, dist}, {-dist, -dist}, {dist, -dist}, {-dist, dist}} {
		lat, lng := ccsCellCentre(col+d[0], row+d[1])
		if in, ok := ccsContains(cells, lng, lat); ok && in != self {
			return true
		}
	}
	return false
}

// stage2LoadEngine loads all artifacts and builds the engine.
func stage2LoadEngine() *Stage2Engine {
	g, ov := stage2LoadOrBuild()
	part, err := loadPartition("data/stage2/partition.snap")
	if err != nil {
		log.Fatalf("stage2: load partition (run `stage2 partition` first): %v", err)
	}
	var rm *RegionMatrices
	if rm, err = loadMatrices("data/stage2/matrices.snap"); err != nil {
		log.Printf("stage2: matrices not cached (%v), building", err)
		rm = BuildRegionMatrices(ov, part)
		if err := saveMatrices("data/stage2/matrices.snap", rm); err != nil {
			log.Fatalf("stage2: save matrices: %v", err)
		}
	}
	return NewStage2Engine(g, ov, part, rm)
}

// stage2MatricesRun builds and reports on the matrices artifact.
func stage2MatricesRun() {
	g, ov := stage2LoadOrBuild()
	part, err := loadPartition("data/stage2/partition.snap")
	if err != nil {
		log.Fatalf("stage2: load partition (run `stage2 partition` first): %v", err)
	}
	_ = g
	rm := BuildRegionMatrices(ov, part)
	if err := saveMatrices("data/stage2/matrices.snap", rm); err != nil {
		log.Fatalf("stage2: save matrices: %v", err)
	}

	nLeaves := len(part.LeafNodes)
	var ents, exts []int
	fullCoverEntries := 0
	for l := 0; l < nLeaves; l++ {
		ents = append(ents, int(rm.EntryOff[l+1]-rm.EntryOff[l]))
		exts = append(exts, int(rm.ExitOff[l+1]-rm.ExitOff[l]))
	}
	for _, e := range rm.Ecc {
		if e != f32Inf {
			fullCoverEntries++
		}
	}
	sort.Ints(ents)
	sort.Ints(exts)
	pctI := func(s []int, p float64) int {
		if len(s) == 0 {
			return 0
		}
		return s[int(p*float64(len(s)-1))]
	}
	fmt.Printf("stage2 matrices: %d leaves; entries p50/p90/max %d/%d/%d; exits p50/p90/max %d/%d/%d\n",
		nLeaves, pctI(ents, .5), pctI(ents, .9), pctI(ents, 1), pctI(exts, .5), pctI(exts, .9), pctI(exts, 1))
	fmt.Printf("stage2 matrices: matrix cells %d (%.1fMB float32); cross edges %d; covering entries %d/%d (%.1f%%)\n",
		len(rm.Mat), float64(len(rm.Mat))*4/1e6, len(rm.CrossFrom), fullCoverEntries, len(rm.Ecc),
		100*float64(fullCoverEntries)/float64(max(1, len(rm.Ecc))))
}

func stage2QueryRun(lat, lng, minutes float64) {
	engine := stage2LoadEngine()
	for i := 0; i < 3; i++ {
		start := time.Now()
		lbl := engine.QueryLabels(lat, lng, float32(minutes*60))
		dur := time.Since(start)
		reached, full, partial := 0, 0, 0
		entries := 0
		for _, rl := range lbl.Reached {
			reached++
			if rl.Full {
				full++
			} else {
				partial++
			}
			for _, a := range rl.EntryArr {
				if a != f32Inf {
					entries++
				}
			}
		}
		fmt.Printf("query %d: %.2fms (local %.2f boundary %.2f label %.2f) | %d regions: %d full, %d partial, %d reached entries\n",
			i, float64(dur.Microseconds())/1000, lbl.LocalMs, lbl.BoundaryMs, lbl.LabelMs, reached, full, partial, entries)
	}
}

// baseDriveDijkstra is a plain bounded Dijkstra over the base graph (drive),
// without Isochrone's haversine prune, used as ground truth in tests.
func baseDriveDijkstra(g *Graph, origin NodeID, seed float32, limit float32) map[NodeID]float32 {
	dist := map[NodeID]float32{origin: seed}
	type qi struct {
		id NodeID
		c  float32
	}
	h := []qi{{origin, seed}}
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
		if d, ok := dist[cur.id]; ok && cur.c > d {
			continue
		}
		for _, e := range g.EdgesFrom(cur.id) {
			if e.Seconds[Drive] < 0 {
				continue
			}
			nc := cur.c + e.Seconds[Drive]
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

// stage2ExactDebugRun re-runs the exactness sweep for one exported post and
// prints full diagnostics for every mismatching node — used to root-cause any
// engine-vs-flat-Dijkstra divergence (which must be zero).
func stage2ExactDebugRun(jsonPath string, engine *Stage2Engine) {
	dir := filepath.Dir(jsonPath)
	posts, err := loadProdPosts(dir)
	if err != nil {
		log.Fatalf("exactdebug: %v", err)
	}
	var p *prodPost
	base := filepath.Base(jsonPath)
	for _, c := range posts {
		if fmt.Sprintf("%d.json", c.Msgid) == base {
			p = c
		}
	}
	if p == nil {
		log.Fatalf("exactdebug: %s not found in %s", base, dir)
	}
	driveMin, _ := p.driveMinForTick()
	T := float32(driveMin * 60)
	g, ov, part := engine.G, engine.Ov, engine.Part

	lbl := engine.QueryLabels(p.Lat, p.Lng, T)
	origin := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
	baseArr := baseDriveDijkstra(g, origin, initialCostFor(Drive), T)

	shown := 0
	for id, want := range baseArr {
		got := engine.ArrivalAtBaseNode(lbl, id)
		if math.Abs(float64(got-want)) <= 0.01 {
			continue
		}
		if shown >= 12 {
			continue
		}
		shown++
		nd := g.Nodes[id]
		if oi := ov.Idx[id]; oi != 0 {
			leaf := part.LeafOf[oi]
			var rl *RegionLabel
			var entArr []float32
			if leaf >= 0 {
				rl = lbl.Reached[leaf]
			}
			if rl != nil {
				entArr = rl.EntryArr
			}
			nEnt, minEnt := 0, float32(math.Inf(1))
			for _, a := range entArr {
				if a != f32Inf {
					nEnt++
					if a < minEnt {
						minEnt = a
					}
				}
			}
			_, hasOrigin := lbl.OriginArr[oi]
			fmt.Printf("MISMATCH junction base=%d oi=%d (%.5f,%.5f) leaf=%d leafSize=%d got=%.2f want=%.2f label=%v entriesReached=%d minEntryArr=%.2f originArr=%v\n",
				id, oi, nd.Lat, nd.Lng, leaf, leafSizeOf(part, leaf), got, want, rl != nil, nEnt, minEnt, hasOrigin)
		} else {
			a, b := ov.ChainEndA[id], ov.ChainEndB[id]
			fmt.Printf("MISMATCH chain base=%d (%.5f,%.5f) got=%.2f want=%.2f ends=%d(leaf %d, arr %.2f, offA %.1f)/%d(leaf %d, arr %.2f, offB %.1f)\n",
				id, nd.Lat, nd.Lng, got, want,
				a, leafOfBase(engine, a), engine.junctionArrival(lbl, a), ov.OffFromA[id],
				b, leafOfBase(engine, b), engine.junctionArrival(lbl, b), ov.OffFromB[id])
		}
	}
	fmt.Printf("total mismatches shown %d (cap 12)\n", shown)
}

func leafSizeOf(part *Stage2Partition, leaf int32) int {
	if leaf < 0 || int(leaf) >= len(part.LeafNodes) {
		return -1
	}
	return len(part.LeafNodes[leaf])
}

func leafOfBase(e *Stage2Engine, j NodeID) int32 {
	if j == 0 || e.Ov.Idx[j] == 0 {
		return -2
	}
	return e.Part.LeafOf[e.Ov.Idx[j]]
}

// stage2BoundaryDebugRun compares the engine's boundary-node arrivals with
// flat-Dijkstra truth for one post, printing the earliest divergences — the
// point where the boundary graph first misses a faster path.
func stage2BoundaryDebugRun(jsonPath string, engine *Stage2Engine) {
	posts, err := loadProdPosts(filepath.Dir(jsonPath))
	if err != nil {
		log.Fatalf("boundarydebug: %v", err)
	}
	var p *prodPost
	for _, c := range posts {
		if fmt.Sprintf("%d.json", c.Msgid) == filepath.Base(jsonPath) {
			p = c
		}
	}
	if p == nil {
		log.Fatal("post not found")
	}
	driveMin, _ := p.driveMinForTick()
	T := float32(driveMin * 60)
	g, ov := engine.G, engine.Ov

	lbl := engine.QueryLabels(p.Lat, p.Lng, T)
	origin := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
	base := baseDriveDijkstra(g, origin, initialCostFor(Drive), T)

	type div struct {
		oi      uint32
		trueArr float32
		engArr  float32
	}
	var divs []div
	for oi, leaf := range engine.BI.leafOf {
		_ = leaf
		baseID := ov.BaseNode[oi]
		want, reached := base[baseID]
		if !reached {
			continue
		}
		got, ok := lbl.BoundaryDist[oi]
		if !ok {
			got = f32Inf
		}
		if float64(got-want) > 0.01 {
			divs = append(divs, div{oi, want, got})
		}
	}
	sort.Slice(divs, func(i, j int) bool { return divs[i].trueArr < divs[j].trueArr })
	fmt.Printf("boundary divergences: %d of %d boundary nodes; earliest 10:\n", len(divs), len(engine.BI.leafOf))
	for i, d := range divs {
		if i >= 10 {
			break
		}
		baseID := ov.BaseNode[d.oi]
		nd := g.Nodes[baseID]
		leaf := engine.BI.leafOf[d.oi]
		_, isEntry := engine.BI.entryIdx[d.oi]
		cr, hasCross := engine.BI.cross[d.oi]
		fmt.Printf("  oi=%d base=%d (%.5f,%.5f) leaf=%d entry=%v crossOut=%v(%d) true=%.2f eng=%.2f Δ=%.2f\n",
			d.oi, baseID, nd.Lat, nd.Lng, leaf, isEntry, hasCross, cr.count, d.trueArr, d.engArr, d.engArr-d.trueArr)
		// Print the base-graph overlay predecessors: which overlay neighbours
		// could have relaxed this node, with their true arrivals.
		for _, e := range ov.EdgesFrom(d.oi) {
			_ = e
		}
		// Reverse relaxation check: find overlay nodes u with edge u->oi.
		cnt := 0
		for u := uint32(1); u <= uint32(ov.NodeCount()) && cnt < 6; u++ {
			for _, e := range ov.EdgesFrom(u) {
				if e.To == d.oi && e.Seconds[Drive] >= 0 {
					ub := ov.BaseNode[u]
					uw, ur := base[ub]
					if ur && uw+e.Seconds[Drive] <= d.trueArr+1 {
						uleaf := int32(-9)
						if engine.Ov.Idx[ub] != 0 {
							uleaf = engine.Part.LeafOf[engine.Ov.Idx[ub]]
						}
						ue, uisEntry := engine.BI.leafOf[u], false
						_, uisEntry = engine.BI.entryIdx[u]
						ud, uhas := lbl.BoundaryDist[u]
						fmt.Printf("      pred u=%d (leaf %d, boundary=%v entry=%v engDist=%.2f has=%v) + edge %.2fs = %.2f\n",
							u, uleaf, ue != 0 || uhas, uisEntry, ud, uhas, e.Seconds[Drive], uw+e.Seconds[Drive])
						cnt++
					}
				}
			}
		}
	}
}

// baseDriveDijkstraPrev is baseDriveDijkstra with predecessor tracking.
func baseDriveDijkstraPrev(g *Graph, origin NodeID, seed float32, limit float32) (map[NodeID]float32, map[NodeID]NodeID) {
	dist := map[NodeID]float32{origin: seed}
	prev := map[NodeID]NodeID{}
	type qi struct {
		id NodeID
		c  float32
	}
	h := []qi{{origin, seed}}
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
		if d, ok := dist[cur.id]; ok && cur.c > d {
			continue
		}
		for _, e := range g.EdgesFrom(cur.id) {
			if e.Seconds[Drive] < 0 {
				continue
			}
			nc := cur.c + e.Seconds[Drive]
			if nc > limit {
				continue
			}
			if d, ok := dist[e.To]; !ok || nc < d {
				dist[e.To] = nc
				prev[e.To] = cur.id
				push(qi{e.To, nc})
			}
		}
	}
	return dist, prev
}

// stage2TracePathRun prints the true shortest path to a base node, projected
// onto overlay junctions with partition annotations.
func stage2TracePathRun(jsonPath string, target NodeID, engine *Stage2Engine) {
	posts, _ := loadProdPosts(filepath.Dir(jsonPath))
	var p *prodPost
	for _, c := range posts {
		if fmt.Sprintf("%d.json", c.Msgid) == filepath.Base(jsonPath) {
			p = c
		}
	}
	if p == nil {
		log.Fatal("post not found")
	}
	driveMin, _ := p.driveMinForTick()
	T := float32(driveMin * 60)
	g, ov := engine.G, engine.Ov
	lbl := engine.QueryLabels(p.Lat, p.Lng, T)
	origin := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
	dist, prevMap := baseDriveDijkstraPrev(g, origin, initialCostFor(Drive), T)

	var path []NodeID
	for cur := target; ; {
		path = append(path, cur)
		pv, ok := prevMap[cur]
		if !ok {
			break
		}
		cur = pv
	}
	fmt.Printf("true path to base=%d (%d hops), overlay junctions only:\n", target, len(path))
	for i := len(path) - 1; i >= 0; i-- {
		v := path[i]
		oi := ov.Idx[v]
		if oi == 0 {
			continue
		}
		leaf := engine.Part.LeafOf[oi]
		_, isEntry := engine.BI.entryIdx[oi]
		_, isBnd := engine.BI.leafOf[oi]
		bd, hasBd := lbl.BoundaryDist[oi]
		fmt.Printf("  base=%d oi=%d leaf=%d entry=%v bnd=%v true=%.2f engBd=%.2f(%v)\n",
			v, oi, leaf, isEntry, isBnd, dist[v], bd, hasBd)
	}
}

// stage2LeafCheckRun walks the true-path segment inside one leaf, verifying
// every overlay hop exists in the leaf subgraph with the base-path weight.
func stage2LeafCheckRun(jsonPath string, target NodeID, engine *Stage2Engine) {
	posts, _ := loadProdPosts(filepath.Dir(jsonPath))
	var p *prodPost
	for _, c := range posts {
		if fmt.Sprintf("%d.json", c.Msgid) == filepath.Base(jsonPath) {
			p = c
		}
	}
	driveMin, _ := p.driveMinForTick()
	T := float32(driveMin * 60)
	g, ov := engine.G, engine.Ov
	origin := nearestNodeForMode(g, p.Lat, p.Lng, Drive)
	dist, prevMap := baseDriveDijkstraPrev(g, origin, initialCostFor(Drive), T)

	// Full base path, then project to overlay junctions.
	var path []NodeID
	for cur := target; ; {
		path = append(path, cur)
		pv, ok := prevMap[cur]
		if !ok {
			break
		}
		cur = pv
	}
	// Reverse to origin->target and keep junctions.
	var junctions []NodeID
	for i := len(path) - 1; i >= 0; i-- {
		if ov.Idx[path[i]] != 0 {
			junctions = append(junctions, path[i])
		}
	}
	leaf := engine.Part.LeafOf[ov.Idx[target]]
	ls := buildLeafSubgraph(ov, engine.Part, leaf)
	fmt.Printf("leaf %d: %d nodes; checking hops of the internal segment:\n", leaf, len(ls.nodes))
	inSeg := false
	for i := 0; i+1 < len(junctions); i++ {
		u, v := junctions[i], junctions[i+1]
		uoi, voi := ov.Idx[u], ov.Idx[v]
		ul := engine.Part.LeafOf[uoi]
		vl := engine.Part.LeafOf[voi]
		if ul != leaf || vl != leaf {
			inSeg = false
			continue
		}
		if !inSeg {
			fmt.Printf("  segment start at base=%d true=%.2f\n", u, dist[u])
			inSeg = true
		}
		wantW := dist[v] - dist[u]
		uli, uin := ls.localOf[uoi]
		vli, vin := ls.localOf[voi]
		if !uin || !vin {
			fmt.Printf("  HOP NODE MISSING from subgraph: u(base %d, oi %d, in=%v) v(base %d, oi %d, in=%v)\n", u, uoi, uin, v, voi, vin)
			continue
		}
		best := f32Inf
		for pp := ls.start[uli]; pp < ls.start[uli+1]; pp++ {
			if ls.to[pp] == vli && ls.secs[pp] < best {
				best = ls.secs[pp]
			}
		}
		if best == f32Inf {
			fmt.Printf("  HOP EDGE MISSING: base %d->%d (oi %d->%d) wantW=%.2f\n", u, v, uoi, voi, wantW)
		} else if float64(best-wantW) > 0.01 {
			fmt.Printf("  HOP WEIGHT HIGH: base %d->%d ls=%.2f want=%.2f\n", u, v, best, wantW)
		}
	}
	fmt.Println("hop check complete")
}
