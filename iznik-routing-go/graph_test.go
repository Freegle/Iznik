package main

import (
	"testing"
)

const bristolPBF = "testdata/bristol.osm.pbf"

func TestBuildGraph_LoadsBristol(t *testing.T) {
	g, err := BuildGraph(bristolPBF)
	if err != nil {
		t.Fatalf("BuildGraph: %v", err)
	}
	if len(g.Nodes) < 10000 {
		t.Errorf("expected ≥10k nodes, got %d", len(g.Nodes))
	}
	if len(g.Edges) < 10000 {
		t.Errorf("expected ≥10k edge-lists, got %d", len(g.Edges))
	}
	t.Logf("nodes=%d edge-lists=%d", len(g.Nodes), len(g.Edges))
}

func TestBuildGraph_NodesHaveCoords(t *testing.T) {
	g, err := BuildGraph(bristolPBF)
	if err != nil {
		t.Fatal(err)
	}
	for id, n := range g.Nodes {
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
	g, err := BuildGraph(bristolPBF)
	if err != nil {
		t.Fatal(err)
	}
	walkEdges, cycleEdges, driveEdges := 0, 0, 0
	for _, edges := range g.Edges {
		for _, e := range edges {
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
	// More drive edges than walk-only footpaths, fewer than all edges
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
