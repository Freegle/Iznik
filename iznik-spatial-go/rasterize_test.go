package main

import (
	"os"
	"strings"
	"testing"

	"spatial-server/cellset"
)

func TestRasterizeWKT_EmptyIsRejected(t *testing.T) {
	if _, err := rasterizeWKT(""); err == nil {
		t.Error("empty WKT must be rejected")
	}
}

func TestRasterizeWKT_TooLargeIsRejected(t *testing.T) {
	huge := "POLYGON((" + strings.Repeat("0 0,", maxRasterizeWKTBytes/4+1) + "0 0))"
	if _, err := rasterizeWKT(huge); err == nil {
		t.Error("oversized WKT must be rejected")
	}
}

func TestRasterizeWKT_ProducesDecodableCellSet(t *testing.T) {
	b, err := rasterizeWKT("POLYGON((0 0,0.003 0,0.003 0.003,0 0.003,0 0))")
	if err != nil {
		t.Fatalf("rasterizeWKT: %v", err)
	}
	cs, err := cellset.Decode(b)
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if !cs.Contains(0.0015, 0.0015) {
		t.Error("centre of the square must be contained")
	}
}

func TestRasterizeWKT_LiveSampleShrinksByAnOrderOfMagnitude(t *testing.T) {
	// Env var, not a hard-coded path. This used to name a scratch
	// directory from the session that captured the sample, which no
	// longer exists - so the test skipped forever while looking like
	// coverage. Point REACH_SAMPLE_WKT at a real reach polygon to run it.
	path := os.Getenv("REACH_SAMPLE_WKT")
	if path == "" {
		t.Skip("set REACH_SAMPLE_WKT to a file holding one real reach polygon in WKT to run this")
	}
	wkt, err := os.ReadFile(path)
	if err != nil {
		t.Skipf("live sample not present (%v)", err)
	}
	out, err := rasterizeWKT(string(wkt))
	if err != nil {
		t.Fatalf("rasterizeWKT: %v", err)
	}
	t.Logf("%d bytes WKT -> %d bytes cellset (%.0fx)", len(wkt), len(out), float64(len(wkt))/float64(len(out)))
	if len(out) >= len(wkt)/10 {
		t.Errorf("expected >=10x reduction, got %d -> %d", len(wkt), len(out))
	}
}
