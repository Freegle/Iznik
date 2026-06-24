package main

// End-to-end benchmark of the density-raster ripple-reach path (design:
// docs/superpowers/specs/2026-06-24-ripple-reach-density-heatmap-design.md)
// against the CURRENT production path (handleRippleSchedule: within_coords +
// nearestNodeForMode per freegler), on the REAL UK graph with the REAL freegler
// distribution exported from production (data/freeglers.tsv, ~175k points).
//
// It answers two things the live server cares about:
//   1. How long does it take to BUILD the static density raster (whole UK)?
//   2. How long does a per-post reach computation take NOW (raster path) vs the
//      ~25s/post we measured on the current path — and does the raster reproduce
//      the population-paced tick cutoffs?
//
// Gated behind RUN_UK_PROFILE=1 (needs the 2.5GB PBF + data/freeglers.tsv).
//
//   RUN_UK_PROFILE=1 CGO_ENABLED=0 go test -run TestUKDensityRaster -v -timeout 1800s

import (
	"bufio"
	"math"
	"os"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"testing"
	"time"
)

// ---- density raster (density-adaptive quadtree) -------------------------------

type densityLeaf struct {
	lat, lng float64
	count    int
}

// buildDensityRaster builds a density-adaptive quadtree over pts ([lat,lng]) and
// returns its leaves (population centroid + count). Subdivide a cell while it
// holds more than m points AND is coarser than minCellDeg; stop otherwise.
func buildDensityRaster(pts [][2]float64, m int, minCellDeg float64,
	rootMinLa, rootMinLo, rootMaxLa, rootMaxLo float64) []densityLeaf {
	idx := make([]int32, 0, len(pts))
	for i, p := range pts {
		if p[0] >= rootMinLa && p[0] <= rootMaxLa && p[1] >= rootMinLo && p[1] <= rootMaxLo {
			idx = append(idx, int32(i))
		}
	}
	var leaves []densityLeaf
	var rec func(idx []int32, minLa, minLo, maxLa, maxLo float64)
	rec = func(idx []int32, minLa, minLo, maxLa, maxLo float64) {
		if len(idx) == 0 {
			return
		}
		if len(idx) <= m || (maxLa-minLa) <= minCellDeg {
			var sLa, sLo float64
			for _, i := range idx {
				sLa += pts[i][0]
				sLo += pts[i][1]
			}
			leaves = append(leaves, densityLeaf{sLa / float64(len(idx)), sLo / float64(len(idx)), len(idx)})
			return
		}
		midLa := (minLa + maxLa) / 2
		midLo := (minLo + maxLo) / 2
		var q [4][]int32
		for _, i := range idx {
			b := 0
			if pts[i][0] >= midLa {
				b |= 1
			}
			if pts[i][1] >= midLo {
				b |= 2
			}
			q[b] = append(q[b], i)
		}
		rec(q[0], minLa, minLo, midLa, midLo)
		rec(q[1], midLa, minLo, maxLa, midLo)
		rec(q[2], minLa, midLo, midLa, maxLo)
		rec(q[3], midLa, midLo, maxLa, maxLo)
	}
	rec(idx, rootMinLa, rootMinLo, rootMaxLa, rootMaxLo)
	return leaves
}

// pointInRing: ray-casting point-in-polygon. ring points are [lng,lat]; the
// query is (lat,lng). Models within_coords' polygon containment test.
func pointInRing(ring [][2]float64, lat, lng float64) bool {
	if len(ring) < 3 {
		return false
	}
	inside := false
	j := len(ring) - 1
	for i := 0; i < len(ring); i++ {
		xi, yi := ring[i][0], ring[i][1] // lng, lat
		xj, yj := ring[j][0], ring[j][1]
		if (yi > lat) != (yj > lat) {
			xint := (xj-xi)*(lat-yi)/(yj-yi) + xi
			if lng < xint {
				inside = !inside
			}
		}
		j = i
	}
	return inside
}

func loadFreeglers(tb testing.TB) [][2]float64 {
	tb.Helper()
	f, err := os.Open("data/freeglers.tsv")
	if err != nil {
		tb.Skipf("data/freeglers.tsv not present: %v", err)
	}
	defer f.Close()
	var pts [][2]float64
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1<<20), 1<<20)
	for sc.Scan() {
		parts := strings.Split(sc.Text(), "\t")
		if len(parts) != 2 {
			continue
		}
		la, e1 := strconv.ParseFloat(strings.TrimSpace(parts[0]), 64)
		lo, e2 := strconv.ParseFloat(strings.TrimSpace(parts[1]), 64)
		if e1 != nil || e2 != nil {
			continue
		}
		pts = append(pts, [2]float64{la, lo})
	}
	return pts
}

