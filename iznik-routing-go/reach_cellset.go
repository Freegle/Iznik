package main

// Decode-free reader for the CCS1 cell-set format, for the stage-2 prototype
// parity harness ONLY. This is the same fixed-format READ arithmetic that
// iznik-server-go (rippling/cellset.go) and iznik-batch (CellSetService)
// already carry; the format's own documentation (iznik-spatial-go/cellset/
// probe.go) declares the read path safe to duplicate because the format is
// versioned and has one possible answer. ENCODING remains solely in
// iznik-spatial-go — this file never writes a cell set, preserving the
// single-writer invariant.

import (
	"encoding/binary"
	"fmt"
)

const ccsCellDegrees = 0.0003
const ccsMagicV1 uint32 = 0x31534343 // "CCS1"
const ccsHeaderSize = 20
const ccsMaxCells = 1 << 28

func ccsFloorDiv(v, step float64) float64 {
	q := v / step
	f := float64(int64(q))
	if q < f {
		f--
	}
	return f
}

func ccsColIndex(lng float64) int32 { return int32(ccsFloorDiv(lng, ccsCellDegrees)) }
func ccsRowIndex(lat float64) int32 { return int32(ccsFloorDiv(lat, ccsCellDegrees)) }

func ccsReadVarint(b []byte) (v uint64, n int, err error) {
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
	return 0, 0, fmt.Errorf("varint truncated")
}

type ccsHeader struct {
	MinCol, MinRow int32
	Cols, Rows     uint32
}

func ccsParseHeader(b []byte) (ccsHeader, error) {
	var h ccsHeader
	if len(b) < ccsHeaderSize || binary.LittleEndian.Uint32(b[0:4]) != ccsMagicV1 {
		return h, fmt.Errorf("bad cellset magic/size")
	}
	h.MinCol = int32(binary.LittleEndian.Uint32(b[4:8]))
	h.MinRow = int32(binary.LittleEndian.Uint32(b[8:12]))
	h.Cols = binary.LittleEndian.Uint32(b[12:16])
	h.Rows = binary.LittleEndian.Uint32(b[16:20])
	if h.Cols == 0 || h.Rows == 0 || uint64(h.Cols)*uint64(h.Rows) > ccsMaxCells {
		return h, fmt.Errorf("absurd cellset dimensions %dx%d", h.Cols, h.Rows)
	}
	return h, nil
}

// ccsContains answers point-in-reach straight from the wire bytes.
func ccsContains(b []byte, lng, lat float64) (contained bool, ok bool) {
	h, err := ccsParseHeader(b)
	if err != nil {
		return false, false
	}
	col := ccsColIndex(lng) - h.MinCol
	row := ccsRowIndex(lat) - h.MinRow
	if col < 0 || row < 0 || uint32(col) >= h.Cols || uint32(row) >= h.Rows {
		return false, true
	}
	target := uint64(row)*uint64(h.Cols) + uint64(col)
	total := uint64(h.Cols) * uint64(h.Rows)
	pos := ccsHeaderSize
	cur := false
	var seen uint64
	for seen < total {
		run, n, err := ccsReadVarint(b[pos:])
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
	return false, false
}

// ccsWalk streams every SET cell to fn as (col, row) GLOBAL lattice indices,
// stopping early if fn returns false.
func ccsWalk(b []byte, fn func(col, row int32) bool) error {
	h, err := ccsParseHeader(b)
	if err != nil {
		return err
	}
	total := uint64(h.Cols) * uint64(h.Rows)
	pos := ccsHeaderSize
	cur := false
	var seen uint64
	for seen < total {
		run, n, err := ccsReadVarint(b[pos:])
		if err != nil {
			return err
		}
		pos += n
		if cur {
			for k := uint64(0); k < run; k++ {
				idx := seen + k
				row := int32(idx/uint64(h.Cols)) + h.MinRow
				col := int32(idx%uint64(h.Cols)) + h.MinCol
				if !fn(col, row) {
					return nil
				}
			}
		}
		seen += run
		cur = !cur
	}
	return nil
}

// ccsCellCentre returns the centre lat/lng of a global lattice cell.
func ccsCellCentre(col, row int32) (lat, lng float64) {
	return (float64(row) + 0.5) * ccsCellDegrees, (float64(col) + 0.5) * ccsCellDegrees
}

// ccsBounds returns the stored grid's lat/lng bounding box.
func ccsBounds(b []byte) (minLat, minLng, maxLat, maxLng float64, err error) {
	h, err := ccsParseHeader(b)
	if err != nil {
		return 0, 0, 0, 0, err
	}
	minLng = float64(h.MinCol) * ccsCellDegrees
	minLat = float64(h.MinRow) * ccsCellDegrees
	maxLng = float64(h.MinCol+int32(h.Cols)) * ccsCellDegrees
	maxLat = float64(h.MinRow+int32(h.Rows)) * ccsCellDegrees
	return minLat, minLng, maxLat, maxLng, nil
}
