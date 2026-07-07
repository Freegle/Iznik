package main

// Profiling harness for the ripple-schedule pipeline cost structure.
//
// Reconstructs the CPU-side phases of handleRippleSchedule (everything except
// the within_coords HTTP round-trip to spatial-knn) and times each one, so we
// can see what fraction of the per-post cost the Dijkstra isochrone actually
// represents — the make-or-break question for whether a faster isochrone
// (contraction hierarchies / PHAST) can move the needle.
//
// Run with: CGO_ENABLED=0 go test -run TestRippleProfile -v -timeout 300s
//
// Bristol extract is small (a 30-min drive covers most of it), so we sweep the
// time budget to vary the reached-set size and observe how each phase scales —
// the scaling law extrapolates to the UK graph where a 30-min drive is a bounded
// subset of the 57M nodes.

import (
	"math"
	"math/rand"
	"os"
	"sort"
	"testing"
	"time"
)

func loadBristol(tb testing.TB) *Graph {
	tb.Helper()
	g, err := BuildGraph("testdata/bristol.osm.pbf", nil)
	if err != nil {
		tb.Fatalf("BuildGraph(bristol): %v", err)
	}
	return g
}

func TestRippleProfile(t *testing.T) {
	if os.Getenv("RUN_RIPPLE_PROFILE") == "" {
		t.Skip("set RUN_RIPPLE_PROFILE=1 (loads the Bristol PBF; profiling harness, not a CI test)")
	}
	g := loadBristol(t)
	t.Logf("graph: %d nodes, %d edges", g.NodeCount(), len(g.Edges))

	// Central Bristol (Temple Meads-ish), on the road graph.
	lat, lng := 51.4545, -2.5879
	mode := Drive
	const ticks = 9 // == len(hazard_hours) the PHP ReachService requests

	// Deterministic RNG so the freegler-sampling phase is reproducible.
	rng := rand.New(rand.NewSource(42))

	for _, mins := range []float64{2, 5, 10, 20, 30} {
		secs := float32(mins * 60)

		// ---- Phase 1: Dijkstra isochrone to the max drive-time ----
		t0 := time.Now()
		iso := Isochrone(g, lat, lng, secs, mode)
		dDijkstra := time.Since(t0)
		reached := len(iso.ReachedNodes)
		if reached == 0 {
			t.Logf("mins=%2.0f reached=0 (skip)", mins)
			continue
		}

		res := AutoResolution(secs, mode)

		// ---- Phase 2: one full-isochrone polygon (drives the within_coords WKT) ----
		t0 = time.Now()
		_ = IsochronePolygon(g, iso.ReachedNodes, res)
		dFullPoly := time.Since(t0)

		// Sorted reached-times, needed to pick each tick's drive-time cutoff.
		secsList := make([]float32, 0, reached)
		for _, tt := range iso.ReachedNodes {
			secsList = append(secsList, tt)
		}
		sort.Slice(secsList, func(i, j int) bool { return secsList[i] < secsList[j] })

		// ---- Phase 3: the 9 per-tick polygons (filter reached set + rasterise) ----
		t0 = time.Now()
		for k := 1; k <= ticks; k++ {
			x := float64(k) / float64(ticks)
			frac := curveFraction("step-70", x, ticks)
			target := int(math.Round(frac * float64(reached)))
			if target < 1 {
				target = 1
			}
			if target > reached {
				target = reached
			}
			cutoff := secsList[target-1]
			filtered := make(map[NodeID]float32, target*2)
			for nid, tt := range iso.ReachedNodes {
				if tt <= cutoff {
					filtered[nid] = tt
				}
			}
			_ = IsochronePolygon(g, filtered, res)
		}
		dTickPolys := time.Since(t0)

		// ---- Phase 4: nearestNodeForMode × N freeglers ----
		// handleRippleSchedule calls nearestNodeForMode once per freegler returned
		// by within_coords (to map them onto a reached node). N is the count of
		// freeglers inside the polygon — small in rural areas, very large in dense
		// ones. Simulate by sampling N reached-node coordinates with ~150m jitter.
		ids := make([]NodeID, 0, reached)
		for nid := range iso.ReachedNodes {
			ids = append(ids, nid)
		}
		for _, n := range []int{1000, 10000, 50000} {
			t0 = time.Now()
			for i := 0; i < n; i++ {
				nd := g.Nodes[ids[rng.Intn(len(ids))]]
				jlat := float64(nd.Lat) + (rng.Float64()-0.5)*0.003
				jlng := float64(nd.Lng) + (rng.Float64()-0.5)*0.003
				_ = nearestNodeForMode(g, jlat, jlng, mode)
			}
			dNearest := time.Since(t0)
			t.Logf("mins=%2.0f reached=%6d | dijkstra=%8v fullPoly=%8v tickPolys×9=%8v nearest×%-5d=%8v",
				mins, reached, dDijkstra.Round(time.Microsecond), dFullPoly.Round(time.Microsecond),
				dTickPolys.Round(time.Microsecond), n, dNearest.Round(time.Microsecond))
		}
	}
}

// TestRippleProfileGraphBuild times the one-off graph build (paid on every
// server start today — no preprocessing is persisted).
func TestRippleProfileGraphBuild(t *testing.T) {
	if os.Getenv("RUN_RIPPLE_PROFILE") == "" {
		t.Skip("set RUN_RIPPLE_PROFILE=1")
	}
	t0 := time.Now()
	g := loadBristol(t)
	t.Logf("BuildGraph(bristol) = %v for %d nodes / %d edges",
		time.Since(t0).Round(time.Millisecond), g.NodeCount(), len(g.Edges))
}
