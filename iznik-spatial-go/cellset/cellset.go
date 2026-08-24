// Package cellset is the canonical, compact on-disk representation of a
// rippling reach area, replacing the stored WKT/GEOMETRY polygon.
//
// plans/2026-08-24-rippling-reach-raster-storage.md: rippling_reach.polygon
// today stores an ~11k-vertex traced boundary (300KB-1MB) of an area that is
// itself computed as a grid fill by the routing server and immediately
// re-rasterised by iznik-spatial-go on load (raster.go) to make containment
// tests affordable. A CellSet stores the grid membership directly - the
// thing every consumer actually wants - instead of a vector tracing of it
// that has to be parsed and re-rasterised at every hop.
//
// FORMAT (v1), all little-endian:
//
//	offset  size  field
//	0       4     magic (formatMagicV1)
//	4       4     MinCol (int32) - column index of the grid's left edge
//	8       4     MinRow (int32) - row index of the grid's bottom edge
//	12      4     Cols   (uint32)
//	16      4     Rows   (uint32)
//	20      ...   RLE stream: varint run-lengths of set/clear cells,
//	              alternating, ALWAYS starting with a clear run (length 0
//	              is valid and used when the very first cell is set) - so
//	              parity, not a flag bit, says which colour a run is.
//	              Row-major from (MinRow, MinCol). Self-terminating: the
//	              decoder stops once Rows*Cols cells have been produced.
//
// The grid is anchored to a GLOBAL lattice, not to each polygon's own
// bounding box: column c spans [c*CellDegrees, (c+1)*CellDegrees) in
// longitude, row r spans the same in latitude. Two independently-encoded
// CellSets covering the same real-world area therefore always agree on
// which cell a point falls into - required for cross-CellSet comparison
// (a future content-hash dedup step) and for the coarse accelerator raster
// spatial-go builds from one to align with the fine grid it reads from.
//
// CellDegrees is fixed at the format version, not stored per-blob: every v1
// CellSet shares one global lattice by construction, and a future coarser
// or finer lattice becomes v2 with its own magic rather than a per-blob
// parameter free to drift between writers.
package cellset

import (
	"encoding/binary"
	"fmt"
)

// CellDegrees is the fixed global lattice step, in degrees, applied to BOTH
// axes uniformly (not adjusted for cos(latitude)): the same convention the
// rest of the codebase already uses for reach-adjacent geometry (see
// iznik-batch's ShrinkOverflowBoundsCommand, whose overflow rings are
// documented as sitting on this exact 0.0003 degree lattice because they
// too are traced from a routing-server raster). At UK latitudes that is
// roughly 33m north-south and 19-25m east-west - not square, but a coverage
// membership grid does not need square cells, only a bounded, consistent
// one, and reusing the existing constant means a CellSet aligns exactly
// with rasters the rest of the system already produces.
const CellDegrees = 0.0003

const formatMagicV1 uint32 = 0x31534343 // "CCS1" (Compact Cell Set, v1) read little-endian

const headerSize = 20 // magic(4) + MinCol(4) + MinRow(4) + Cols(4) + Rows(4)

// CellSet is the decoded, queryable form.
type CellSet struct {
	MinCol, MinRow int32
	Cols, Rows     uint32
	// bits holds one bit per cell, row-major from (MinRow, MinCol): bit
	// (row*Cols+col) is set when that cell is part of the reach.
	bits []byte
}

func newCellSet(minCol, minRow int32, cols, rows uint32) *CellSet {
	return &CellSet{
		MinCol: minCol, MinRow: minRow,
		Cols: cols, Rows: rows,
		bits: make([]byte, (uint64(cols)*uint64(rows)+7)/8),
	}
}

func (cs *CellSet) getCell(col, row uint32) bool {
	i := uint64(row)*uint64(cs.Cols) + uint64(col)
	return (cs.bits[i>>3]>>(i&7))&1 == 1
}

func (cs *CellSet) setCell(col, row uint32) {
	i := uint64(row)*uint64(cs.Cols) + uint64(col)
	cs.bits[i>>3] |= 1 << (i & 7)
}

// cellIndex returns the global column/row a point falls into.
func cellIndex(lng, lat float64) (col, row int32) {
	return colIndex(lng), rowIndex(lat)
}

// colIndex and rowIndex are the single-axis forms cellIndex is built from -
// used directly wherever only one axis is needed, so a caller can never
// destructure cellIndex's (col, row) pair the wrong way round.
func colIndex(lng float64) int32 { return int32(floorDiv(lng, CellDegrees)) }
func rowIndex(lat float64) int32 { return int32(floorDiv(lat, CellDegrees)) }

