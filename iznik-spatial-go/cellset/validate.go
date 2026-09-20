package cellset

import (
	"encoding/binary"
	"fmt"
)

// ValidateEncoded walks the whole run stream of encoded cell-set bytes
// WITHOUT allocating a bitmap: header sanity, stream integrity (the runs must
// sum exactly to cols*rows with no trailing bytes), and returns the covered
// cell count and the grid's bounds in degrees. This is what an index build
// runs per row before trusting stored bytes - a corrupt blob is rejected
// here, in microseconds, rather than discovered by a wrong answer later.
func ValidateEncoded(b []byte) (setCells int, minLng, minLat, maxLng, maxLat float64, err error) {
	if len(b) < headerSize {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: too short (%d bytes)", len(b))
	}
	if binary.LittleEndian.Uint32(b[0:4]) != formatMagicV1 {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: bad magic")
	}
	minCol := int32(binary.LittleEndian.Uint32(b[4:8]))
	minRow := int32(binary.LittleEndian.Uint32(b[8:12]))
	cols := binary.LittleEndian.Uint32(b[12:16])
	rows := binary.LittleEndian.Uint32(b[16:20])
	if cols == 0 || rows == 0 {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: zero dimension")
	}
	total := uint64(cols) * uint64(rows)
	if total > MaxCells {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: %d cells exceeds limit", total)
	}

	pos := headerSize
	cur := false // runs alternate, starting with a CLEAR run
	var seen, set uint64
	for seen < total {
		run, n, verr := readVarint(b[pos:])
		if verr != nil {
			return 0, 0, 0, 0, 0, fmt.Errorf("cellset: truncated run stream")
		}
		pos += n
		seen += run
		if cur {
			set += run
		}
		cur = !cur
	}
	if seen != total {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: runs overshoot grid (%d > %d)", seen, total)
	}
	if pos != len(b) {
		return 0, 0, 0, 0, 0, fmt.Errorf("cellset: %d trailing bytes", len(b)-pos)
	}

	minLng = float64(minCol) * CellDegrees
	minLat = float64(minRow) * CellDegrees
	maxLng = float64(minCol+int32(cols)) * CellDegrees
	maxLat = float64(minRow+int32(rows)) * CellDegrees
	return int(set), minLng, minLat, maxLng, maxLat, nil
}
