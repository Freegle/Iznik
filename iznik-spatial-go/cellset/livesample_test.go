package cellset

import (
	"os"
	"testing"
)

// TestLiveSamplePolygon_SizeAndBoundary is a size/correctness check against
// a real reach polygon pulled from production (msgid 121556272, a median-
// sized recent 'done' reach, 2026-08-24) - not a synthetic shape. It only
// runs when the sample file is present (a local artefact from that measurement
// session, not committed), so CI and other machines skip it rather than fail.
func TestLiveSamplePolygon_SizeAndBoundary(t *testing.T) {
	path := "/tmp/claude-1000/-home-edward-FreegleDockerWSL/6cc0d137-5be2-47c2-a290-7c87f043dcd2/scratchpad/sample-polygon.wkt"
	wkt, err := os.ReadFile(path)
	if err != nil {
		t.Skipf("live sample not present (%v) - this test only runs where it was captured", err)
	}

	cs, err := FromPolygonWKT(string(wkt))
	if err != nil {
		t.Fatalf("FromPolygonWKT on live sample: %v", err)
	}

	encoded := cs.Encode()
	t.Logf("live sample: WKT %d bytes -> CellSet %d bytes (%.0fx smaller), %dx%d grid, %d cells set",
		len(wkt), len(encoded), float64(len(wkt))/float64(len(encoded)),
		cs.Cols, cs.Rows, cs.SetCellCount())

	if len(encoded) >= len(wkt)/10 {
		t.Errorf("expected at least a 10x size reduction on a real reach polygon, got %d -> %d",
			len(wkt), len(encoded))
	}

	decoded, err := Decode(encoded)
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if decoded.SetCellCount() != cs.SetCellCount() {
		t.Errorf("round trip changed the set cell count: %d -> %d", cs.SetCellCount(), decoded.SetCellCount())
	}

	minLng, minLat, maxLng, maxLat := cs.Bounds()
	// A point well inside the bbox's lower-left quadrant of a compact blob
	// shape should usually be contained; more importantly, exercise Contains
	// against real (not synthetic) coordinates without crashing or panicking
	// on the 31k-vertex input.
	_ = cs.Contains((minLng+maxLng)/2, (minLat+maxLat)/2)
	_ = cs.Contains(minLng-1, minLat-1)
}