func floorDiv(v, step float64) float64 {
	q := v / step
	f := float64(int64(q))
	if q < f {
		f--
	}
	return f
}

// Contains reports whether the cell containing (lng, lat) is part of the
// reach. Points outside the stored grid are not contained - the grid is
// exactly the polygon's bounding box at encode time, so this is exact,
// not an approximation the caller must double-check elsewhere.
func (cs *CellSet) Contains(lng, lat float64) bool {
	col, row := cellIndex(lng, lat)
	col -= cs.MinCol
	row -= cs.MinRow
	if col < 0 || row < 0 || uint32(col) >= cs.Cols || uint32(row) >= cs.Rows {
		return false
	}
	return cs.getCell(uint32(col), uint32(row))
}

// SetCellCount is the number of cells the reach covers - the same
// information OCTET_LENGTH(polygon) gave a rough proxy for, but exact and
// directly comparable across CellSets on the shared lattice.
func (cs *CellSet) SetCellCount() int {
	n := 0
	for _, b := range cs.bits {
		n += popcount(b)
	}
	return n
}

// Bounds returns the covered area's bounding box in degrees, matching what
// callers previously read from ST_Envelope(polygon).
func (cs *CellSet) Bounds() (minLng, minLat, maxLng, maxLat float64) {
	minLng = float64(cs.MinCol) * CellDegrees
	minLat = float64(cs.MinRow) * CellDegrees
	maxLng = float64(cs.MinCol+int32(cs.Cols)) * CellDegrees
	maxLat = float64(cs.MinRow+int32(cs.Rows)) * CellDegrees
	return
}

// Encode packs the CellSet into its wire form.
func (cs *CellSet) Encode() []byte {
	out := make([]byte, headerSize)
	binary.LittleEndian.PutUint32(out[0:4], formatMagicV1)
	binary.LittleEndian.PutUint32(out[4:8], uint32(cs.MinCol))
	binary.LittleEndian.PutUint32(out[8:12], uint32(cs.MinRow))
	binary.LittleEndian.PutUint32(out[12:16], cs.Cols)
	binary.LittleEndian.PutUint32(out[16:20], cs.Rows)

	total := uint64(cs.Cols) * uint64(cs.Rows)
	cur := false // runs alternate starting with "clear"
	var run uint64
	for i := uint64(0); i < total; i++ {
		v := (cs.bits[i>>3]>>(i&7))&1 == 1
		if v == cur {
			run++
			continue
		}
		out = appendVarint(out, run)
		cur = v
		run = 1
	}
	out = appendVarint(out, run)
	return out
}

// Decode is the inverse of Encode.
func Decode(b []byte) (*CellSet, error) {
	if len(b) < headerSize {
		return nil, fmt.Errorf("cellset: input too short for a header (%d bytes)", len(b))
	}
	if binary.LittleEndian.Uint32(b[0:4]) != formatMagicV1 {
		return nil, fmt.Errorf("cellset: bad magic")
	}
	minCol := int32(binary.LittleEndian.Uint32(b[4:8]))
	minRow := int32(binary.LittleEndian.Uint32(b[8:12]))
	cols := binary.LittleEndian.Uint32(b[12:16])
	rows := binary.LittleEndian.Uint32(b[16:20])
	if cols == 0 || rows == 0 {
		return nil, fmt.Errorf("cellset: zero-sized grid (%dx%d)", cols, rows)
	}

	cs := newCellSet(minCol, minRow, cols, rows)
	total := uint64(cols) * uint64(rows)
	pos := headerSize
	cur := false
	var i uint64
	for i < total {
		run, n, err := readVarint(b[pos:])
		if err != nil {
			return nil, fmt.Errorf("cellset: %w", err)
		}
		pos += n
		if cur {
			for k := uint64(0); k < run && i < total; k++ {
				row, col := uint32(i/uint64(cols)), uint32(i%uint64(cols))
				cs.setCell(col, row)
				i++
			}
		} else {
			i += run
		}
		cur = !cur
	}
	return cs, nil
}

func popcount(b byte) int {
	n := 0
	for b != 0 {
		n += int(b & 1)
		b >>= 1
	}
	return n
}

func appendVarint(out []byte, v uint64) []byte {
	for v >= 0x80 {
		out = append(out, byte(v)|0x80)
		v >>= 7
	}
	return append(out, byte(v))
}

func readVarint(b []byte) (v uint64, n int, err error) {
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
