package main

import (
	"math"
	"testing"
)

// buildBristolEngine builds the full stage-2 stack over the bristol fixture.
func buildBristolEngine(t *testing.T) (*Graph, *Stage2Engine) {
	t.Helper()
	g := loadBristol(t)
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 3000, 0.25)
	rm := BuildRegionMatrices(ov, part)
	return g, NewStage2Engine(g, ov, part, rm)
}

func TestStage2QueryExactnessBristol(t *testing.T) {
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
			if nearestNodeForMode(g, float64(nd.Lat), float64(nd.Lng), Drive) == v {
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
			origin := nearestNodeForMode(g, tc.lat, tc.lng, Drive)
			if origin == noNode {
				t.Fatal("no origin snap")
			}
			base := baseDriveDijkstra(g, origin, initialCostFor(Drive), tc.T)

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
