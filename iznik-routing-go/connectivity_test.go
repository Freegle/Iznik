package main

import (
	"os"
	"path/filepath"
	"testing"
)

func writeCSV(t *testing.T, body string) string {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, "conn.csv")
	if err := os.WriteFile(path, []byte(body), 0644); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestLoadConnectivity_LookupNearest(t *testing.T) {
	ci := LoadConnectivity(writeCSV(t, "lat,lng,conn\n51.45,-2.60,80\n53.74,-0.33,40\n"))
	if ci == nil {
		t.Fatal("LoadConnectivity returned nil")
	}
	if got := ci.Lookup(51.451, -2.601); got != 80 {
		t.Errorf("near Bristol centroid: want 80, got %d", got)
	}
	if got := ci.Lookup(53.741, -0.331); got != 40 {
		t.Errorf("near Hull centroid: want 40, got %d", got)
	}
}

func TestLoadConnectivity_UnknownFarAway(t *testing.T) {
	ci := LoadConnectivity(writeCSV(t, "lat,lng,conn\n51.45,-2.60,80\n"))
	// Off the coast of France — no centroid within range → 0 (unknown → plain isochrone).
	if got := ci.Lookup(48.0, 2.0); got != 0 {
		t.Errorf("far from any centroid: want 0 (unknown), got %d", got)
	}
}

func TestTagConnectivity_SetsNodeConn(t *testing.T) {
	g := makeLineGraph(3) // nodes clustered at ~51.45,-2.60
	ci := LoadConnectivity(writeCSV(t, "lat,lng,conn\n51.45,-2.60,77\n"))

	TagConnectivity(g, ci)

	for id := NodeID(1); id <= 3; id++ {
		if g.Nodes[id].Conn != 77 {
			t.Errorf("node %d Conn: want 77, got %d", id, g.Nodes[id].Conn)
		}
	}
}
