package cellset

import (
	"encoding/binary"
	"testing"
)

// A simple 2x2-cell square, well inside one cell each way so there is no
// boundary ambiguity to reason about.
const unitSquareWKT = "POLYGON((0 0,0.0009 0,0.0009 0.0009,0 0.0009,0 0))"

func TestFromPolygonWKT_ContainsInteriorPoint(t *testing.T) {
	cs, err := FromPolygonWKT(unitSquareWKT)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	if !cs.Contains(0.00045, 0.00045) {
		t.Error("centre of the square must be contained")
	}
}

func TestFromPolygonWKT_ExcludesFarOutsidePoint(t *testing.T) {
	cs, err := FromPolygonWKT(unitSquareWKT)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	if cs.Contains(5, 5) {
		t.Error("a point far outside the bbox must not be contained")
	}
	if cs.Contains(-1, -1) {
		t.Error("a point before the bbox must not be contained")
	}
}

func TestEncodeDecode_RoundTrips(t *testing.T) {
	cs, err := FromPolygonWKT(unitSquareWKT)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	encoded := cs.Encode()
	decoded, err := Decode(encoded)
	if err != nil {
		t.Fatalf("Decode: %v", err)
	}
	if decoded.MinCol != cs.MinCol || decoded.MinRow != cs.MinRow ||
		decoded.Cols != cs.Cols || decoded.Rows != cs.Rows {
		t.Fatalf("decoded header mismatch: got (%d,%d,%d,%d) want (%d,%d,%d,%d)",
			decoded.MinCol, decoded.MinRow, decoded.Cols, decoded.Rows,
			cs.MinCol, cs.MinRow, cs.Cols, cs.Rows)
	}
	if !decoded.Contains(0.00045, 0.00045) {
		t.Error("decoded cellset lost the interior point")
	}
	if decoded.Contains(5, 5) {
		t.Error("decoded cellset gained a false positive far outside")
	}
}

func TestEncode_DeterministicForIdenticalInput(t *testing.T) {
	a, err := FromPolygonWKT(unitSquareWKT)
	if err != nil {
		t.Fatal(err)
	}
	b, err := FromPolygonWKT(unitSquareWKT)
	if err != nil {
		t.Fatal(err)
	}
	ea, eb := a.Encode(), b.Encode()
	if len(ea) != len(eb) {
		t.Fatalf("byte-identical input produced different-length output: %d vs %d", len(ea), len(eb))
	}
	for i := range ea {
		if ea[i] != eb[i] {
			t.Fatalf("byte-identical input produced different bytes at offset %d - content addressing (a future dedup step) requires this to hold", i)
		}
	}
}

func TestDecode_RejectsBadMagic(t *testing.T) {
	_, err := Decode([]byte{0, 1, 2, 3, 4, 5, 6, 7})
	if err == nil {
		t.Error("garbage input must not decode successfully")
	}
}

// Cols and Rows are each uint32, so a corrupt header can claim 1.8e19 cells.
// Refusing it means "fall back to the polygon", which every caller already
// handles; trying to allocate for it does not. MaxCells must match the other
// implementations, or a value one language accepts is rejected by another.
func TestDecode_RejectsAnAbsurdlyLargeGrid(t *testing.T) {
	b := make([]byte, headerSize)
	binary.LittleEndian.PutUint32(b[0:4], formatMagicV1)
	binary.LittleEndian.PutUint32(b[12:16], 0xFFFFFFFF) // cols
	binary.LittleEndian.PutUint32(b[16:20], 0xFFFFFFFF) // rows
	if _, err := Decode(b); err == nil {
		t.Fatal("a grid of 1.8e19 cells must be rejected, not allocated for")
	}
}

func TestDecode_RejectsTruncatedHeader(t *testing.T) {
	_, err := Decode([]byte{0x31, 0x4c, 0x45, 0x43}) // magic bytes only, no header fields
	if err == nil {
		t.Error("a header shorter than the fixed size must be rejected")
	}
}

// A polygon with a hole (a ring inside a ring) must exclude the hole's
// interior - the reach engine's own polygons are frequently multi-ring
// (secondary-group clips can carve a hole out of the middle of a reach).
func TestFromPolygonWKT_HoleIsExcluded(t *testing.T) {
	wkt := "POLYGON((0 0,0.003 0,0.003 0.003,0 0.003,0 0)," +
		"(0.001 0.001,0.002 0.001,0.002 0.002,0.001 0.002,0.001 0.001))"
	cs, err := FromPolygonWKT(wkt)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	if !cs.Contains(0.0005, 0.0005) {
		t.Error("a point in the outer ring but outside the hole must be contained")
	}
	if cs.Contains(0.0015, 0.0015) {
		t.Error("a point inside the hole must not be contained")
	}
}

// MULTIPOLYGON is a real shape the engine produces (e.g. after a clip splits
// a reach into two disjoint pieces); both parts must be captured.
func TestFromPolygonWKT_MultiPolygonBothPartsIncluded(t *testing.T) {
	wkt := "MULTIPOLYGON(((0 0,0.0009 0,0.0009 0.0009,0 0.0009,0 0))," +
		"((0.01 0.01,0.0109 0.01,0.0109 0.0109,0.01 0.0109,0.01 0.01)))"
	cs, err := FromPolygonWKT(wkt)
	if err != nil {
		t.Fatalf("FromPolygonWKT: %v", err)
	}
	if !cs.Contains(0.00045, 0.00045) {
		t.Error("first part must be contained")
	}
	if !cs.Contains(0.01045, 0.01045) {
		t.Error("second, disjoint part must be contained")
	}
	if cs.Contains(0.005, 0.005) {
		t.Error("the gap between the two parts must not be contained")
	}
}

func TestFromPolygonWKT_RejectsUnparseable(t *testing.T) {
	_, err := FromPolygonWKT("not a polygon")
	if err == nil {
		t.Error("garbage WKT must return an error, not a zero-value CellSet")
	}
}

func TestSetCellCount_MatchesActualArea(t *testing.T) {
	// A 30x30-cell square (0.009deg side at the 0.0003deg lattice) has a
	// known, exactly-computable set-cell count with no boundary ambiguity -
	// every cell's centre falls unambiguously inside or outside.
	wkt := "POLYGON((0 0,0.009 0,0.009 0.009,0 0.009,0 0))"
	cs, err := FromPolygonWKT(wkt)
	if err != nil {
		t.Fatal(err)
	}
	got := cs.SetCellCount()
	want := 30 * 30
	if got != want {
		t.Errorf("set cell count = %d, want %d (30x30 grid)", got, want)
	}
}
