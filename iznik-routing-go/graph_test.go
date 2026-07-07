package main

import (
	"testing"
)

const sampleLSOACSV = "testdata/bristol_lsoa.csv"

// makeTestGrid builds a 50×50 grid of nodes around Bristol city centre
// connected by bidirectional residential roads. Tests that previously
// required testdata/bristol.osm.pbf use this instead — no external files needed.
//
// Grid bounds: lat 51.430–51.479, lng −2.613–−2.564 (~70 m per cell).
// The query point (51.4545, −2.5879) used in algorithm tests maps to row 24,
// col 25 — well inside the grid and reachable from all sides.
func makeTestGrid(dep *DeprivationIndex) *Graph {
	const (
		rows      = 50
		cols      = 50
		latOrigin = 51.430
		lngOrigin = -2.613
		dLat      = 0.001 // ≈70 m per row
		dLng      = 0.001 // ≈70 m per col
	)
	idAt := func(r, c int) int64 { return int64(r*cols+c) + 1 }

	nodes := make([]RawNodeSpec, 0, rows*cols)
	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			nodes = append(nodes, RawNodeSpec{
				OSMID: idAt(r, c),
				Lat:   latOrigin + float64(r)*dLat,
				Lng:   lngOrigin + float64(c)*dLng,
			})
		}
	}

	ways := make([]RawWaySpec, 0, rows*(cols-1)+(rows-1)*cols)
	for r := 0; r < rows; r++ {
		for c := 0; c < cols-1; c++ {
			ways = append(ways, RawWaySpec{
				NodeIDs: []int64{idAt(r, c), idAt(r, c+1)},
				Highway: "residential",
			})
		}
	}
	for r := 0; r < rows-1; r++ {
		for c := 0; c < cols; c++ {
			ways = append(ways, RawWaySpec{
				NodeIDs: []int64{idAt(r, c), idAt(r+1, c)},
				Highway: "residential",
			})
		}
	}

	return BuildGraphFromRaw(nodes, ways, dep)
}

func TestBuildGraphFromRaw_NodeAndEdgeCount(t *testing.T) {
	g := makeTestGrid(nil)
	const want = 50 * 50
	if g.NodeCount() != want {
		t.Errorf("expected %d nodes, got %d", want, g.NodeCount())
	}
	// Horizontal: 50 rows × 49 bidir = 4900; Vertical: 49 rows × 50 bidir = 4900 → 9800 total.
	if len(g.Edges) < 9800 {
		t.Errorf("expected ≥9800 edges, got %d", len(g.Edges))
	}
	t.Logf("nodes=%d edges=%d", g.NodeCount(), len(g.Edges))
}

func TestBuildGraphFromRaw_NodesHaveCoords(t *testing.T) {
	g := makeTestGrid(nil)
	for id := NodeID(1); id < NodeID(len(g.Nodes)); id++ {
		n := g.Nodes[id]
		if n.Lat == 0 && n.Lng == 0 {
			t.Errorf("node %d has zero coords", id)
		}
		if n.Lat < 51.0 || n.Lat > 52.0 {
			t.Errorf("node %d lat %f out of Bristol range", id, n.Lat)
		}
		break // spot-check first node only
	}
}

func TestBuildGraphFromRaw_EdgeCounts(t *testing.T) {
	g := makeTestGrid(nil)
	walkEdges, cycleEdges, driveEdges := 0, 0, 0
	for id := NodeID(1); id < NodeID(len(g.Nodes)); id++ {
		for _, e := range g.EdgesFrom(id) {
			if e.Seconds[Walk] > 0 {
				walkEdges++
			}
			if e.Seconds[Cycle] > 0 {
				cycleEdges++
			}
			if e.Seconds[Drive] > 0 {
				driveEdges++
			}
		}
	}
	t.Logf("walk edges=%d cycle edges=%d drive edges=%d", walkEdges, cycleEdges, driveEdges)
	if walkEdges < 1000 {
		t.Errorf("expected ≥1k walk edges, got %d", walkEdges)
	}
	if driveEdges < 1000 {
		t.Errorf("expected ≥1k drive edges, got %d", driveEdges)
	}
	if driveEdges > walkEdges*2 {
		t.Errorf("suspiciously many drive edges vs walk: %d vs %d", driveEdges, walkEdges)
	}
}

func TestBuildGraphFromRaw_WithDeprivation(t *testing.T) {
	idx := LoadDeprivation(sampleLSOACSV)
	if idx == nil {
		t.Fatal("LoadDeprivation returned nil for sample CSV")
	}
	g := makeTestGrid(idx)
	tagged := 0
	for id := NodeID(1); id < NodeID(len(g.Nodes)); id++ {
		if g.Nodes[id].Quintile != 0 {
			tagged++
		}
	}
	if tagged == 0 {
		t.Error("expected some nodes with deprivation quintile, got 0")
	}
	t.Logf("tagged nodes with quintile: %d / %d", tagged, g.NodeCount())
}

func TestBuildGraphFromRaw_MotorwayExcludesWalkers(t *testing.T) {
	// Build a minimal two-node graph with only a motorway way.
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.45, Lng: -2.59},
		{OSMID: 2, Lat: 51.45, Lng: -2.58},
	}
	ways := []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "motorway", Oneway: true},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)

	found := false
	for _, e := range g.EdgesFrom(1) {
		if e.To == 2 {
			found = true
			if e.Seconds[Walk] > 0 {
				t.Error("motorway edge must exclude walkers (Seconds[Walk] must be ≤0)")
			}
			if e.Seconds[Drive] <= 0 {
				t.Error("motorway edge must allow driving (Seconds[Drive] must be >0)")
			}
		}
	}
	if !found {
		t.Error("expected edge from node 1 to node 2")
	}
	// Oneway: no reverse edge.
	for _, e := range g.EdgesFrom(2) {
		if e.To == 1 {
			t.Error("motorway is oneway — no reverse edge expected")
		}
	}
}

func TestHaversineM(t *testing.T) {
	// Bristol Temple Meads to Bristol city centre ≈ 800m
	d := haversineM(51.4491, -2.5832, 51.4545, -2.5879)
	if d < 500 || d > 1200 {
		t.Errorf("haversine gave %f m, expected ~800m", d)
	}
}
