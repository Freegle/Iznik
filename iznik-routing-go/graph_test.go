package main

import (
	"testing"
)

const bristolPBF = "testdata/bristol.osm.pbf"
const sampleLSOACSV = "testdata/bristol_lsoa.csv"

func TestBuildGraph_LoadsBristol(t *testing.T) {
	g, err := BuildGraph(bristolPBF, nil)
	if err != nil {
		t.Fatalf("BuildGraph: %v", err)
	}
	if g.NodeCount() < 10000 {
		t.Errorf("expected ≥10k nodes, got %d", g.NodeCount())
	}
	if len(g.Edges) < 10000 {
		t.Errorf("expected ≥10k edges, got %d", len(g.Edges))
	}
	t.Logf("nodes=%d edges=%d", g.NodeCount(), len(g.Edges))
}

func TestBuildGraph_NodesHaveCoords(t *testing.T) {
	g, err := BuildGraph(bristolPBF, nil)
	if err != nil {
		t.Fatal(err)
	}
	for id := NodeID(1); id < NodeID(len(g.Nodes)); id++ {
		n := g.Nodes[id]
		if n.Lat == 0 && n.Lng == 0 {
			t.Errorf("node %d has zero coords", id)
		}
		// Bristol is roughly 51.4-51.5 N, 2.5-2.6 W
		if n.Lat < 51.0 || n.Lat > 52.0 {
			t.Errorf("node %d lat %f out of Bristol range", id, n.Lat)
		}
		break // spot-check first node
	}
}

func TestBuildGraph_EdgesHaveSensibleTimes(t *testing.T) {
	g, err := BuildGraph(bristolPBF, nil)
	if err != nil {
		t.Fatal(err)
	}
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

func TestHaversineM(t *testing.T) {
	// Bristol Temple Meads to Bristol city centre ≈ 800m
	d := haversineM(51.4491, -2.5832, 51.4545, -2.5879)
	if d < 500 || d > 1200 {
		t.Errorf("haversine gave %f m, expected ~800m", d)
	}
}

func TestBuildGraph_WithDeprivation(t *testing.T) {
	idx := LoadDeprivation(sampleLSOACSV)
	if idx == nil {
		t.Fatal("LoadDeprivation returned nil for sample CSV")
	}
	g, err := BuildGraph(bristolPBF, idx)
	if err != nil {
		t.Fatal(err)
	}
	// At least some nodes should have quintile data.
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
