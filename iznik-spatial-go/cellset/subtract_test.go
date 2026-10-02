package cellset

import "testing"

// A 10x10-cell square (0.003deg side) and a 5x5-cell square overlapping its
// right half, both from FromPolygonWKT so bounds/lattice alignment come from
// the real encoder, not hand-picked numbers.
const subtractBaseWKT = "POLYGON((0 0, 0.003 0, 0.003 0.003, 0 0.003, 0 0))"
const subtractOverlapWKT = "POLYGON((0.0015 0, 0.0045 0, 0.0045 0.003, 0.0015 0.003, 0.0015 0))"

func TestSubtract_RemovesOverlappingCellsOnly(t *testing.T) {
	base, err := FromPolygonWKT(subtractBaseWKT)
	if err != nil {
		t.Fatal(err)
	}
	overlap, err := FromPolygonWKT(subtractOverlapWKT)
	if err != nil {
		t.Fatal(err)
	}

	result := base.Subtract(overlap)

	// Left half of the base square (never touched by the overlap) must
	// survive; the overlapping right half must be gone.
	if !result.Contains(0.0005, 0.0015) {
		t.Error("left half (outside the overlap) must remain")
	}
	if result.Contains(0.002, 0.0015) {
		t.Error("right half (inside the overlap) must be cleared")
	}

	// Base's own cell count must have shrunk, not grown or stayed the same -
	// there must be genuine overlap in this fixture for the test to mean
	// anything.
	if result.SetCellCount() >= base.SetCellCount() {
		t.Errorf("expected fewer set cells after subtracting an overlapping region: before=%d after=%d",
			base.SetCellCount(), result.SetCellCount())
	}
}

func TestSubtract_NoOverlapLeavesBaseUnchanged(t *testing.T) {
	base, err := FromPolygonWKT(subtractBaseWKT)
	if err != nil {
		t.Fatal(err)
	}
	farAway, err := FromPolygonWKT("POLYGON((10 10, 10.003 10, 10.003 10.003, 10 10.003, 10 10))")
	if err != nil {
		t.Fatal(err)
	}

	result := base.Subtract(farAway)

	if result.SetCellCount() != base.SetCellCount() {
		t.Errorf("a disjoint subtrahend must not change the cell count: before=%d after=%d",
			base.SetCellCount(), result.SetCellCount())
	}
}

func TestSubtract_TotalOverlapEmptiesTheResult(t *testing.T) {
	base, err := FromPolygonWKT(subtractBaseWKT)
	if err != nil {
		t.Fatal(err)
	}
	// A generously larger square, guaranteed to cover the whole base.
	coversAll, err := FromPolygonWKT("POLYGON((-0.003 -0.003, 0.006 -0.003, 0.006 0.006, -0.003 0.006, -0.003 -0.003))")
	if err != nil {
		t.Fatal(err)
	}

	result := base.Subtract(coversAll)

	if result.SetCellCount() != 0 {
		t.Errorf("a subtrahend covering the whole base must empty the result, got %d cells set", result.SetCellCount())
	}
}

func TestSubtract_DoesNotMutateEitherOperand(t *testing.T) {
	base, err := FromPolygonWKT(subtractBaseWKT)
	if err != nil {
		t.Fatal(err)
	}
	overlap, err := FromPolygonWKT(subtractOverlapWKT)
	if err != nil {
		t.Fatal(err)
	}
	baseCountBefore := base.SetCellCount()
	overlapCountBefore := overlap.SetCellCount()

	_ = base.Subtract(overlap)

	if base.SetCellCount() != baseCountBefore {
		t.Error("Subtract must not mutate the receiver")
	}
	if overlap.SetCellCount() != overlapCountBefore {
		t.Error("Subtract must not mutate the argument")
	}
}
