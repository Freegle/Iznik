package main

// Compact raster classification of a polygon over its bounding box, built once
// at load time so point-in-reach queries are O(1) bit tests instead of exact
// geometry against ~11k-vertex polygons (the reach dataset's polygons average
// 178KB of WKB; measured on prod 2026-08-11, exact containment in MySQL was
// 95-98% of the browse badge-count query's 300-500ms).
//
// Each cell of a Cols x Rows grid over the polygon's bbox is classified:
//
//	cellOut     - the cell lies entirely outside the polygon
//	cellIn      - the cell lies entirely inside
//	cellPartial - a polygon edge passes through the cell, so a point here
//	              needs the exact polygon to decide (the caller resolves
//	              these few against the source of truth)
//
// Cells an edge passes through are found by supercover line traversal; the
// remaining cells are classified by even-odd crossing counts of their centre
// row against the polygon edges, which handles holes and multipolygons
// without special cases. Classification is conservative: any doubt is
// cellPartial, never a wrong In/Out.

import (
	"encoding/binary"
	"fmt"
	"math"

	"spatial-server/cellset"
)

const (
	cellOut     = 0
	cellIn      = 1
	cellPartial = 2
)

// rasterMaxDim bounds the grid so the serialized raster stays ~1-3KB: at
// 96x96 x 2 bits = 2.3KB. Finer grids shrink the partial band (fewer exact
// fallbacks) at the cost of index size; 96 keeps a 40km reach's cells at
// ~400m, so the partial band is a thin boundary strip.
const rasterMaxDim = 96

// rasterMagic versions the serialized form.
const rasterMagic uint32 = 0x52535401 // "RST" + version 1

// Raster is the in-memory form. Cells are packed 2 bits each, row-major from
// (MinLng, MinLat).
type Raster struct {
	MinLng, MinLat float64
	CellW, CellH   float64
	Cols, Rows     int
	cells          []byte
}

func (r *Raster) get(col, row int) byte {
	i := row*r.Cols + col
	return (r.cells[i>>2] >> ((i & 3) * 2)) & 3
}

func (r *Raster) set(col, row int, v byte) {
	i := row*r.Cols + col
	shift := (i & 3) * 2
	r.cells[i>>2] = (r.cells[i>>2] &^ (3 << shift)) | (v << shift)
}

// Classify returns cellOut / cellIn / cellPartial for a point. Points outside
// the bbox are cellOut.
func (r *Raster) Classify(lng, lat float64) byte {
	col := int(math.Floor((lng - r.MinLng) / r.CellW))
	row := int(math.Floor((lat - r.MinLat) / r.CellH))
	if col < 0 || col >= r.Cols || row < 0 || row >= r.Rows {
		return cellOut
	}
	return r.get(col, row)
}

// Serialize packs the raster for storage in the index's blob column.
// Layout: magic u32 | cols u16 | rows u16 | minLng f64 | minLat f64 |
// cellW f64 | cellH f64 | packed cells.
func (r *Raster) Serialize() []byte {
	out := make([]byte, 0, 40+len(r.cells))
	var buf [8]byte
	binary.LittleEndian.PutUint32(buf[:4], rasterMagic)
	out = append(out, buf[:4]...)
	binary.LittleEndian.PutUint16(buf[:2], uint16(r.Cols))
	out = append(out, buf[:2]...)
	binary.LittleEndian.PutUint16(buf[:2], uint16(r.Rows))
	out = append(out, buf[:2]...)
	for _, f := range []float64{r.MinLng, r.MinLat, r.CellW, r.CellH} {
		binary.LittleEndian.PutUint64(buf[:], math.Float64bits(f))
		out = append(out, buf[:]...)
	}
	return append(out, r.cells...)
}

