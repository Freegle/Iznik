package cellset

import "encoding/binary"

// ContainsEncoded answers "is this point in the covered area" straight from
// the wire bytes, WITHOUT decoding them. The second return is false when the
// bytes cannot answer (too short, bad magic, absurd dimensions, truncated run
// stream) - distinct from a definite "outside".
//
// Decoding builds the whole grid to test a single bit; on a production-sized
// reach (4,334 x 1,634 = 7.1 million cells) that is ~885KB allocated and seven
// million loop iterations per lookup. Walking the run stream touches only the
// runs before the target cell and allocates nothing, which is what lets the
// reach index probe candidates per query instead of materialising a bitmap
// for each. This is the same fixed-format arithmetic iznik-server-go and
// iznik-batch carry (rippling/cellset.go, CellSetService) - safe to duplicate
// because the format is versioned and there is only one possible answer,
// unlike rasterising a boundary, which stays solely in FromPolygonWKT.
func ContainsEncoded(b []byte, lng, lat float64) (contained bool, ok bool) {
	if len(b) < headerSize || binary.LittleEndian.Uint32(b[0:4]) != formatMagicV1 {
		return false, false
	}
	minCol := int32(binary.LittleEndian.Uint32(b[4:8]))
	minRow := int32(binary.LittleEndian.Uint32(b[8:12]))
	cols := binary.LittleEndian.Uint32(b[12:16])
	rows := binary.LittleEndian.Uint32(b[16:20])
	if cols == 0 || rows == 0 || uint64(cols)*uint64(rows) > MaxCells {
		return false, false
	}

	col := colIndex(lng) - minCol
	row := rowIndex(lat) - minRow
	if col < 0 || row < 0 || uint32(col) >= cols || uint32(row) >= rows {
		return false, true // definitely outside the stored grid
	}

	target := uint64(row)*uint64(cols) + uint64(col)
	total := uint64(cols) * uint64(rows)
	pos := headerSize
	cur := false // runs alternate, starting with a CLEAR run
	var seen uint64
	for seen < total {
		run, n, err := readVarint(b[pos:])
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
