package main

import (
	"math"
	"testing"
)

// buildBristolEngine builds the full stage-2 stack over the bristol fixture.
func buildBristolEngine(t *testing.T) (*Graph, *ReachEngine) {
	t.Helper()
	g := loadBristol(t)
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 3000, 0.25)
	rm := BuildRegionMatrices(ov, part)
	return g, NewReachEngine(g, ov, part, rm)
}

func TestReachQueryExactnessBristol(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	cases := []struct {
		name     string
		lat, lng float64
		T        float32
	}{
		{"centre-15min", 51.4545, -2.5879, 900},
		{"north-10min", 51.4900, -2.5900, 600},
		{"east-20min", 51.4600, -2.5200, 1200},
	}

	// Add a chain-node origin: find an absorbed node with a drive chain.
	for v := NodeID(1); v <= NodeID(g.NodeCount()); v++ {
		if eng.Ov.Idx[v] == 0 && eng.Ov.ChainEndA[v] != 0 && eng.Ov.OffFromA[v] > 0 && eng.Ov.OffFromB[v] > 0 {
			nd := g.Nodes[v]
			// Only use it if snapping from its own coords lands on it.
			if nearestDriveNode(g, float64(nd.Lat), float64(nd.Lng)) == v {
				cases = append(cases, struct {
					name     string
					lat, lng float64
					T        float32
				}{"chain-origin", float64(nd.Lat), float64(nd.Lng), 700})
				break
			}
		}
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			lbl := eng.QueryLabels(tc.lat, tc.lng, tc.T)
			origin := nearestDriveNode(g, tc.lat, tc.lng)
			if origin == noNode {
				t.Fatal("no origin snap")
			}
			base := baseDriveDijkstra(g, origin, driveStartupSecs, tc.T)

			checked := 0
			for id, want := range base {
				got := eng.ArrivalAtBaseNode(lbl, id)
				if math.Abs(float64(got-want)) > 0.01 {
					t.Fatalf("node %d arrival mismatch: engine %.4f vs base %.4f (junction=%v)",
						id, got, want, eng.Ov.Idx[id] != 0)
				}
				checked++
			}
			if checked < 100 {
				t.Fatalf("degenerate: only %d nodes reached", checked)
			}

			// Unreached probes: nodes outside base must not be claimed within T.
			probes, over := 0, 0
			for id := NodeID(1); id <= NodeID(g.NodeCount()) && probes < 5000; id += 37 {
				if _, in := base[id]; in {
					continue
				}
				// Only drive-relevant nodes are meaningful probes.
				if eng.Ov.Idx[id] == 0 && eng.Ov.ChainEndA[id] == 0 {
					continue
				}
				probes++
				if got := eng.ArrivalAtBaseNode(lbl, id); got <= tc.T {
					over++
					t.Fatalf("node %d claimed within T (%.2f <= %.2f) but base Dijkstra did not reach it", id, got, tc.T)
				}
			}

			// Fully-in soundness: every junction of a Full region must have been
			// reached by the base Dijkstra within T.
			fullLeaves, fullNodes := 0, 0
			for leaf, rl := range lbl.Reached {
				if !rl.Full {
					continue
				}
				fullLeaves++
				for _, oi := range eng.Part.LeafNodes[leaf] {
					fullNodes++
					baseID := eng.Ov.BaseNode[oi]
					if _, in := base[baseID]; !in {
						t.Fatalf("leaf %d marked Full but junction %d (overlay %d) not reached by base within T", leaf, baseID, oi)
					}
				}
			}
			t.Logf("%s: %d nodes exact, %d unreached probes clean, %d Full leaves (%d junctions) sound; local %.1fms boundary %.1fms label %.1fms",
				tc.name, checked, probes, fullLeaves, fullNodes, lbl.LocalMs, lbl.BoundaryMs, lbl.LabelMs)
		})
	}
}

