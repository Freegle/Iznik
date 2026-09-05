package main

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
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
	if !reflect.DeepEqual(ov.BaseNode, ov2.BaseNode) || !reflect.DeepEqual(ov.Ref, ov2.Ref) {
		t.Fatal("overlay node mapping differs after round trip")
	}
	if !reflect.DeepEqual(ov.EdgeStart, ov2.EdgeStart) || !reflect.DeepEqual(ov.Edges, ov2.Edges) {
		t.Fatal("overlay CSR differs after round trip")
	}
	if !reflect.DeepEqual(ov.ChainEndB, ov2.ChainEndB) ||
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

// TestSnapshotStaleAgainstPBF covers the trap sitting on the rollout path for any
// map fix: the snapshot wins over the extract at boot, so refreshing the extract
// without rebuilding the artifacts changes nothing, and used to say nothing.
func TestSnapshotStaleAgainstPBF(t *testing.T) {
	dir := t.TempDir()
	snap := filepath.Join(dir, "graph.snap")
	pbf := filepath.Join(dir, "uk-latest.osm.pbf")
	for _, p := range []string{snap, pbf} {
		if err := os.WriteFile(p, []byte("x"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("OSM_PBF_PATH", pbf)

	base := time.Now().Add(-24 * time.Hour)
	setTime := func(p string, at time.Time) {
		t.Helper()
		if err := os.Chtimes(p, at, at); err != nil {
			t.Fatal(err)
		}
	}

	// Snapshot built after the extract: current, so no complaint.
	setTime(pbf, base)
	setTime(snap, base.Add(time.Hour))
	if got := snapshotStaleAgainstPBF(snap); got != "" {
		t.Errorf("snapshot newer than extract: got %q, want no complaint", got)
	}

	// Extract refreshed since the snapshot was built: stale.
	setTime(pbf, base.Add(2*time.Hour))
	got := snapshotStaleAgainstPBF(snap)
	if got == "" {
		t.Fatal("extract newer than snapshot: got no complaint, want one")
	}
	if !strings.Contains(got, snap) || !strings.Contains(got, pbf) {
		t.Errorf("complaint should name both files, got %q", got)
	}

	// No extract on disk: nothing to compare against, so say nothing.
	if err := os.Remove(pbf); err != nil {
		t.Fatal(err)
	}
	if got := snapshotStaleAgainstPBF(snap); got != "" {
		t.Errorf("no extract on disk: got %q, want no complaint", got)
	}
}
