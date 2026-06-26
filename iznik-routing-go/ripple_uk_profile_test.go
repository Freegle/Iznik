package main

// Real-UK-scale profiling of the ripple-schedule pipeline.
//
// Loads the production graph (data/uk-latest.osm.pbf, the merged GB+Ireland
// extract the routing server actually runs on) and times each CPU phase of
// handleRippleSchedule for representative origins, so we can see — at true
// 57M-node scale — what fraction of a post's compute the Dijkstra isochrone is,
// and therefore whether a faster isochrone (contraction hierarchies / PHAST)
// can move the needle.
//
// Gated behind RUN_UK_PROFILE=1 because it needs the 2.5GB PBF and ~6-8GB RAM
// and takes minutes to build the graph.
//
//   RUN_UK_PROFILE=1 CGO_ENABLED=0 go test -run TestUKRippleProfile -v -timeout 1200s

import (
	"os"
	"runtime"
	"sort"
	"testing"
	"time"
)

var ukGraph *Graph

func ukLoad(tb testing.TB) *Graph {
	tb.Helper()
	if ukGraph != nil {
		return ukGraph
	}
	path := "data/uk-latest.osm.pbf"
	if _, err := os.Stat(path); err != nil {
		tb.Skipf("UK PBF not present (%s): %v", path, err)
	}
	t0 := time.Now()
	g, err := BuildGraph(path, nil)
	if err != nil {
		tb.Fatalf("BuildGraph(uk): %v", err)
	}
	var ms runtime.MemStats
	runtime.ReadMemStats(&ms)
	tb.Logf("BuildGraph(uk) = %v | %d nodes, %d edges | HeapAlloc=%.1fGB Sys=%.1fGB",
		time.Since(t0).Round(time.Second), g.NodeCount(), len(g.Edges),
		float64(ms.HeapAlloc)/1e9, float64(ms.Sys)/1e9)
	ukGraph = g
	return g
}

func TestUKRippleProfile(t *testing.T) {
	if os.Getenv("RUN_UK_PROFILE") == "" {
		t.Skip("set RUN_UK_PROFILE=1")
	}
	g := ukLoad(t)
	const ticks = 9
	mode := Drive

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
		{"rural-mid-Wales", 52.3, -3.6},
	}

	for _, o := range origins {
		for _, mins := range []float64{30} { // production max_minutes
			secs := float32(mins * 60)

			t0 := time.Now()
			iso := Isochrone(g, o.lat, o.lng, secs, mode)
			dDijkstra := time.Since(t0)
			reached := len(iso.ReachedNodes)
			if reached == 0 {
				t.Logf("%-16s mins=%2.0f reached=0 (off-graph)", o.name, mins)
				continue
			}

			res := AutoResolution(secs, mode)

			t0 = time.Now()
			_ = IsochronePolygon(g, iso.ReachedNodes, res)
			dFullPoly := time.Since(t0)

			secsList := make([]float32, 0, reached)
			for _, tt := range iso.ReachedNodes {
				secsList = append(secsList, tt)
			}
			sort.Slice(secsList, func(i, j int) bool { return secsList[i] < secsList[j] })

			t0 = time.Now()
			for k := 1; k <= ticks; k++ {
				x := float64(k) / float64(ticks)
				frac := curveFraction("step-70", x, ticks)
				target := int(float64(reached) * frac)
				if target < 1 {
					target = 1
				}
				if target > reached {
					target = reached
				}
				cutoff := secsList[target-1]
				filtered := make(map[NodeID]float32, target)
				for nid, tt := range iso.ReachedNodes {
					if tt <= cutoff {
						filtered[nid] = tt
					}
				}
				_ = IsochronePolygon(g, filtered, res)
			}
			dTickPolys := time.Since(t0)

			// Per-call nearestNodeForMode cost on the REAL grid, ×N freeglers.
			// Sample reached-node coords with ~150m jitter to mimic freegler points.
			ids := make([]NodeID, 0, reached)
			for nid := range iso.ReachedNodes {
				ids = append(ids, nid)
			}
			// Deterministic sampling: stride through ids.
			const probe = 20000
			t0 = time.Now()
			hit := 0
			for i := 0; i < probe; i++ {
				nd := g.Nodes[ids[(i*7919)%len(ids)]]
				jlat := float64(nd.Lat) + float64((i%7)-3)*0.0004
				jlng := float64(nd.Lng) + float64((i%5)-2)*0.0004
				if nearestNodeForMode(g, jlat, jlng, mode) != noNode {
					hit++
				}
			}
			dProbe := time.Since(t0)
			perCall := dProbe / probe

			t.Logf("%-16s reached=%8d | dijkstra=%9v fullPoly=%8v tickPolys×9=%9v | nearest/call=%6v (→ ×10k=%v ×50k=%v ×100k=%v)",
				o.name, reached,
				dDijkstra.Round(time.Millisecond), dFullPoly.Round(time.Millisecond), dTickPolys.Round(time.Millisecond),
				perCall.Round(time.Nanosecond),
				(perCall * 10000).Round(time.Millisecond),
				(perCall * 50000).Round(time.Millisecond),
				(perCall * 100000).Round(time.Millisecond))
		}
	}
}