// TestReachMetresBristol: road metres from the engine must track the base
// search's DistM-style metres. Seconds are exact; metres follow the winning
// path, so equal-seconds path ties allow small divergence — bounded here.
func TestReachMetresBristol(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	lbl := eng.QueryLabels(51.4545, -2.5879, 900)
	origin := nearestDriveNode(g, 51.4545, -2.5879)
	base, baseM := baseDriveDijkstraM(g, origin, driveStartupSecs, 900)

	checked, noMet, bigDev := 0, 0, 0
	var worstFrac float64
	for id, want := range base {
		s, m := eng.ArrivalAtBaseNodeM(lbl, id)
		if math.Abs(float64(s-want)) > 0.01 {
			t.Fatalf("node %d secs mismatch %v vs %v", id, s, want)
		}
		wm := baseM[id]
		if m == f32Inf {
			noMet++
			if noMet <= 5 {
				if oi := eng.Ov.Idx[id]; oi != 0 {
					leaf := eng.Part.LeafAt(oi)
					rl := lbl.Reached[leaf]
					_, hasOA := lbl.OriginArr[oi]
					t.Logf("noMet junction id=%d leaf=%d label=%v entryMet=%v originArr=%v secs=%.1f", id, leaf, rl != nil, rl != nil && rl.EntryMet != nil, hasOA, want)
				} else {
					a, b := eng.Ov.ChainEndA[id], eng.Ov.ChainEndB[id]
					ja, jma := eng.junctionArrivalM(lbl, a)
					jb, jmb := eng.junctionArrivalM(lbl, b)
					cma := chainMetresFromEnd(g, eng.Ov, a, id)
					cmb := chainMetresFromEnd(g, eng.Ov, b, id)
					t.Logf("noMet chain id=%d ends %d/%d endArr %.1f/%.1f endMet %.0f/%.0f chainMet %.0f/%.0f offs %.1f/%.1f secs=%.1f",
						id, a, b, ja, jb, jma, jmb, cma, cmb, offOf(eng.Ov.OffFromA[id]), offOf(eng.Ov.OffFromB[id]), want)
				}
			}
			continue
		}
		checked++
		dev := math.Abs(float64(m - wm))
		tol := math.Max(100, 0.05*float64(wm))
		if dev > tol {
			bigDev++
			if frac := dev / math.Max(1, float64(wm)); frac > worstFrac {
				worstFrac = frac
			}
			if bigDev <= 3 {
				t.Logf("metre deviation node %d: engine %.0fm vs base %.0fm (secs %.1f)", id, m, wm, want)
			}
		}
	}
	if checked < 1000 {
		t.Fatalf("degenerate: only %d metre answers (%d without metres)", checked, noMet)
	}
	// Equal-seconds ties can pick different geometry; allow a small fraction.
	if frac := float64(bigDev) / float64(checked); frac > 0.01 {
		t.Fatalf("%.2f%% of nodes deviate beyond max(100m, 5%%) (worst %.1f%%): metre propagation broken",
			100*frac, 100*worstFrac)
	}
	if noMet > checked/10 {
		t.Fatalf("metres missing on %d of %d reached nodes", noMet, noMet+checked)
	}
	t.Logf("metres OK: %d checked, %d tie deviations, %d without metres", checked, bigDev, noMet)
}

func TestQueryLabelsCached(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	fresh := eng.QueryLabels(51.4545, -2.5879, 900)
	c1 := eng.QueryLabelsCached(51.4545, -2.5879, 900)
	c2 := eng.QueryLabelsCached(51.4545, -2.5879, 900)
	if c1 != c2 {
		t.Fatal("second cached call should return the same object")
	}
	// Same answers as a fresh query at a spread of nodes.
	checked := 0
	for id := NodeID(1); id <= NodeID(g.NodeCount()); id += 97 {
		a := eng.ArrivalAtBaseNode(fresh, id)
		b := eng.ArrivalAtBaseNode(c1, id)
		if a != b {
			t.Fatalf("node %d: cached %v vs fresh %v", id, b, a)
		}
		checked++
	}
	if checked < 500 {
		t.Fatalf("degenerate: %d nodes", checked)
	}
	// Fractional budgets bypass the cache (still correct).
	f1 := eng.QueryLabelsCached(51.4545, -2.5879, 900.5)
	if f1 == c1 {
		t.Fatal("fractional budget must not reuse the whole-minute cache entry")
	}
}
