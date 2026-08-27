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
		for _, rl := range lbl.Reached {
			reached++
			if rl.Full {
				full++
			}
		}
		agreePct := 100 * float64(a.agree) / float64(max(1, a.samples))
		fmt.Printf("post %d [%s] tick %d T=%.0fmin: samples %d agree %.2f%% (fill %d, boundary %d, structural %d) | exact %d/%d mism (worst Δ %.4fs) | query %.1fms (local %.1f, boundary %.1f) vs flat %.0fms | regions %d (%d full)\n",
			p.Msgid, p.Band, p.Tick, driveMin, a.samples, agreePct, a.fill, a.boundary, a.structural,
			mism, exact, worst, float64(qDur.Microseconds())/1000, lbl.LocalMs, lbl.BoundaryMs,
			float64(bDur.Microseconds())/1000, reached, full)
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