// DeserializeRaster is the inverse of Serialize.
func DeserializeRaster(b []byte) (*Raster, error) {
	if len(b) < 40 || binary.LittleEndian.Uint32(b[:4]) != rasterMagic {
		return nil, fmt.Errorf("raster: bad header")
	}
	r := &Raster{
		Cols: int(binary.LittleEndian.Uint16(b[4:6])),
		Rows: int(binary.LittleEndian.Uint16(b[6:8])),
	}
	fs := make([]float64, 4)
	for i := range fs {
		fs[i] = math.Float64frombits(binary.LittleEndian.Uint64(b[8+i*8:]))
	}
	r.MinLng, r.MinLat, r.CellW, r.CellH = fs[0], fs[1], fs[2], fs[3]
	if r.Cols <= 0 || r.Rows <= 0 || r.CellW <= 0 || r.CellH <= 0 {
		return nil, fmt.Errorf("raster: bad dimensions")
	}
	need := (r.Cols*r.Rows + 3) / 4
	if len(b) != 40+need {
		return nil, fmt.Errorf("raster: bad length %d for %dx%d", len(b), r.Cols, r.Rows)
	}
	r.cells = b[40:]
	return r, nil
}

// BuildRasterFromCellSet builds the same bounded coarse accelerator as
// BuildRasterDim, but from a cellset.CellSet (plans/2026-08-24-rippling-
// reach-raster-storage.md) instead of a parsed WKT/WKB polygon. This is the
// intended long-run path: the cellset IS a fine-grained membership grid
// already, so classifying a coarse cell is bit-array sampling, not edge
// geometry - cheaper than BuildRasterDim's supercover+scanline fill, and it
// never needs to parse or hold a polygon at all.
//
// A coarse cell is classified by sampling every constituent fine cell within
// it (stepping in cellset.CellDegrees, which does not need to share the fine
// grid's exact phase — a dense sample is what decides "needs the exact
// answer", not a claim of enumerating every fine cell). All-set -> cellIn,
// all-clear -> cellOut, mixed -> cellPartial, exactly mirroring
// BuildRasterDim's contract: partial cells are resolved by the caller
// against the fine CellSet itself (a bit test), never against a polygon —
// there no longer is one.
func BuildRasterFromCellSet(cs *cellset.CellSet, maxDim int) *Raster {
	if maxDim < 1 {
		maxDim = rasterMaxDim
	}
	minLng, minLat, maxLng, maxLat := cs.Bounds()
	w, h := maxLng-minLng, maxLat-minLat
	if w <= 0 || h <= 0 {
		return nil
	}

	cols, rows := maxDim, maxDim
	if w > h {
		rows = int(math.Max(1, math.Round(float64(maxDim)*h/w)))
	} else {
		cols = int(math.Max(1, math.Round(float64(maxDim)*w/h)))
	}

	r := &Raster{
		MinLng: minLng, MinLat: minLat,
		CellW: w / float64(cols), CellH: h / float64(rows),
		Cols: cols, Rows: rows,
		cells: make([]byte, (cols*rows+3)/4),
	}

	for row := 0; row < rows; row++ {
		cy0 := minLat + float64(row)*r.CellH
		cy1 := cy0 + r.CellH
		for col := 0; col < cols; col++ {
			cx0 := minLng + float64(col)*r.CellW
			cx1 := cx0 + r.CellW

			sawIn, sawOut := false, false
			for fy := cy0; fy < cy1; fy += cellset.CellDegrees {
				for fx := cx0; fx < cx1; fx += cellset.CellDegrees {
					if cs.Contains(fx+cellset.CellDegrees/2, fy+cellset.CellDegrees/2) {
						sawIn = true
					} else {
						sawOut = true
					}
					if sawIn && sawOut {
						break
					}
				}
				if sawIn && sawOut {
					break
				}
			}
			// A coarse cell finer than one fine cell (small reach, big
			// maxDim) samples exactly one point - treat that single answer
			// as definite, matching BuildRasterDim's centre-test cells.
			if !sawIn && !sawOut {
				sawIn = cs.Contains((cx0+cx1)/2, (cy0+cy1)/2)
				sawOut = !sawIn
			}

			switch {
			case sawIn && sawOut:
				r.set(col, row, cellPartial)
			case sawIn:
				r.set(col, row, cellIn)
			}
		}
	}

	return r
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// sortFloats is a tiny insertion sort — crossing lists are short (a handful
// per row) so this beats pulling in sort.Float64s' interface overhead in the
// hot load loop. Correctness over cleverness: plain insertion sort.
func sortFloats(xs []float64) {
	for i := 1; i < len(xs); i++ {
		for j := i; j > 0 && xs[j] < xs[j-1]; j-- {
			xs[j], xs[j-1] = xs[j-1], xs[j]
		}
	}
}
