package rippling

import (
	"encoding/binary"
	"fmt"
	"sync"

	"gorm.io/gorm"
)

// CellSet is a Go-side port of spatial-server/cellset
// (plans/2026-08-24-rippling-reach-raster-storage.md) covering everything
// EXCEPT rasterising a polygon boundary into cells. Decoding, encoding an
// already-computed grid back to bytes, and combining two grids (Subtract) are
// all format-defined and deterministic - there is no canonicalisation risk a
// second implementation could disagree about, unlike turning a polygon's
// VECTOR BOUNDARY into a grid in the first place, which stays centralised in
// ONE place (iznik-spatial-go's POST /v1/reach/rasterize, the only step that
// involves a real scanline-fill judgement call). Kept as a small,
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

// cellSetMaxCells mirrors cellset.MaxCells in iznik-spatial-go: Cols and Rows
// are each uint32, so a corrupt header could ask for exabytes, and a decode
// failure has a defined meaning here (fall back to the polygon) where an
// out-of-memory does not. Must stay the SAME limit in every implementation, or
// a value one language accepts is rejected by another.
const cellSetMaxCells = 1 << 28

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
	if uint64(cols)*uint64(rows) > cellSetMaxCells {
		return nil, fmt.Errorf("cellset: grid too large (%dx%d = %d cells, max %d)",
			cols, rows, uint64(cols)*uint64(rows), cellSetMaxCells)
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

// CellSetContains answers "is this point in the reach" straight from the
// stored bytes, WITHOUT decoding them. The second return is false when the
// bytes cannot answer (too short, bad magic, absurd dimensions, truncated run
// stream), which callers treat as "fall back to the polygon" - distinct from
// a definite "outside".
//
// This is the shape every read path needs: one point, one answer. Decoding
// builds the whole grid to test a single bit; on a production-sized reach
// (4,334 x 1,634 = 7.1 million cells) that is 885KB allocated and seven
// million loop iterations for one lookup, on the reply gate. Walking the run
// stream touches only the runs before the target and allocates nothing.
// DecodeCellSet stays for the clip, which genuinely needs the whole grid.
func CellSetContains(b []byte, lng, lat float64) (bool, bool) {
	if len(b) < cellSetHeaderSize || binary.LittleEndian.Uint32(b[0:4]) != cellSetMagic {
		return false, false
	}
	minCol := int32(binary.LittleEndian.Uint32(b[4:8]))
	minRow := int32(binary.LittleEndian.Uint32(b[8:12]))
	cols := binary.LittleEndian.Uint32(b[12:16])
	rows := binary.LittleEndian.Uint32(b[16:20])
	if cols == 0 || rows == 0 || uint64(cols)*uint64(rows) > cellSetMaxCells {
		return false, false
	}

	col := int32(floorDivCellSet(lng, cellDegrees)) - minCol
	row := int32(floorDivCellSet(lat, cellDegrees)) - minRow
	if col < 0 || row < 0 || uint32(col) >= cols || uint32(row) >= rows {
		return false, true // definitely outside the stored grid
	}

	target := uint64(row)*uint64(cols) + uint64(col)
	total := uint64(cols) * uint64(rows)
	pos := cellSetHeaderSize
	cur := false // runs alternate, starting with a CLEAR run
	var seen uint64
	for seen < total {
		run, n, err := readCellSetVarint(b[pos:])
		if err != nil {
			return false, false
		}
		pos += n
		seen += run
		if target < seen {
			return cur, true
		}
		cur = !cur
	}

	// The stream ended before reaching the target: truncated, so "cannot say".
	return false, false
}

// Contains reports whether the cell containing (lng, lat) is part of the reach.
func (cs *DecodedCellSet) Contains(lng, lat float64) bool {
	col := int32(floorDivCellSet(lng, cellDegrees)) - cs.minCol
	row := int32(floorDivCellSet(lat, cellDegrees)) - cs.minRow
	if col < 0 || row < 0 || uint32(col) >= cs.cols || uint32(row) >= cs.rows {
		return false
	}
	return cs.getCell(uint32(col), uint32(row))
}

// SetCellCount is the number of cells this grid covers - used by callers of
// Subtract to tell "shrank" from "emptied entirely" (the wholly-within-the-
// rejected-group case, which drops the reach row rather than storing an
// empty grid - the same distinction ST_Difference callers made before).
func (cs *DecodedCellSet) SetCellCount() int {
	n := 0
	for _, b := range cs.bits {
		n += popcountCellSet(b)
	}
	return n
}

// Subtract returns a new DecodedCellSet holding cs's cells minus other's -
// the secondary-group rejection clip's cell-set equivalent of
// ST_Difference(polygon, group_area). Both operands share the SAME global
// lattice by construction, so this is a plain bitwise AND-NOT over the
// overlapping range: no resampling, no ambiguity, safe to duplicate exactly
// like Decode/Contains (see the package doc comment).
func (cs *DecodedCellSet) Subtract(other *DecodedCellSet) *DecodedCellSet {
	result := &DecodedCellSet{
		minCol: cs.minCol, minRow: cs.minRow,
		cols: cs.cols, rows: cs.rows,
		bits: make([]byte, len(cs.bits)),
	}
	copy(result.bits, cs.bits)

	for row := uint32(0); row < cs.rows; row++ {
		globalRow := cs.minRow + int32(row)
		otherRow := globalRow - other.minRow
		if otherRow < 0 || uint32(otherRow) >= other.rows {
			continue
		}
		for col := uint32(0); col < cs.cols; col++ {
			globalCol := cs.minCol + int32(col)
			otherCol := globalCol - other.minCol
			if otherCol < 0 || uint32(otherCol) >= other.cols {
				continue
			}
			if other.getCell(uint32(otherCol), uint32(otherRow)) {
				result.clearCell(col, row)
			}
		}
	}

	return result
}

// Encode packs the CellSet back into its wire form - serialising an
// already-computed grid is as unambiguous as parsing one, unlike rasterising
// a polygon boundary, so this is safe here even though this package carries
// no rasteriser. Format matches spatial-server/cellset's Encode exactly.
func (cs *DecodedCellSet) Encode() []byte {
	out := make([]byte, cellSetHeaderSize)
	binary.LittleEndian.PutUint32(out[0:4], cellSetMagic)
	binary.LittleEndian.PutUint32(out[4:8], uint32(cs.minCol))
	binary.LittleEndian.PutUint32(out[8:12], uint32(cs.minRow))
	binary.LittleEndian.PutUint32(out[12:16], cs.cols)
	binary.LittleEndian.PutUint32(out[16:20], cs.rows)

	total := uint64(cs.cols) * uint64(cs.rows)
	cur := false
	var run uint64
	for i := uint64(0); i < total; i++ {
		v := (cs.bits[i>>3]>>(i&7))&1 == 1
		if v == cur {
			run++
			continue
		}
		out = appendCellSetVarint(out, run)
		cur = v
		run = 1
	}
	out = appendCellSetVarint(out, run)
	return out
}

func (cs *DecodedCellSet) getCell(col, row uint32) bool {
	i := uint64(row)*uint64(cs.cols) + uint64(col)
	return (cs.bits[i>>3]>>(i&7))&1 == 1
}

func (cs *DecodedCellSet) clearCell(col, row uint32) {
	i := uint64(row)*uint64(cs.cols) + uint64(col)
	cs.bits[i>>3] &^= 1 << (i & 7)
}

func popcountCellSet(b byte) int {
	n := 0
	for b != 0 {
		n += int(b & 1)
		b >>= 1
	}
	return n
}

func appendCellSetVarint(out []byte, v uint64) []byte {
	for v >= 0x80 {
		out = append(out, byte(v)|0x80)
		v >>= 7
	}
	return append(out, byte(v))
}

var polygonCellsOnce sync.Once
var polygonCellsExists bool

// PolygonCellsReady reports whether rippling_reach.polygon_cells has been
// migrated - the CURRENT-reach cell set (as opposed to MaxPolygonCellsReady's
// eventual-reach one). Checked once per process, same discipline as
// ReachBoundsReady.
func PolygonCellsReady(db *gorm.DB) bool {
	polygonCellsOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon_cells'").
			Count(&n)
		polygonCellsExists = n > 0
	})
	return polygonCellsExists
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

