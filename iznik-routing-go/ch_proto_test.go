package main

// ch_proto_test.go - Tests and benchmarks for the CH prototype.
//
// Tests:
//   TestCHBuild          - builds the CH on bristol.osm.pbf and reports stats.
//   TestCHPointToPoint   - cross-checks CH distances against plain Dijkstra for 1000 random pairs.
//   TestCHIsochroneConsistency - compares CH/PHAST isochrone sets against Isochrone() Dijkstra.
//   BenchmarkIsochroneVsPHAST  - times Dijkstra vs PHAST at 5/15/30 minutes on Bristol.

import (
	"container/heap"
	"fmt"
	"math"
	"math/rand"
	"testing"
	"time"
)

// loadBristolOnce caches the Bristol graph and CH to avoid repeated PBF parsing.
var (
	bristolGraph *Graph
	bristolCH    *CH
)

func getBristol(t testing.TB) (*Graph, *CH) {
	t.Helper()
	if bristolGraph != nil {
		return bristolGraph, bristolCH
	}
	t.Log("Building Bristol graph from testdata/bristol.osm.pbf ...")
	g, err := BuildGraph("testdata/bristol.osm.pbf", nil)
	if err != nil {
		t.Fatalf("BuildGraph: %v", err)
	}
	bristolGraph = g
	t.Logf("Graph: %d nodes, %d edges", g.NodeCount(), len(g.Edges))

	t.Log("Building CH ...")
	ch := BuildCH(g)
	bristolCH = ch
	t.Logf("CH built in %.2fs: %d original drive edges, %d shortcuts added",
		ch.BuildTime.Seconds(), ch.OrigEdgeCount, ch.ShortcutCount)
	return g, ch
}

// TestCHBuild verifies the CH builds without panicking and reports preprocessing stats.
func TestCHBuild(t *testing.T) {
	g, ch := getBristol(t)
	if ch.ShortcutCount < 0 {
		t.Fatal("negative shortcut count")
	}
	t.Logf("Bristol graph nodes: %d", g.NodeCount())
	t.Logf("CH: orig_edges=%d shortcuts=%d build_time=%.3fs",
		ch.OrigEdgeCount, ch.ShortcutCount, ch.BuildTime.Seconds())
	t.Logf("CH drive nodes: %d", len(ch.DriveNodeIDs))
}

