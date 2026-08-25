package cellset

import (
	"fmt"

	"github.com/peterstace/simplefeatures/geom"
)

// FromPolygonWKT rasterises a POLYGON or MULTIPOLYGON WKT string onto the
// global CellDegrees lattice. A cell is set if and only if its CENTRE point
// is inside the polygon (even-odd rule) - a definite rule, not raster.go's
// approximate/needs-fallback one, because there is no fallback: this is the
// only stored form. At a 0.0003-degree cell that is at most ~33m of
// boundary ambiguity, well inside the ~400m location blur every reach
// origin already carries (App\Support\UserApproxLocService::BLUR_USER) and
// the routing server's own approximation - not a new source of error.
func FromPolygonWKT(wkt string) (*CellSet, error) {
	g, err := geom.UnmarshalWKT(wkt, geom.NoValidate{})
	if err != nil {
		return nil, fmt.Errorf("cellset: parse WKT: %w", err)
	}
	return FromGeometry(g)
}

// FromGeometry rasterises an already-parsed POLYGON or MULTIPOLYGON with the
// same rule as FromPolygonWKT - the entry point for geometries that arrive as
// WKB (e.g. group areas from the groups index) rather than text.
func FromGeometry(g geom.Geometry) (*CellSet, error) {
	edges := polygonEdges(g)
	if len(edges) == 0 {
		return nil, fmt.Errorf("cellset: no polygon rings in geometry")
	}

	env := g.Envelope()
	min, max, ok := env.MinMaxXYs()
	if !ok || max.X < min.X || max.Y < min.Y {
		return nil, fmt.Errorf("cellset: degenerate envelope")
	}

	minCol, minRow := colIndex(min.X), rowIndex(min.Y)
	maxColExclusive, maxRowExclusive := colIndex(max.X)+1, rowIndex(max.Y)+1
	cols := uint32(maxColExclusive - minCol)
	rows := uint32(maxRowExclusive - minRow)
	if cols == 0 {
		cols = 1
	}
	if rows == 0 {
		rows = 1
	}

	// The SAME bound Decode enforces, applied on the way IN. Decode has always
	// refused an absurd grid before allocating for it, but until this check the
	// construction path did not - so a legitimately enormous input allocated
	// whatever its extent implied. That matters because this is reachable from
	// /v1/groups/intersecting with a group's own area: a national-scale
	// boundary spanning ~10 degrees is 33,000 cells a side, over a billion
	// cells, a 139MB bitmap, inside the spatial server. Refusing is correct -
	// the caller treats a rasterise failure as "cannot answer" and falls back
	// rather than acting on a wrong answer.
	if uint64(cols)*uint64(rows) > MaxCells {
		return nil, fmt.Errorf("cellset: %d x %d = %d cells exceeds the %d limit",
			cols, rows, uint64(cols)*uint64(rows), uint64(MaxCells))
	}

	cs := newCellSet(minCol, minRow, cols, rows)

	// Bucket edges by the rows they can cross, so the fill is ~edges work
	// rather than rows*edges - reach polygons run to ~11k vertices.
	rowEdges := make([][]int32, rows)
	for i, e := range edges {
		lo, hi := e.y1, e.y2
		if lo > hi {
			lo, hi = hi, lo
		}
		r1 := rowIndex(lo) - minRow
		r2 := rowIndex(hi) - minRow
		if r1 < 0 {
			r1 = 0
		}
		if r2 >= int32(rows) {
			r2 = int32(rows) - 1
		}
		for r := r1; r <= r2; r++ {
			rowEdges[r] = append(rowEdges[r], int32(i))
		}
	}

	for row := uint32(0); row < rows; row++ {
		cy := float64(minRow+int32(row))*CellDegrees + CellDegrees/2

		var xs []float64
		for _, ei := range rowEdges[row] {
			e := edges[ei]
			if (e.y1 <= cy && cy < e.y2) || (e.y2 <= cy && cy < e.y1) {
				t := (cy - e.y1) / (e.y2 - e.y1)
				xs = append(xs, e.x1+t*(e.x2-e.x1))
			}
		}
		if len(xs) == 0 {
			continue
		}
		insertionSortFloats(xs)

		for col := uint32(0); col < cols; col++ {
			cx := float64(minCol+int32(col))*CellDegrees + CellDegrees/2
			n := 0
			for _, x := range xs {
				if x < cx {
					n++
				} else {
					break
				}
			}
			if n%2 == 1 {
				cs.setCell(col, row)
			}
		}
	}

	return cs, nil
}

type edge struct {
	x1, y1, x2, y2 float64
}

// polygonEdges flattens every ring - outer and holes, across a
// multipolygon - into one edge list, matching raster.go's approach: even-odd
// classification needs no ring bookkeeping once every edge is in one list.
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

// insertionSortFloats: crossing lists are short (a handful per row), so a
// plain insertion sort beats pulling in sort.Float64s' interface overhead -
// same reasoning and shape as raster.go's sortFloats.
func insertionSortFloats(xs []float64) {
	for i := 1; i < len(xs); i++ {
		for j := i; j > 0 && xs[j] < xs[j-1]; j-- {
			xs[j], xs[j-1] = xs[j-1], xs[j]
		}
	}
}
