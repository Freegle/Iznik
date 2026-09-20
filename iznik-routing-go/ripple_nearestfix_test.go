package main

// The real per-post bottleneck is calling nearestNodeForMode once per freegler
// (N expanding-radius grid searches) to look up each freegler's drive-time. This
// measures replacing it with an O(1) lookup into a time-grid built once from the
// isochrone's reached set, sweeping the grid resolution to expose the
// speed-vs-accuracy tradeoff on the real UK graph.
//
//   RUN_UK_PROFILE=1 CGO_ENABLED=0 go test -run TestUKNearestFix -v -timeout 1200s

import (
	"math"
	"os"
	"sort"
	"testing"
	"time"
)

func buildTimeGrid(g *Graph, reached map[NodeID]float32, res float64) map[[2]int32]float32 {
	grid := make(map[[2]int32]float32, len(reached))
	for nid, t := range reached {
		n := g.Nodes[nid]
		key := [2]int32{int32(math.Floor(float64(n.Lat) / res)), int32(math.Floor(float64(n.Lng) / res))}
		if cur, ok := grid[key]; !ok || t < cur {
			grid[key] = t
		}
	}
	return grid
}

// gridTime: a freegler's drive-time = the min time in its OWN cell; only if that
// cell is empty do we fall back to the 8 neighbours (so we don't pull in faster
// distant nodes when the freegler's own cell is populated).
func gridTime(grid map[[2]int32]float32, res, lat, lng float64) (float32, bool) {
	r := int32(math.Floor(lat / res))
	c := int32(math.Floor(lng / res))
	if t, ok := grid[[2]int32{r, c}]; ok {
		return t, true
	}
	best := float32(-1)
	found := false
	for dr := int32(-1); dr <= 1; dr++ {
		for dc := int32(-1); dc <= 1; dc++ {
			if t, ok := grid[[2]int32{r + dr, c + dc}]; ok {
				if !found || t < best {
					best, found = t, true
				}
			}
		}
	}
	return best, found
}

func TestUKNearestFix(t *testing.T) {
	if os.Getenv("RUN_UK_PROFILE") == "" {
		t.Skip("set RUN_UK_PROFILE=1")
	}
	g := ukLoad(t)
	secs := float32(30 * 60)

	for _, o := range []struct {
		name     string
		lat, lng float64
	}{
		{"London-central", 51.5074, -0.1278},
		{"Birmingham", 52.4862, -1.8904},
		{"Reading-suburb", 51.4543, -0.9781},
	} {
		iso := Isochrone(g, o.lat, o.lng, secs)
		reached := len(iso.ReachedNodes)
		if reached == 0 {
			continue
		}
		ids := make([]NodeID, 0, reached)
		for nid := range iso.ReachedNodes {
			ids = append(ids, nid)
		}

		const N = 50000
		type pt struct{ lat, lng float64 }
		pts := make([]pt, N)
		for i := 0; i < N; i++ {
			nd := g.Nodes[ids[(i*7919)%len(ids)]]
			pts[i] = pt{float64(nd.Lat) + float64((i%7)-3)*0.0004, float64(nd.Lng) + float64((i%5)-2)*0.0004}
		}

		// Reference (current code): nearestNodeForMode per freegler, exact.
		t0 := time.Now()
		ref := make([]float32, N)
		refResolved := 0
		for i, p := range pts {
			nid := nearestDriveNode(g, p.lat, p.lng)
			if nid != noNode {
				if tt, ok := iso.ReachedNodes[nid]; ok {
					ref[i] = tt
					refResolved++
					continue
				}
			}
			ref[i] = -1
		}
		dRef := time.Since(t0)
		t.Logf("%-16s reached=%7d  REFERENCE nearestNode×%d = %v  (resolved=%d)",
			o.name, reached, N, dRef.Round(time.Millisecond), refResolved)

		for _, res := range []float64{0.01, 0.002, 0.001, 0.0005} {
			t0 = time.Now()
			grid := buildTimeGrid(g, iso.ReachedNodes, res)
			dBuild := time.Since(t0)

			t0 = time.Now()
			diffs := make([]float64, 0, N)
			var maxDiff float64
			resolved, falsePos, falseNeg := 0, 0, 0
			for i, p := range pts {
				tt, ok := gridTime(grid, res, p.lat, p.lng)
				switch {
				case ok && ref[i] >= 0:
					resolved++
					d := math.Abs(float64(tt - ref[i]))
					diffs = append(diffs, d)
					if d > maxDiff {
						maxDiff = d
					}
				case ok && ref[i] < 0:
					falsePos++ // grid says reachable, exact says not
				case !ok && ref[i] >= 0:
					falseNeg++ // grid says not, exact says reachable
				}
			}
			dLook := time.Since(t0)

			sort.Float64s(diffs)
			mean, p50, p95 := 0.0, 0.0, 0.0
			if len(diffs) > 0 {
				s := 0.0
				for _, d := range diffs {
					s += d
				}
				mean = s / float64(len(diffs))
				p50 = diffs[len(diffs)*50/100]
				p95 = diffs[len(diffs)*95/100]
			}
			t.Logf("  res=%.4f (~%4.0fm) cells=%7d | build=%5v + lookup=%5v = %6v  speedup=%6.1f× | drive-time err mean=%5.0fs p50=%4.0fs p95=%5.0fs max=%5.0fs | falsePos=%d falseNeg=%d",
				res, res*111000, len(grid),
				dBuild.Round(time.Millisecond), dLook.Round(time.Millisecond), (dBuild + dLook).Round(time.Millisecond),
				float64(dRef)/float64(dBuild+dLook),
				mean, p50, p95, maxDiff, falsePos, falseNeg)
		}
	}
}
