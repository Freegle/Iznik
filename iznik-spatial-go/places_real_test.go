package main

import (
	"os"
	"runtime"
	"testing"
	"time"
)

// TestRealPlacesArtifact loads a real places artifact when PLACES_TEST_FILE is
// set (skips otherwise, like the rasterize env-gated tests) and reports the
// index's heap footprint and query latencies. Not a pass/fail gate beyond
// loading and answering — it exists so occupancy and speed are measured facts.
func TestRealPlacesArtifact(t *testing.T) {
	path := os.Getenv("PLACES_TEST_FILE")
	if path == "" {
		t.Skip("PLACES_TEST_FILE not set")
	}

	var before, after runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&before)

	start := time.Now()
	ix, err := loadPlacesFile(path)
	if err != nil {
		t.Fatalf("load: %v", err)
	}
	loadMs := time.Since(start).Milliseconds()

	runtime.GC()
	runtime.ReadMemStats(&after)
	t.Logf("entries=%d tokens=%d load=%dms heap=%.1fMB",
		len(ix.entries), len(ix.tokens), loadMs,
		float64(after.HeapAlloc-before.HeapAlloc)/1024/1024)

	queries := []string{"Kendal", "Ken", "St", "West Midlands", "Mancester", "batten&apos;s green", "Kenwyn, Cornwall"}
	for _, q := range queries {
		qStart := time.Now()
		res := ix.search(q, searchOpts{limit: 15})
		t.Logf("q=%-22s results=%2d in %s", q, len(res), time.Since(qStart))
	}

	if res := ix.search("Kendal", searchOpts{limit: 1}); len(res) == 0 || res[0].e.Name != "Kendal" {
		t.Fatalf("real artifact should answer Kendal")
	}
}