// weighted percentile cutoffs: given (secs,weight) sorted by secs and a curve,
// return the drive-time at each tick's cumulative-weight target.
func tickCutoffs(secs []float32, weights []int, curve string, ticks int) []float32 {
	// cumulative weight
	totalW := 0
	for _, w := range weights {
		totalW += w
	}
	cuts := make([]float32, ticks)
	for k := 1; k <= ticks; k++ {
		x := float64(k) / float64(ticks)
		frac := curveFraction(curve, x, ticks)
		target := int(math.Round(frac * float64(totalW)))
		if target < 1 {
			target = 1
		}
		if target > totalW {
			target = totalW
		}
		// walk cumulative to find first index where cum >= target
		cum := 0
		ci := len(secs) - 1
		for i := range secs {
			cum += weights[i]
			if cum >= target {
				ci = i
				break
			}
		}
		cuts[k-1] = secs[ci]
	}
	return cuts
}

func TestUKDensityRaster(t *testing.T) {
	if os.Getenv("RUN_UK_PROFILE") == "" {
		t.Skip("set RUN_UK_PROFILE=1")
	}
	g := ukLoad(t)
	mode := Drive
	const ticks = 9
	const curve = "step-70"
	secs := float32(30 * 60)

	// UK/Ireland bbox (PBF is merged GB+IE); drops a handful of junk coords.
	const (
		ukMinLa, ukMaxLa = 49.0, 61.0
		ukMinLo, ukMaxLo = -11.5, 2.5
	)
	const m = 10              // aggregation target (people/cell)
	const minCellDeg = 0.001  // ~111m floor (= time-grid resolution)
	const gridRes = 0.001     // time-grid resolution for drive-time lookup

	all := loadFreeglers(t)
	// keep only UK-bbox points for the raster + as the realistic recipient set
	pts := make([][2]float64, 0, len(all))
	for _, p := range all {
		if p[0] >= ukMinLa && p[0] <= ukMaxLa && p[1] >= ukMinLo && p[1] <= ukMaxLo {
			pts = append(pts, p)
		}
	}
	t.Logf("freeglers: %d loaded, %d inside UK bbox (%d dropped as out-of-bounds)",
		len(all), len(pts), len(all)-len(pts))

	// ---- BUILD THE RASTER (whole UK, once) ----
	var ms0, ms1 runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&ms0)
	t0 := time.Now()
	leaves := buildDensityRaster(pts, m, minCellDeg, ukMinLa, ukMinLo, ukMaxLa, ukMaxLo)
	dBuild := time.Since(t0)
	runtime.ReadMemStats(&ms1)
	totalInLeaves := 0
	for _, l := range leaves {
		totalInLeaves += l.count
	}
	t.Logf("RASTER BUILD (whole UK): %v | %d leaf cells from %d points | mean %.1f people/cell | heap +%.1fMB",
		dBuild.Round(time.Millisecond), len(leaves), totalInLeaves,
		float64(totalInLeaves)/float64(len(leaves)), float64(ms1.HeapAlloc-ms0.HeapAlloc)/1e6)

	origins := []struct {
		name     string
		lat, lng float64
	}{
		{"London-central", 51.5074, -0.1278},
		{"Birmingham", 52.4862, -1.8904},
		{"Manchester", 53.4808, -2.2426},
		{"Reading-suburb", 51.4543, -0.9781},
		{"Norwich-smaller", 52.6309, 1.2974},
		{"rural-Devon", 50.7772, -3.9995},
	}

	t.Logf("")
	t.Logf("%-16s | %8s | %10s %10s %8s | %10s %10s %8s | %7s | tick-cutoff agreement",
		"origin", "Npoly", "OLD total", "  └snap", "OLD/N", "NEW total", "  └raster", "speedup", "cells")

	for _, o := range origins {
		iso := Isochrone(g, o.lat, o.lng, secs, mode)
		reached := len(iso.ReachedNodes)
		if reached == 0 {
			t.Logf("%-16s reached=0 (off-graph)", o.name)
			continue
		}
		res := AutoResolution(secs, mode)

		// shared cost: dijkstra already done above (timed separately below for ref)
		t0 = time.Now()
		isoT := Isochrone(g, o.lat, o.lng, secs, mode)
		dDijkstra := time.Since(t0)
		_ = isoT

		// ===== OLD path (current production: handleRippleSchedule) =====
		// within_coords stand-in: real freeglers inside the polygon. We model the
		// routing-server cost = nearestNodeForMode per freegler returned. To find
		// the returned set cheaply we bbox-prefilter then snap (the snap IS the
		// production step-3 cost, so it is timed).
		// bbox of reached nodes:
		minLa, minLo := math.MaxFloat64, math.MaxFloat64
		maxLa, maxLo := -math.MaxFloat64, -math.MaxFloat64
		for nid := range iso.ReachedNodes {
			n := g.Nodes[nid]
			la, lo := float64(n.Lat), float64(n.Lng)
			if la < minLa {
				minLa = la
			}
			if la > maxLa {
				maxLa = la
			}
			if lo < minLo {
				minLo = lo
			}
			if lo > maxLo {
				maxLo = lo
			}
		}
		// OLD step 2: full polygon (→ within_coords WKT). TIMED.
		t0 = time.Now()
		fullPoly := IsochronePolygon(g, iso.ReachedNodes, res)
		dFullPoly := time.Since(t0)
		var ring0 [][2]float64
		if len(fullPoly.Geometry.Coordinates) > 0 {
			ring0 = fullPoly.Geometry.Coordinates[0]
		}

		// within_coords stand-in (UNTIMED — production does this on the spatial
		// server): bbox-prefilter then point-in-polygon against the outer ring, so
		// the snapped set equals what within_coords actually returns.
		cand := make([][2]float64, 0, 4096)
		for _, p := range pts {
			if p[0] >= minLa && p[0] <= maxLa && p[1] >= minLo && p[1] <= maxLo && pointInRing(ring0, p[0], p[1]) {
				cand = append(cand, p)
			}
		}

		// OLD step 3: snap each within-polygon freegler. (TIMED — the bottleneck.)
		t0 = time.Now()
		oldSecs := make([]float32, 0, len(cand))
		for _, p := range cand {
			nid := nearestNodeForMode(g, p[0], p[1], mode)
			if nid == noNode {
				continue
			}
			tt, ok := iso.ReachedNodes[nid]
			if !ok {
				continue
			}
			oldSecs = append(oldSecs, tt)
		}
		dSnap := time.Since(t0)
		nPoly := len(oldSecs)
		sort.Slice(oldSecs, func(i, j int) bool { return oldSecs[i] < oldSecs[j] })

		// OLD step 5: tick polygons (9), filtered by each old cutoff.
		oldW := make([]int, len(oldSecs))
		for i := range oldW {
			oldW[i] = 1
		}
		oldCuts := tickCutoffs(oldSecs, oldW, curve, ticks)
		t0 = time.Now()
		for _, cut := range oldCuts {
			filtered := make(map[NodeID]float32)
			for nid, tt := range iso.ReachedNodes {
				if tt <= cut {
					filtered[nid] = tt
				}
			}
			_ = IsochronePolygon(g, filtered, res)
		}
		dOldTickPolys := time.Since(t0)
		oldTotal := dDijkstra + dFullPoly + dSnap + dOldTickPolys

		// ===== NEW path (density raster + time-grid) =====
		t0 = time.Now()
		grid := buildTimeGrid(g, iso.ReachedNodes, gridRes)
		dGrid := time.Since(t0)

		t0 = time.Now()
		// one O(1) lookup per leaf cell → (driveSecs, count) for reachable cells
		type sw struct {
			s float32
			w int
		}
		acc := make([]sw, 0, len(leaves))
		cellsLookedUp := 0
		for _, l := range leaves {
			// cheap prefilter: skip cells far outside the isochrone bbox
			if l.lat < minLa-gridRes || l.lat > maxLa+gridRes || l.lng < minLo-gridRes || l.lng > maxLo+gridRes {
				continue
			}
			cellsLookedUp++
			tt, ok := gridTime(grid, gridRes, l.lat, l.lng)
			if !ok {
				continue
			}
			acc = append(acc, sw{tt, l.count})
		}
		sort.Slice(acc, func(i, j int) bool { return acc[i].s < acc[j].s })
		newSecs := make([]float32, len(acc))
		newW := make([]int, len(acc))
		newTotal := 0
		for i, a := range acc {
			newSecs[i] = a.s
			newW[i] = a.w
			newTotal += a.w
		}
		newCuts := tickCutoffs(newSecs, newW, curve, ticks)
		dRaster := time.Since(t0)

		// NEW tick polygons (9): same as OLD but using NEW cutoffs.
		t0 = time.Now()
		for _, cut := range newCuts {
			filtered := make(map[NodeID]float32)
			for nid, tt := range iso.ReachedNodes {
				if tt <= cut {
					filtered[nid] = tt
				}
			}
			_ = IsochronePolygon(g, filtered, res)
		}
		dNewTickPolys := time.Since(t0)
		newTotalTime := dDijkstra + dNewTickPolys + dGrid + dRaster

		// accuracy: tick-cutoff agreement (drive-time seconds)
		var maxCut, sumCut float64
		for i := 0; i < ticks; i++ {
			d := math.Abs(float64(oldCuts[i] - newCuts[i]))
			sumCut += d
			if d > maxCut {
				maxCut = d
			}
		}

		speed := float64(oldTotal) / float64(newTotalTime)
		t.Logf("%-16s | %8d | %10v %10v %8s | %10v %10v %7.1f× | %7d | Δcut mean=%.0fs max=%.0fs  (raster N=%d vs %d)",
			o.name, nPoly,
			oldTotal.Round(time.Millisecond), dSnap.Round(time.Millisecond),
			func() string {
				if nPoly == 0 {
					return "-"
				}
				return (dSnap / time.Duration(nPoly)).Round(time.Microsecond).String()
			}(),
			newTotalTime.Round(time.Millisecond), dRaster.Round(time.Millisecond), speed,
			cellsLookedUp,
			sumCut/float64(ticks), maxCut, newTotal, nPoly)
	}
}
