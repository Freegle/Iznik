package main

import (
	"os"
	"path/filepath"
	"testing"
	"unsafe"
)

// The index region is cast in place, so its record layout is load-bearing.
func TestLeafTablesIdxLayout(t *testing.T) {
	if sz := unsafe.Sizeof(ltIdx{}); sz != leafTablesIdxLen {
		t.Fatalf("ltIdx is %d bytes, format needs %d", sz, leafTablesIdxLen)
	}
}

// The artifact must answer every reach product identically to the lazy path:
// same arrivals, same metres, same isochrone node sets. Exact equality is the
// right bar because both paths run the same builders on the same inputs — any
// drift means the file layout or the read path is wrong.
func TestLeafTablesParityBristol(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	// Two engines over the SAME graph/partition/matrices (PartitionOverlay is
	// not deterministic across builds, so independently-built fixtures carry
	// different partitions and can never share an artifact): one serves from
	// its lazy Dijkstras, the other from the file the first one builds. The
	// engines' caches are independent; the inputs are immutable.
	g := loadBristol(t)
	ov := BuildOverlay(g)
	part := PartitionOverlay(g, ov, 3000, 0.25)
	rm := BuildRegionMatrices(ov, part)
	lazy := NewReachEngine(g, ov, part, rm)
	mapped := NewReachEngine(g, ov, part, rm)

	path := filepath.Join(t.TempDir(), leafTablesName)
	if err := BuildLeafTablesFile(path, lazy, 4); err != nil {
		t.Fatalf("build: %v", err)
	}
	lt, err := LoadLeafTables(path, mapped.partFP, len(mapped.Part.LeafNodes))
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	defer lt.Close()
	mapped.leafTabs.Store(lt)

	origins := []struct{ lat, lng float64 }{
		{51.4545, -2.5879},
		{51.4900, -2.5900},
		{51.4600, -2.5200},
	}
	checked := 0
	for _, o := range origins {
		ll := lazy.QueryLabels(o.lat, o.lng, 1200)
		lm := mapped.QueryLabels(o.lat, o.lng, 1200)
		for id := NodeID(1); id <= NodeID(g.NodeCount()); id += 61 {
			ls, lmet := lazy.ArrivalAtBaseNodeM(ll, id)
			ms, mmet := mapped.ArrivalAtBaseNodeM(lm, id)
			if ls != ms || lmet != mmet {
				t.Fatalf("origin %v node %d: lazy (%v,%v) vs mapped (%v,%v)", o, id, ls, lmet, ms, mmet)
			}
			checked++
		}
		// Isochrone expansion reads whole table rows plus the local→overlay
		// node mapping — the other artifact-served shape.
		ln := lazy.ReachedNodes(ll, 900)
		mn := mapped.ReachedNodes(lm, 900)
		if len(ln) != len(mn) {
			t.Fatalf("origin %v: reached %d lazy vs %d mapped", o, len(ln), len(mn))
		}
		for v, a := range ln {
			if b, ok := mn[v]; !ok || a != b {
				t.Fatalf("origin %v node %d: reached %v lazy vs %v,%v mapped", o, v, a, b, ok)
			}
		}
	}
	if checked < 1000 {
		t.Fatalf("degenerate parity sweep: %d nodes", checked)
	}

	// The mapped engine must not have needed the lazy Dijkstra for entry
	// tables: every cached region should be artifact-backed until an
	// arbitrary-source path (the origin's own seed leaf) demanded a subgraph.
	mapped.tables.mu.Lock()
	backed := 0
	for _, rt := range mapped.tables.m {
		if rt.ls == nil {
			backed++
		}
	}
	total := len(mapped.tables.m)
	mapped.tables.mu.Unlock()
	if backed == 0 {
		t.Fatalf("no artifact-backed tables in the cache (%d entries): attach did not take effect", total)
	}
}

// A stale artifact (wrong partition, wrong shape, truncated) must be refused,
// leaving the lazy path serving — never half-trusted.
func TestLeafTablesRejectsBadArtifacts(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	_, eng := buildBristolEngine(t)
	dir := t.TempDir()
	path := filepath.Join(dir, leafTablesName)
	if err := BuildLeafTablesFile(path, eng, 2); err != nil {
		t.Fatalf("build: %v", err)
	}

	if _, err := LoadLeafTables(path, eng.partFP+1, len(eng.Part.LeafNodes)); err == nil {
		t.Fatal("wrong fingerprint accepted")
	}
	if _, err := LoadLeafTables(path, eng.partFP, len(eng.Part.LeafNodes)+1); err == nil {
		t.Fatal("wrong leaf count accepted")
	}

	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	trunc := filepath.Join(dir, "trunc.snap")
	if err := os.WriteFile(trunc, data[:len(data)/2], 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadLeafTables(trunc, eng.partFP, len(eng.Part.LeafNodes)); err == nil {
		t.Fatal("truncated artifact accepted")
	}

	garbled := filepath.Join(dir, "garbled.snap")
	data[0] ^= 0xFF
	if err := os.WriteFile(garbled, data, 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadLeafTables(garbled, eng.partFP, len(eng.Part.LeafNodes)); err == nil {
		t.Fatal("bad magic accepted")
	}
}