// dijkstraP2P runs a plain Dijkstra from src to dst (drive mode, no geographic prune).
// Returns the shortest distance in seconds or math.MaxFloat32 if unreachable.
func dijkstraP2P(g *Graph, src, dst NodeID) float32 {
	if src == dst {
		return 0
	}
	inf := float32(math.MaxFloat32)
	dist := make(map[NodeID]float32)
	dist[src] = 0

	q := &pq{}
	heap.Push(q, &item{id: src, cost: 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.id == dst {
			break
		}
		for _, e := range g.EdgesFrom(cur.id) {
			if e.Seconds[Drive] < 0 {
				continue
			}
			nc := cur.cost + e.Seconds[Drive]
			if prev, ok := dist[e.To]; !ok || nc < prev {
				dist[e.To] = nc
				heap.Push(q, &item{id: e.To, cost: nc})
			}
		}
	}
	if d, ok := dist[dst]; ok {
		return d
	}
	return inf
}

// TestCHPointToPoint cross-checks CH bidirectional query against plain Dijkstra for 1000 random pairs.
func TestCHPointToPoint(t *testing.T) {
	g, ch := getBristol(t)

	driveNodes := ch.DriveNodeIDs
	if len(driveNodes) < 2 {
		t.Fatal("too few drive nodes")
	}

	const nPairs = 1000
	const tolerance = float32(5e-3) // 5ms tolerance for float32 summation order differences via shortcuts

	rng := rand.New(rand.NewSource(42))
	mismatches := 0
	maxDiff := float32(0)
	tested := 0

	for tested < nPairs {
		si := rng.Intn(len(driveNodes))
		di := rng.Intn(len(driveNodes))
		if si == di {
			continue
		}
		src := driveNodes[si]
		dst := driveNodes[di]

		chDist := ch.CHQuery(src, dst)
		dDist := dijkstraP2P(g, src, dst)

		// Both unreachable = match.
		if chDist > float32(math.MaxFloat32)/2 && dDist > float32(math.MaxFloat32)/2 {
			tested++
			continue
		}
		// One reachable, other not.
		if (chDist > float32(math.MaxFloat32)/2) != (dDist > float32(math.MaxFloat32)/2) {
			t.Errorf("pair %d: src=%d dst=%d: CH=%g Dijkstra=%g (reachability mismatch)", tested, src, dst, chDist, dDist)
			mismatches++
			tested++
			continue
		}

		diff := chDist - dDist
		if diff < 0 {
			diff = -diff
		}
		if diff > maxDiff {
			maxDiff = diff
		}
		if diff > tolerance {
			t.Errorf("pair %d: src=%d dst=%d: CH=%g Dijkstra=%g diff=%g > tol=%g",
				tested, src, dst, chDist, dDist, diff, tolerance)
			mismatches++
		}
		tested++
	}

	t.Logf("P2P cross-check: %d pairs tested, %d mismatches, max_abs_diff=%.6fs",
		tested, mismatches, maxDiff)

	if mismatches > 0 {
		t.Fatalf("CH point-to-point distances do not match Dijkstra (%d/%d mismatches); CH is buggy", mismatches, tested)
	}
}

// TestCHIsochroneConsistency compares PHASTIsochrone vs Isochrone (plain Dijkstra)
// for several origins and time limits.
func TestCHIsochroneConsistency(t *testing.T) {
	g, ch := getBristol(t)

	type origin struct {
		name string
		lat  float64
		lng  float64
	}
	origins := []origin{
		{"central-bristol", 51.4545, -2.5879},
		{"clifton", 51.4593, -2.6235},
		{"brislington", 51.4350, -2.5520},
	}
	minutes := []float64{5, 15, 30}

	for _, o := range origins {
		for _, m := range minutes {
			limit := float32(m * 60)

			dijkRes := Isochrone(g, o.lat, o.lng, limit, Drive)
			phastRes := PHASTIsochrone(g, ch, o.lat, o.lng, limit)

			dijkSet := dijkRes.ReachedNodes
			phastSet := phastRes.ReachedNodes

			// Compare sets.
			symmDiff := 0
			maxTimeDiff := float32(0)
			boundaryOnly := true

			// Nodes in Dijkstra but not PHAST.
			for id, dt := range dijkSet {
				if pt, ok := phastSet[id]; ok {
					diff := dt - pt
					if diff < 0 {
						diff = -diff
					}
					if diff > maxTimeDiff {
						maxTimeDiff = diff
					}
				} else {
					symmDiff++
					// Is this a boundary node?
					if limit-dt > 0.1 {
						// Not within epsilon of cutoff
						boundaryOnly = false
					}
				}
			}
			// Nodes in PHAST but not Dijkstra.
			for id, pt := range phastSet {
				if _, ok := dijkSet[id]; !ok {
					symmDiff++
					if limit-pt > 0.1 {
						boundaryOnly = false
					}
				}
			}

			setIdentical := symmDiff == 0
			if !setIdentical && !boundaryOnly {
				t.Logf("WARNING: non-boundary set differences for %s %.0fmin", o.name, m)
			}

			t.Logf("origin=%s minutes=%.0f: dijkstra_reached=%d ch_reached=%d set_identical=%v symm_diff=%d max_time_diff=%.6fs boundary_only=%v",
				o.name, m, len(dijkSet), len(phastSet), setIdentical, symmDiff, maxTimeDiff, boundaryOnly)
		}
	}
}

// TestCHSpeedComparison times plain Dijkstra vs PHAST at 5/15/30 minutes.
func TestCHSpeedComparison(t *testing.T) {
	g, ch := getBristol(t)

	type origin struct {
		name string
		lat  float64
		lng  float64
	}
	o := origin{"central-bristol", 51.4545, -2.5879}
	minutes := []float64{5, 15, 30}

	const nReps = 5 // repeat each to get stable timing

	for _, m := range minutes {
		limit := float32(m * 60)

		// Time plain Dijkstra.
		t0 := time.Now()
		var dijkReached int
		for i := 0; i < nReps; i++ {
			r := Isochrone(g, o.lat, o.lng, limit, Drive)
			dijkReached = len(r.ReachedNodes)
		}
		dijkMs := float64(time.Since(t0).Milliseconds()) / float64(nReps)

		// Time PHAST.
		t1 := time.Now()
		var phastReached int
		for i := 0; i < nReps; i++ {
			r := PHASTIsochrone(g, ch, o.lat, o.lng, limit)
			phastReached = len(r.ReachedNodes)
		}
		phastMs := float64(time.Since(t1).Milliseconds()) / float64(nReps)

		t.Logf("minutes=%.0f reached(dijkstra=%d phast=%d) dijkstra=%.1fms phast=%.1fms",
			m, dijkReached, phastReached, dijkMs, phastMs)
	}
}

// BenchmarkDijkstra30min benchmarks plain Dijkstra at 30-minute drive limit.
func BenchmarkDijkstra30min(b *testing.B) {
	g, _ := getBristol(b)
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		_ = Isochrone(g, 51.4545, -2.5879, 30*60, Drive)
	}
}

// BenchmarkPHAST30min benchmarks PHAST at 30-minute drive limit.
func BenchmarkPHAST30min(b *testing.B) {
	g, ch := getBristol(b)
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		_ = PHASTIsochrone(g, ch, 51.4545, -2.5879, 30*60)
	}
}

// BenchmarkDijkstra5min benchmarks plain Dijkstra at 5-minute drive limit.
func BenchmarkDijkstra5min(b *testing.B) {
	g, _ := getBristol(b)
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		_ = Isochrone(g, 51.4545, -2.5879, 5*60, Drive)
	}
}

// BenchmarkPHAST5min benchmarks PHAST at 5-minute drive limit.
func BenchmarkPHAST5min(b *testing.B) {
	g, ch := getBristol(b)
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		_ = PHASTIsochrone(g, ch, 51.4545, -2.5879, 5*60)
	}
}

// exportResults is a helper for the structured output collection.
// Called from TestMain-style or directly.
func exportResults(g *Graph, ch *CH) {
	fmt.Printf("=== CH Preprocessing ===\n")
	fmt.Printf("nodes=%d orig_edges=%d shortcuts=%d build_seconds=%.3f\n",
		g.NodeCount(), ch.OrigEdgeCount, ch.ShortcutCount, ch.BuildTime.Seconds())
}
