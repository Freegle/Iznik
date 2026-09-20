package cellset

import (
	"encoding/binary"
	"testing"
)

// ValidateEncoded is what the reach index runs on every stored blob before
// trusting it, so its REJECTIONS matter as much as its acceptances: a corrupt
// grid that validates would be believed, and a sound grid that fails
// validation drops a post out of the index. It is called from the parent
// package, so Go's per-package coverage shows it untested here even when it
// runs - hence these direct tests.

func TestValidateEncoded_AcceptsAndMeasuresARealGrid(t *testing.T) {
	// 6x5 grid at a NEGATIVE origin, 20 covered cells - the same shape the
	// cross-language golden vector pins.
	cs := gridFromPic(t, -3, -2, []string{
		"######",
		"######",
		"##..##",
		"######",
	})
	want := cs.SetCellCount()

	set, minLng, minLat, maxLng, maxLat, err := ValidateEncoded(cs.Encode())
	if err != nil {
		t.Fatalf("a grid straight from Encode must validate: %v", err)
	}
	if set != want {
		t.Errorf("covered cells: got %d, want %d", set, want)
	}

	wMinLng, wMinLat, wMaxLng, wMaxLat := cs.Bounds()
	if minLng != wMinLng || minLat != wMinLat || maxLng != wMaxLng || maxLat != wMaxLat {
		t.Errorf("bounds disagree with Bounds(): got (%v,%v)-(%v,%v), want (%v,%v)-(%v,%v)",
			minLng, minLat, maxLng, maxLat, wMinLng, wMinLat, wMaxLng, wMaxLat)
	}
}

func TestValidateEncoded_RejectsEveryWayBytesCanBeWrong(t *testing.T) {
	good := gridFromPic(t, 10, 20, []string{"###", "#.#", "###"}).Encode()

	// A header claiming dimensions past the cell limit. Built by hand because
	// no encoder would produce one.
	huge := make([]byte, headerSize)
	copy(huge, good[:headerSize])
	binary.LittleEndian.PutUint32(huge[12:16], 1<<20)
	binary.LittleEndian.PutUint32(huge[16:20], 1<<20)

	zeroCols := make([]byte, len(good))
	copy(zeroCols, good)
	binary.LittleEndian.PutUint32(zeroCols[12:16], 0)

	// Runs that sum to MORE than the grid holds: take a valid stream and
	// shrink the declared grid so the same runs overshoot it.
	overshoot := make([]byte, len(good))
	copy(overshoot, good)
	binary.LittleEndian.PutUint32(overshoot[16:20], 1)

	cases := map[string][]byte{
		"nil":             nil,
		"too short":       good[:headerSize-1],
		"bad magic":       append([]byte{0, 0, 0, 0}, good[4:]...),
		"zero cols":       zeroCols,
		"beyond MaxCells": huge,
		"truncated runs":  good[:len(good)-1],
		"trailing bytes":  append(append([]byte{}, good...), 0x00),
		"runs overshoot":  overshoot,
	}
	for name, b := range cases {
		if _, _, _, _, _, err := ValidateEncoded(b); err == nil {
			t.Errorf("%s: validated, but must be rejected", name)
		}
	}
}

// Bounds is read by the reach index for each item's envelope and by the raster
// builder, so a wrong answer mis-places a whole reach in the R-tree. Negative
// offsets are the case worth pinning: the UK is at negative longitude.
func TestBounds_IncludingNegativeOffsets(t *testing.T) {
	cs := newCellSet(-3, -2, 6, 5)
	minLng, minLat, maxLng, maxLat := cs.Bounds()

	if minLng != -3*CellDegrees {
		t.Errorf("minLng: got %v, want %v", minLng, -3*CellDegrees)
	}
	if minLat != -2*CellDegrees {
		t.Errorf("minLat: got %v, want %v", minLat, -2*CellDegrees)
	}
	// Bounds is EXCLUSIVE at the top: MinCol+Cols, so the box covers every
	// cell rather than stopping at the last cell's origin.
	if maxLng != 3*CellDegrees {
		t.Errorf("maxLng: got %v, want %v", maxLng, 3*CellDegrees)
	}
	if maxLat != 3*CellDegrees {
		t.Errorf("maxLat: got %v, want %v", maxLat, 3*CellDegrees)
	}

	// Every covered cell's centre must fall inside the reported box, which is
	// the property the R-tree depends on.
	cs.setCell(0, 0)
	cs.setCell(5, 4)
	for _, c := range [][2]uint32{{0, 0}, {5, 4}} {
		lng := (float64(cs.MinCol) + float64(c[0]) + 0.5) * CellDegrees
		lat := (float64(cs.MinRow) + float64(c[1]) + 0.5) * CellDegrees
		if lng < minLng || lng > maxLng || lat < minLat || lat > maxLat {
			t.Errorf("cell %v centre (%v,%v) falls outside the reported bounds", c, lng, lat)
		}
	}
}

// The new size guard on the construction path. Decode has always refused an
// absurd grid; until recently FromGeometry would happily allocate one, which
// is reachable from /v1/groups/intersecting with a large group area.
func TestFromPolygonWKT_RefusesAnAbsurdlyLargeExtent(t *testing.T) {
	// ~10 degrees square: at 0.0003 degrees a side that is over a billion
	// cells, well past MaxCells.
	_, err := FromPolygonWKT("POLYGON((0 0, 10 0, 10 10, 0 10, 0 0))")
	if err == nil {
		t.Fatal("a 10-degree extent must be refused rather than allocated")
	}

	// And something merely large must still work, so the guard is not simply
	// rejecting anything big: 0.3 degrees is 1000 cells a side, a million
	// cells, which is a plausible rural reach.
	if _, err := FromPolygonWKT("POLYGON((0 0, 0.3 0, 0.3 0.3, 0 0.3, 0 0))"); err != nil {
		t.Fatalf("a 0.3-degree extent is a realistic reach and must be accepted: %v", err)
	}
}

// Varints carry the run lengths, so a multi-byte boundary is where a bad
// encode/decode pairing would corrupt a long run - and long runs are exactly
// what a large empty area produces.
func TestVarintRoundTripAcrossByteBoundaries(t *testing.T) {
	for _, v := range []uint64{0, 1, 126, 127, 128, 129, 255, 16383, 16384, 1 << 20, 1 << 27} {
		buf := appendVarint(nil, v)
		got, n, err := readVarint(buf)
		if err != nil {
			t.Errorf("%d: %v", v, err)
			continue
		}
		if got != v {
			t.Errorf("%d: round-tripped to %d", v, got)
		}
		if n != len(buf) {
			t.Errorf("%d: consumed %d of %d bytes", v, n, len(buf))
		}
	}

	// A varint whose continuation bit is set but which then ends must be an
	// error, not a silent zero: that is a truncated run stream.
	if _, _, err := readVarint([]byte{0x80}); err == nil {
		t.Error("a truncated varint must be an error")
	}
}
