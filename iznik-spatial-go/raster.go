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

	"github.com/peterstace/simplefeatures/geom"
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

// edge is one polygon edge in (lng, lat) space.
type edge struct {
	x1, y1, x2, y2 float64
}

// polygonEdges flattens every ring (outer and holes, across a multipolygon)
// into one edge list — even-odd classification needs no ring bookkeeping.
func polygonEdges(g geom.Geometry) []edge {
	var edges []edge
	addRing := func(seq geom.Sequence) {
		n := seq.Length()
		for i := 0; i+1 < n; i++ {
			a, b := seq.GetXY(i), seq.GetXY(i+1)
			edges = append(edges, edge{a.X, a.Y, b.X, b.Y})
		}
	}
	addPoly := func(p geom.Polygon) {
		addRing(p.ExteriorRing().Coordinates())
		for i := 0; i < p.NumInteriorRings(); i++ {
			addRing(p.InteriorRingN(i).Coordinates())
		}
	}
	switch g.Type() {
	case geom.TypePolygon:
		addPoly(g.MustAsPolygon())
	case geom.TypeMultiPolygon:
		mp := g.MustAsMultiPolygon()
		for i := 0; i < mp.NumPolygons(); i++ {
			addPoly(mp.PolygonN(i))
		}
	}
	return edges
}

// BuildRaster classifies the grid cells for g. Returns nil for geometries with
// no polygon edges (points, lines, empties) — callers treat that as
// "no raster; always use the exact geometry".
func BuildRaster(g geom.Geometry) *Raster {
	edges := polygonEdges(g)
	if len(edges) == 0 {
		return nil
	}

	env := g.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok || max.X <= min.X || max.Y <= min.Y {
		return nil
	}

	// Grid dimensions: aspect-fit within rasterMaxDim, at least 1 cell.
	w, h := max.X-min.X, max.Y-min.Y
	cols, rows := rasterMaxDim, rasterMaxDim
	if w > h {
		rows = int(math.Max(1, math.Round(float64(rasterMaxDim)*h/w)))
	} else {
		cols = int(math.Max(1, math.Round(float64(rasterMaxDim)*w/h)))
	}

	r := &Raster{
		MinLng: min.X, MinLat: min.Y,
		CellW: w / float64(cols), CellH: h / float64(rows),
		Cols: cols, Rows: rows,
		cells: make([]byte, (cols*rows+3)/4),
	}

	// Pass 1: supercover-mark every cell an edge passes through as partial.
	for _, e := range edges {
		r.markEdge(e)
	}

	// Bucket edges by the rows their y-range spans, so the fill scans only the
	// edges that can cross each row instead of the whole edge list per row —
	// reach polygons average ~11k vertices and most edges span a single row, so
	// this turns rows×edges into ~edges work (matters at load: ~50k polygons).
	rowEdges := make([][]int32, rows)
	for i, e := range edges {
		lo, hi := e.y1, e.y2
		if lo > hi {
			lo, hi = hi, lo
		}
		r1 := int(math.Floor((lo - r.MinLat) / r.CellH))
		r2 := int(math.Floor((hi - r.MinLat) / r.CellH))
		if r1 < 0 {
			r1 = 0
		}
		if r2 >= rows {
			r2 = rows - 1
		}
		for rw := r1; rw <= r2; rw++ {
			rowEdges[rw] = append(rowEdges[rw], int32(i))
		}
	}

	// Pass 2: even-odd fill along each row of cell centres. Crossing parity is
	// evaluated at the row's centre latitude against the row's edges; a cell
	// centre with odd parity is inside. Partial cells keep their marking, and
	// cells no edge passes through are wholly in or out, so the centre test is
	// exact for them.
	for row := 0; row < rows; row++ {
		cy := r.MinLat + (float64(row)+0.5)*r.CellH

		// Gather x-crossings of the horizontal line y=cy. Half-open interval
		// (y1 <= cy < y2 or y2 <= cy < y1) so vertices aren't double-counted.
		var xs []float64
		for _, ei := range rowEdges[row] {
			e := edges[ei]
			if (e.y1 <= cy && cy < e.y2) || (e.y2 <= cy && cy < e.y1) {
				t := (cy - e.y1) / (e.y2 - e.y1)
				xs = append(xs, e.x1+t*(e.x2-e.x1))
			}
		}
		if len(xs) == 0 {
			continue // whole row outside (partials already marked)
		}
		sortFloats(xs)

		for col := 0; col < cols; col++ {
			if r.get(col, row) == cellPartial {
				continue
			}
			cx := r.MinLng + (float64(col)+0.5)*r.CellW
			// Count crossings strictly left of the centre.
			n := 0
			for _, x := range xs {
				if x < cx {
					n++
				} else {
					break
				}
			}
			if n%2 == 1 {
				r.set(col, row, cellIn)
			}
		}
	}

	return r
}

