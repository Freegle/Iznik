package main

import (
	"encoding/binary"
	"testing"
)

// buildCCS1 hand-encodes a CCS1 blob from a bitmap (row-major, origin at
// MinCol/MinRow), following the documented format: header then alternating
// varint runs starting with a CLEAR run.
func buildCCS1(minCol, minRow int32, cols, rows uint32, set func(c, r uint32) bool) []byte {
	out := make([]byte, 0, 64)
	var hdr [20]byte
	binary.LittleEndian.PutUint32(hdr[0:], ccsMagicV1)
	binary.LittleEndian.PutUint32(hdr[4:], uint32(minCol))
	binary.LittleEndian.PutUint32(hdr[8:], uint32(minRow))
	binary.LittleEndian.PutUint32(hdr[12:], cols)
	binary.LittleEndian.PutUint32(hdr[16:], rows)
	out = append(out, hdr[:]...)

	appendVarint := func(v uint64) {
		for v >= 0x80 {
			out = append(out, byte(v)|0x80)
			v >>= 7
		}
		out = append(out, byte(v))
	}
	cur := false
	run := uint64(0)
	for r := uint32(0); r < rows; r++ {
		for c := uint32(0); c < cols; c++ {
			v := set(c, r)
			if v == cur {
				run++
				continue
			}
			appendVarint(run)
			cur = v
			run = 1
		}
	}
	appendVarint(run)
	return out
}

func TestCCSReaderHandBuilt(t *testing.T) {
	// A 5x4 grid at MinCol=100, MinRow=200 with a 3x2 filled block at local
	// cols 1-3, rows 1-2.
	blob := buildCCS1(100, 200, 5, 4, func(c, r uint32) bool {
		return c >= 1 && c <= 3 && r >= 1 && r <= 2
	})

	centre := func(col, row int32) (float64, float64) {
		lat, lng := ccsCellCentre(col, row)
		return lng, lat
	}

	// Inside the block.
	for _, cr := range [][2]int32{{101, 201}, {103, 202}, {102, 201}} {
		lng, lat := centre(cr[0], cr[1])
		in, ok := ccsContains(blob, lng, lat)
		if !ok || !in {
			t.Fatalf("cell (%d,%d) should be contained (ok=%v in=%v)", cr[0], cr[1], ok, in)
		}
	}
	// Inside the grid but clear.
	for _, cr := range [][2]int32{{100, 200}, {104, 203}, {100, 202}} {
		lng, lat := centre(cr[0], cr[1])
		in, ok := ccsContains(blob, lng, lat)
		if !ok || in {
			t.Fatalf("cell (%d,%d) should be clear (ok=%v in=%v)", cr[0], cr[1], ok, in)
		}
	}
	// Outside the stored grid: definite outside.
	lng, lat := centre(99, 200)
	if in, ok := ccsContains(blob, lng, lat); !ok || in {
		t.Fatalf("cell outside grid: ok=%v in=%v", ok, in)
	}

	// Walk must visit exactly the six set cells.
	got := map[[2]int32]bool{}
	if err := ccsWalk(blob, func(col, row int32) bool {
		got[[2]int32{col, row}] = true
		return true
	}); err != nil {
		t.Fatalf("walk: %v", err)
	}
	if len(got) != 6 {
		t.Fatalf("walk visited %d cells, want 6: %v", len(got), got)
	}
	for c := int32(101); c <= 103; c++ {
		for r := int32(201); r <= 202; r++ {
			if !got[[2]int32{c, r}] {
				t.Fatalf("walk missed set cell (%d,%d)", c, r)
			}
		}
	}

	// Bounds.
	minLat, minLng, maxLat, maxLng, err := ccsBounds(blob)
	if err != nil {
		t.Fatalf("bounds: %v", err)
	}
	if minLng >= maxLng || minLat >= maxLat {
		t.Fatal("degenerate bounds")
	}
	// Truncated blob: must answer "cannot say" for a cell past the break.
	if in, ok := ccsContains(blob[:len(blob)-1], centreLng(104, 203), centreLat(104, 203)); ok && in {
		t.Fatal("truncated blob answered contained")
	}
}

func centreLng(col, row int32) float64 { _, lng := ccsCellCentre(col, row); return lng }
func centreLat(col, row int32) float64 { lat, _ := ccsCellCentre(col, row); return lat }
