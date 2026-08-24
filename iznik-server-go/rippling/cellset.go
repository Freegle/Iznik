package rippling

import (
	"encoding/binary"
	"fmt"
)

// CellSet is a Go-side DECODE-ONLY port of iznik-reach-cellset/cellset
// (plans/2026-08-24-rippling-reach-raster-storage.md). It duplicates the PHP
// twin's App\Services\Ripple\CellSetService, not the encoder: decoding only
// parses a fixed, versioned, self-describing format, which carries no
// canonicalisation risk a second implementation could disagree about -
// unlike RASTERISING a polygon's boundary, which stays centralised in ONE
// place (iznik-spatial-go's POST /v1/reach/rasterize). Kept as a small,
// dependency-free file inside this package rather than importing the shared
// iznik-reach-cellset module: this repo's dev/test containers only sync
// their own top-level directory (file-sync.sh), so a cross-module `replace
// ../iznik-reach-cellset` cannot resolve inside them - see the raster-
// storage plan's known packaging gap. Proven byte-identical to the real Go
// encoder via the same golden vector CellSetServiceTest asserts against
// (rippling/cellset_test.go).
//
// Format v1, little-endian:
//
//	offset 0   magic  uint32 (0x31534343, "CCS1")
//	offset 4   MinCol int32
//	offset 8   MinRow int32
//	offset 12  Cols   uint32
//	offset 16  Rows   uint32
//	offset 20  RLE varint run-lengths, alternating starting with a clear
//	           run (length 0 valid), row-major, self-terminating.
const cellSetMagic uint32 = 0x31534343
const cellSetHeaderSize = 20
const cellDegrees = 0.0003

// DecodedCellSet is the queryable decoded form.
type DecodedCellSet struct {
	minCol, minRow int32
	cols, rows     uint32
	bits           []byte
}

// DecodeCellSet parses raw cell-set bytes (e.g. rippling_reach.max_polygon_cells).
func DecodeCellSet(b []byte) (*DecodedCellSet, error) {
	if len(b) < cellSetHeaderSize {
		return nil, fmt.Errorf("cellset: input too short for a header (%d bytes)", len(b))
	}
	if binary.LittleEndian.Uint32(b[0:4]) != cellSetMagic {
		return nil, fmt.Errorf("cellset: bad magic")
	}
	cols := binary.LittleEndian.Uint32(b[12:16])
	rows := binary.LittleEndian.Uint32(b[16:20])
	if cols == 0 || rows == 0 {
		return nil, fmt.Errorf("cellset: zero-sized grid (%dx%d)", cols, rows)
	}

	cs := &DecodedCellSet{
		minCol: int32(binary.LittleEndian.Uint32(b[4:8])),
		minRow: int32(binary.LittleEndian.Uint32(b[8:12])),
		cols:   cols,
		rows:   rows,
		bits:   make([]byte, (uint64(cols)*uint64(rows)+7)/8),
	}

	total := uint64(cols) * uint64(rows)
	pos := cellSetHeaderSize
	cur := false
	var i uint64
	for i < total {
		run, n, err := readCellSetVarint(b[pos:])
		if err != nil {
			return nil, fmt.Errorf("cellset: %w", err)
		}
		pos += n
		if cur {
			for k := uint64(0); k < run && i < total; k++ {
				cs.bits[i>>3] |= 1 << (i & 7)
				i++
			}
		} else {
			i += run
		}
		cur = !cur
	}
	return cs, nil
}

// Contains reports whether the cell containing (lng, lat) is part of the reach.
func (cs *DecodedCellSet) Contains(lng, lat float64) bool {
	col := int32(floorDivCellSet(lng, cellDegrees)) - cs.minCol
	row := int32(floorDivCellSet(lat, cellDegrees)) - cs.minRow
	if col < 0 || row < 0 || uint32(col) >= cs.cols || uint32(row) >= cs.rows {
		return false
	}
	i := uint64(row)*uint64(cs.cols) + uint64(col)
	return (cs.bits[i>>3]>>(i&7))&1 == 1
}

func floorDivCellSet(v, step float64) float64 {
	q := v / step
	f := float64(int64(q))
	if q < f {
		f--
	}
	return f
}

func readCellSetVarint(b []byte) (v uint64, n int, err error) {
	var shift uint
	for n < len(b) {
		c := b[n]
		n++
		v |= uint64(c&0x7f) << shift
		if c&0x80 == 0 {
			return v, n, nil
		}
		shift += 7
		if shift >= 64 {
			return 0, 0, fmt.Errorf("varint too long")
		}
	}
	return 0, 0, fmt.Errorf("truncated varint")
}