// markEdge marks every cell the segment passes through as partial (a
// supercover grid traversal, Amanatides & Woo). Cells never touched by any
// edge are wholly inside or outside the polygon, so the even-odd fill's
// centre test is EXACT for them; only the one-cell-wide traversed band needs
// the exact polygon. When the segment runs exactly along a gridline the
// floor() below assigns samples to one side; the epsilon spill marks the
// touching neighbour too, so borderline cells err towards partial (safe),
// never towards a wrong In/Out.
func (r *Raster) markEdge(e edge) {
	// Work in cell units, clamped into the grid with a hair of slack — edges
	// lie on the bbox border, and exactly-on-max coordinates must land in the
	// last cell, not one past it.
	toCell := func(x, y float64) (float64, float64) {
		fc := (x - r.MinLng) / r.CellW
		fr := (y - r.MinLat) / r.CellH
		return math.Min(math.Max(fc, 0), float64(r.Cols)-1e-9),
			math.Min(math.Max(fr, 0), float64(r.Rows)-1e-9)
	}
	fc1, fr1 := toCell(e.x1, e.y1)
	fc2, fr2 := toCell(e.x2, e.y2)

	markWithSpill := func(fc, fr float64) {
		col, row := int(math.Floor(fc)), int(math.Floor(fr))
		const eps = 1e-9
		for _, c := range [...]int{col, col - boolToInt(fc-math.Floor(fc) < eps), col + boolToInt(math.Ceil(fc)-fc < eps)} {
			for _, rw := range [...]int{row, row - boolToInt(fr-math.Floor(fr) < eps), row + boolToInt(math.Ceil(fr)-fr < eps)} {
				if c >= 0 && c < r.Cols && rw >= 0 && rw < r.Rows {
					r.set(c, rw, cellPartial)
				}
			}
		}
	}

	markWithSpill(fc1, fr1)

	dc, dr := fc2-fc1, fr2-fr1
	col, row := int(math.Floor(fc1)), int(math.Floor(fr1))
	endCol, endRow := int(math.Floor(fc2)), int(math.Floor(fr2))

	stepC, stepR := 1, 1
	if dc < 0 {
		stepC = -1
	}
	if dr < 0 {
		stepR = -1
	}

	// Parametric distance along the segment to the next column/row boundary.
	next := func(f float64, step int) float64 {
		if step > 0 {
			return math.Floor(f) + 1 - f
		}
		return f - math.Floor(f)
	}
	tMaxC, tMaxR := math.Inf(1), math.Inf(1)
	tDeltaC, tDeltaR := math.Inf(1), math.Inf(1)
	if dc != 0 {
		tMaxC = next(fc1, stepC) / math.Abs(dc)
		tDeltaC = 1 / math.Abs(dc)
	}
	if dr != 0 {
		tMaxR = next(fr1, stepR) / math.Abs(dr)
		tDeltaR = 1 / math.Abs(dr)
	}

	for (col != endCol || row != endRow) && (tMaxC <= 1 || tMaxR <= 1) {
		if tMaxC < tMaxR {
			col += stepC
			tMaxC += tDeltaC
		} else if tMaxR < tMaxC {
			row += stepR
			tMaxR += tDeltaR
		} else {
			// Exact corner crossing: step both, and mark both single-step
			// neighbours so the diagonal doesn't skip a touched cell.
			if col+stepC >= 0 && col+stepC < r.Cols && row >= 0 && row < r.Rows {
				r.set(col+stepC, row, cellPartial)
			}
			if col >= 0 && col < r.Cols && row+stepR >= 0 && row+stepR < r.Rows {
				r.set(col, row+stepR, cellPartial)
			}
			col += stepC
			row += stepR
			tMaxC += tDeltaC
			tMaxR += tDeltaR
		}
		if col >= 0 && col < r.Cols && row >= 0 && row < r.Rows {
			r.set(col, row, cellPartial)
		}
	}

	markWithSpill(fc2, fr2)
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
