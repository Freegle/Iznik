package isochrone

import (
	"testing"
)

// ---------------------------------------------------------------------------
// ReachRadiusMetres - edge cases not covered by score_test.go: a ring vertex
// with too few fields, and a ring vertex whose fields don't parse as floats.
// Both must be skipped rather than aborting the whole scan.
// ---------------------------------------------------------------------------

func TestReachRadiusMetres_SkipsMalformedVertices(t *testing.T) {
	// First vertex has only one field (skipped by the len(parts)<2 guard),
	// second vertex has non-numeric fields (skipped by the ParseFloat guard),
	// third vertex is the only valid one, 1 degree of longitude from the origin.
	wkt := "POLYGON((5, abc def, 1 0))"
	got := ReachRadiusMetres(0, 0, wkt, 30000)
	want := HaversineMetres(0, 0, 0, 1)
	approxScore(t, "malformed vertices skipped, valid vertex used", got, want, 1)
}

func TestReachRadiusMetres_AllVerticesMalformedFallsBackToDefault(t *testing.T) {
	// Every vertex is unparseable, so maxDist never rises above 0 and the
	// function must fall back to defaultM.
	wkt := "POLYGON((abc def, ghi, 5))"
	got := ReachRadiusMetres(51.5, -0.1, wkt, 9999)
	approxScore(t, "all vertices malformed -> default", got, 9999, 1e-9)
}
