package main

import (
	"path/filepath"
	"reflect"
	"testing"
)

func TestReachSnapshotRoundTrip(t *testing.T) {
	g := makeTestGrid(nil)
	ov := BuildOverlay(g)

	path := filepath.Join(t.TempDir(), "graph.snap")
	if err := SaveReachSnapshot(path, g, ov); err != nil {
		t.Fatalf("save: %v", err)
	}
	g2, ov2, err := LoadReachSnapshot(path)
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if !reflect.DeepEqual(g.Nodes, g2.Nodes) {
		t.Fatal("Nodes differ after round trip")
	}
	if !reflect.DeepEqual(g.EdgeStart, g2.EdgeStart) {
		t.Fatal("EdgeStart differs after round trip")
	}
	if !reflect.DeepEqual(g.Edges, g2.Edges) {
		t.Fatal("Edges differ after round trip")
	}
	if !reflect.DeepEqual(g.DriveSnappable, g2.DriveSnappable) {
		t.Fatal("DriveSnappable differs after round trip")
	}
	if !reflect.DeepEqual(ov.BaseNode, ov2.BaseNode) || !reflect.DeepEqual(ov.Idx, ov2.Idx) {
		t.Fatal("overlay node mapping differs after round trip")
	}
	if !reflect.DeepEqual(ov.EdgeStart, ov2.EdgeStart) || !reflect.DeepEqual(ov.Edges, ov2.Edges) {
		t.Fatal("overlay CSR differs after round trip")
	}
	if !reflect.DeepEqual(ov.ChainEndA, ov2.ChainEndA) || !reflect.DeepEqual(ov.ChainEndB, ov2.ChainEndB) ||
		!reflect.DeepEqual(ov.OffFromA, ov2.OffFromA) || !reflect.DeepEqual(ov.OffFromB, ov2.OffFromB) {
		t.Fatal("chain table differs after round trip")
	}

	// The rebuilt grid must answer nearest-node queries identically.
	want := nearestDriveNode(g, 51.4545, -2.5879)
	got := nearestDriveNode(g2, 51.4545, -2.5879)
	if want != got {
		t.Fatalf("nearest node after round trip: %d vs %d", got, want)
	}
}
