package cellset

import "testing"

func gridFromPic(t *testing.T, minCol, minRow int32, pic []string) *CellSet {
	t.Helper()
	rows := uint32(len(pic))
	cols := uint32(len(pic[0]))
	cs := newCellSet(minCol, minRow, cols, rows)
	for i, line := range pic {
		row := rows - 1 - uint32(i)
		for c := uint32(0); c < cols; c++ {
			if line[c] == '#' {
				cs.setCell(c, row)
			}
		}
	}
	return cs
}

func TestIntersectsAndWithin(t *testing.T) {
	// A 2x2 block at global (10,10).
	small := gridFromPic(t, 10, 10, []string{
		"##",
		"##",
	})
	// A 4x4 block at (9,9) that fully covers it.
	big := gridFromPic(t, 9, 9, []string{
		"####",
		"####",
		"####",
		"####",
	})
	// A disjoint block.
	far := gridFromPic(t, 100, 100, []string{
		"##",
		"##",
	})
	// Overlapping bounding boxes but disjoint cells: covers (11,12) and
	// (12,11), while small's shared-bbox cell (11,11) is left unset.
	corner := gridFromPic(t, 11, 11, []string{
		"#.",
		".#",
	})

	if !small.Intersects(big) || !big.Intersects(small) {
		t.Fatal("covering grids must intersect, both directions")
	}
	if !small.Within(big) {
		t.Fatal("small is covered by big")
	}
	if big.Within(small) {
		t.Fatal("big is not covered by small")
	}
	if small.Intersects(far) || far.Intersects(small) {
		t.Fatal("disjoint grids must not intersect")
	}
	if small.Within(far) {
		t.Fatal("disjoint grids are not within each other")
	}

	// corner covers global cells (12,11) and (11,12), both outside small's
	// 10..11 range on one axis; the one cell inside the bbox overlap, (11,11),
	// is unset in corner. Bboxes overlap, cells do not.
	if small.Intersects(corner) {
		t.Fatal("bbox overlap without shared cells must not intersect")
	}

	// A grid is within itself, and an empty grid is within anything.
	if !small.Within(small) {
		t.Fatal("a grid is within itself")
	}
	empty := newCellSet(0, 0, 3, 3)
	if !empty.Within(small) {
		t.Fatal("an empty grid is vacuously within")
	}
	if empty.Intersects(small) {
		t.Fatal("an empty grid intersects nothing")
	}
}