// Intersects reports whether the two grids share at least one covered cell -
// the cell form of ST_Intersects(reach, area). Like Subtract, plain bit
// arithmetic on the fixed shared lattice: one possible answer, safe to exist
// per language (see the package comment for what is NOT safe to duplicate).
func (cs *DecodedCellSet) Intersects(other *DecodedCellSet) bool {
	for row := uint32(0); row < cs.rows; row++ {
		globalRow := cs.minRow + int32(row)
		otherRow := globalRow - other.minRow
		if otherRow < 0 || uint32(otherRow) >= other.rows {
			continue
		}
		for col := uint32(0); col < cs.cols; col++ {
			if !cs.getCell(col, row) {
				continue
			}
			globalCol := cs.minCol + int32(col)
			otherCol := globalCol - other.minCol
			if otherCol < 0 || uint32(otherCol) >= other.cols {
				continue
			}
			if other.getCell(uint32(otherCol), uint32(otherRow)) {
				return true
			}
		}
	}
	return false
}

// Within reports whether every covered cell of cs is also covered by other -
// the cell form of ST_Within(reach, area). An empty grid is vacuously within.
func (cs *DecodedCellSet) Within(other *DecodedCellSet) bool {
	for row := uint32(0); row < cs.rows; row++ {
		globalRow := cs.minRow + int32(row)
		otherRow := globalRow - other.minRow
		outsideRow := otherRow < 0 || uint32(otherRow) >= other.rows
		for col := uint32(0); col < cs.cols; col++ {
			if !cs.getCell(col, row) {
				continue
			}
			if outsideRow {
				return false
			}
			globalCol := cs.minCol + int32(col)
			otherCol := globalCol - other.minCol
			if otherCol < 0 || uint32(otherCol) >= other.cols || !other.getCell(uint32(otherCol), uint32(otherRow)) {
				return false
			}
		}
	}
	return true
}
