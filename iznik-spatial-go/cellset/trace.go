package cellset

import (
	"fmt"
	"math"
	"sort"
	"strconv"
	"strings"
)

// Tracing a CellSet back into a boundary polygon - the inverse of
// FromPolygonWKT, for the few places that genuinely need a vector once the
// grid is the only stored form: the map overlay, and re-deriving the sandwich
// bounds after a clip. The trace is EXACT at tolerance 0: every output ring
// runs along lattice lines, so rasterising it back (cell-centre even-odd,
// FromPolygonWKT) reproduces the input grid bit for bit - asserted by tests,
// because that equivalence is what makes it safe for anything load-bearing.
// A positive tolerance additionally Douglas-Peucker-simplifies each ring for
// display, which deliberately trades that exactness away; nothing that feeds
// a decision uses a simplified trace.
//
// Like the rasteriser, this lives ONLY here: it is a judgement-carrying
// conversion between forms (pinch handling, hole nesting), exactly the class
// of code that must not exist twice.

// direction indices; vector per direction.
const (
	dirPosX = 0
	dirPosY = 1
	dirNegX = 2
	dirNegY = 3
)

var dirDX = [4]int32{1, 0, -1, 0}
var dirDY = [4]int32{0, 1, 0, -1}

// corner identifies a lattice corner in LOCAL coordinates: col in [0..Cols],
// row in [0..Rows].
type corner struct {
	col, row int32
}

func cornerKey(c corner) uint64 {
	return uint64(uint32(c.row))<<32 | uint64(uint32(c.col))
}

// tracedRing is one closed boundary loop in local corner coordinates. The
// vertex list is corner-only (collinear runs merged) and does NOT repeat the
// first vertex at the end.
type tracedRing struct {
	verts []corner
	area2 int64 // twice the signed shoelace area; >0 = CCW = shell, <0 = hole
}

// TraceBoundary extracts the covered area's boundary as rings on the lattice.
// Interior is always on the LEFT of travel, so shells come out CCW and holes
// CW, and every returned ring is SIMPLE - no ring visits a corner twice.
//
// Corners where two regions meet only diagonally (saddles) are the whole
// difficulty. A saddle admits two pairings of its edges, and the choice is
// forced to be inconsistent somewhere: making the COVERED region 4-connected
// there necessarily makes the UNCOVERED region 8-connected, and vice versa. So
// a single rule cannot keep both sides simple, and a hole threading two
// saddles will come back as a figure-eight however the pairing is chosen.
//
// Two things therefore happen. Saddles are paired by the CELL each edge
// bounds, which is direction-independent (an earlier version preferred a turn
// direction, decided from the direction of travel at the moment the corner was
// visited, so visit order changed the answer). And any walk that still closes
// on itself is split at its repeated corners into simple loops - their union
// is the same boundary, so coverage is untouched.
func (cs *CellSet) TraceBoundary() []tracedRing {
	// Directed boundary edges, keyed by start corner, each remembering WHICH
	// CELL's boundary it is. A corner has at most two outgoing edges, and
	// exactly two only at a saddle - one cell diagonally touching another.
	//
	// The owning cell is what resolves a saddle, and it is why this needs no
	// turn preference at all. At a saddle the two outgoing edges belong to the
	// two different diagonal cells, as do the two incoming edges, so
	// continuing onto the edge of the SAME cell keeps each region's ring to
	// itself. An earlier version instead preferred a left turn, decided from
	// the direction of travel at the moment the corner was visited - which is
	// correct at a single saddle but not when one walk meets two of them: the
	// walk could route back through the first saddle instead of closing there
	// and emit a figure-eight. Pairing by cell is direction-independent, so
	// visit order cannot change the answer.
	type outEdge struct {
		dir  int
		cell corner // the cell whose boundary this edge is
		used bool
	}
	edges := make(map[uint64][]outEdge)
	addEdge := func(c corner, dir int, owner corner) {
		k := cornerKey(c)
		edges[k] = append(edges[k], outEdge{dir: dir, cell: owner})
	}

	isSet := func(col, row int32) bool {
		if col < 0 || row < 0 || uint32(col) >= cs.Cols || uint32(row) >= cs.Rows {
			return false
		}
		return cs.getCell(uint32(col), uint32(row))
	}

	for row := int32(0); uint32(row) < cs.Rows; row++ {
		for col := int32(0); uint32(col) < cs.Cols; col++ {
			if !isSet(col, row) {
				continue
			}
			owner := corner{col, row}
			if !isSet(col, row-1) { // south edge, travel +x
				addEdge(corner{col, row}, dirPosX, owner)
			}
			if !isSet(col+1, row) { // east edge, travel +y
				addEdge(corner{col + 1, row}, dirPosY, owner)
			}
			if !isSet(col, row+1) { // north edge, travel -x
				addEdge(corner{col + 1, row + 1}, dirNegX, owner)
			}
			if !isSet(col-1, row) { // west edge, travel -y
				addEdge(corner{col, row + 1}, dirNegY, owner)
			}
		}
	}

	// Deterministic iteration order for reproducible output: walk corners in
	// sorted key order when picking each ring's starting edge.
	keys := make([]uint64, 0, len(edges))
	for k := range edges {
		keys = append(keys, k)
	}
	sort.Slice(keys, func(i, j int) bool { return keys[i] < keys[j] })

	// takeEdge claims an unused outgoing edge at corner c and returns its
	// direction plus the cell it bounds. prevCell is the cell whose boundary
	// the walk arrived on; at a saddle that is what decides which way to go,
	// and everywhere else there is only one candidate anyway.
	takeEdge := func(c corner, prevCell corner, havePrev bool) (int, corner, bool) {
		out := edges[cornerKey(c)]
		if havePrev {
			for i := range out {
				if !out[i].used && out[i].cell == prevCell {
					out[i].used = true
					return out[i].dir, out[i].cell, true
				}
			}
		}
		// A straight run passes from one cell's edge to the next cell's, so
		// the owner legitimately changes at an ordinary corner; and a ring's
		// first edge has no predecessor. Either way, any unused edge will do -
		// there is only ever more than one at a saddle, which the branch above
		// has already handled.
		for i := range out {
			if !out[i].used {
				out[i].used = true
				return out[i].dir, out[i].cell, true
			}
		}
		return 0, corner{}, false
	}

	var rings []tracedRing
	for _, k := range keys {
		for {
			start := corner{col: int32(uint32(k)), row: int32(uint32(k >> 32))}
			dir, cell, ok := takeEdge(start, corner{}, false)
			if !ok {
				break
			}
			// Walk until back at the starting corner.
			var raw []corner
			raw = append(raw, start)
			cur := corner{start.col + dirDX[dir], start.row + dirDY[dir]}
			for cur != start {
				raw = append(raw, cur)
				nd, ncell, ok := takeEdge(cur, cell, true)
				if !ok {
					// Cannot happen on a well-formed grid: every corner's
					// in-degree equals its out-degree by construction.
					return nil
				}
				cur = corner{cur.col + dirDX[nd], cur.row + dirDY[nd]}
				cell = ncell
			}

			// One walk can legitimately close on itself more than once. A
			// saddle is a single decision that cannot make both sides
			// simple: pairing by owning cell keeps the COVERED region
			// 4-connected, which necessarily makes the UNCOVERED region
			// 8-connected, so a hole threading two saddles comes back as a
			// figure-eight. Splitting the walk at its repeated corners
			// yields simple loops either way, and their union is the same
			// boundary, so nothing about coverage changes.
			for _, loop := range splitIntoSimpleLoops(raw) {
				rings = append(rings, finishRing(loop))
			}
		}
	}
	return rings
}

// splitIntoSimpleLoops breaks a closed corner walk into loops that each visit
// every corner at most once. A walk that never repeats a corner comes back
// unchanged (one loop), which is the ordinary case.
//
// Standard closed-walk decomposition: carry the path on a stack, and when a
// corner reappears, the segment from its first occurrence to just before now
// is a complete loop - pop it and carry on. Whatever is left on the stack at
// the end closes back to the start.
func splitIntoSimpleLoops(raw []corner) [][]corner {
	var loops [][]corner
	stack := make([]corner, 0, len(raw))
	at := make(map[corner]int, len(raw))

	for _, c := range raw {
		if j, seen := at[c]; seen {
			loop := make([]corner, len(stack)-j)
			copy(loop, stack[j:])
			if len(loop) >= 3 {
				loops = append(loops, loop)
			}
			for _, x := range stack[j:] {
				delete(at, x)
			}
			stack = stack[:j]
		}
		at[c] = len(stack)
		stack = append(stack, c)
	}
	if len(stack) >= 3 {
		loops = append(loops, stack)
	}

	return loops
}

// finishRing merges collinear runs and computes the signed area.
func finishRing(raw []corner) tracedRing {
	n := len(raw)
	verts := make([]corner, 0, n/2)
	for i := 0; i < n; i++ {
		p, c, nx := raw[(i+n-1)%n], raw[i], raw[(i+1)%n]
		// Keep c only when the direction changes there.
		if (c.col-p.col == nx.col-c.col) && (c.row-p.row == nx.row-c.row) {
			continue
		}
		verts = append(verts, c)
	}
	var area2 int64
	for i := range verts {
		a, b := verts[i], verts[(i+1)%len(verts)]
		area2 += int64(a.col)*int64(b.row) - int64(b.col)*int64(a.row)
	}
	return tracedRing{verts: verts, area2: area2}
}

// simplifyRing Douglas-Peucker-simplifies a closed ring's vertices with the
// tolerance in DEGREES (matching ST_Simplify's units on these geometries).
// The ring is split at its two mutually-farthest vertices so DP has stable
// anchors; rings simplified to fewer than 4 distinct corners are dropped
// (they can no longer enclose area worth drawing).
func simplifyRing(verts []corner, tolCells float64) []corner {
	if tolCells <= 0 || len(verts) <= 3 {
		return verts
	}
	// Farthest pair by scanning from vertex 0 (adequate anchor choice for
	// display simplification; exactness is not a goal here).
	far := 0
	var best int64 = -1
	for i, v := range verts {
		d := sqDist(verts[0], v)
		if d > best {
			best, far = d, i
		}
	}
	second := make([]corner, 0, len(verts)-far+1)
	second = append(second, verts[far:]...)
	second = append(second, verts[0])
	a := append(dpSimplify(verts[:far+1], tolCells), dpSimplify(second, tolCells)[1:]...)
	// The two halves each end where the other begins; drop the duplicated
	// closing vertex (verts[0]) at the end.
	a = a[:len(a)-1]
	// Three distinct corners (a triangle) is the smallest ring that still
	// encloses area; below that the ring cannot be drawn and is dropped.
	if len(a) < 3 {
		return nil
	}
	return a
}

func sqDist(a, b corner) int64 {
	dx, dy := int64(a.col-b.col), int64(a.row-b.row)
	return dx*dx + dy*dy
}

// dpSimplify is classic Douglas-Peucker over an open polyline in corner
// coordinates, tolerance in cell units.
func dpSimplify(pts []corner, tol float64) []corner {
	if len(pts) <= 2 {
		return pts
	}
	a, b := pts[0], pts[len(pts)-1]
	worst, at := -1.0, -1
	for i := 1; i < len(pts)-1; i++ {
		d := perpDist(pts[i], a, b)
		if d > worst {
			worst, at = d, i
		}
	}
	if worst <= tol {
		return []corner{a, b}
	}
	left := dpSimplify(pts[:at+1], tol)
	right := dpSimplify(pts[at:], tol)
	return append(left[:len(left)-1], right...)
}

func perpDist(p, a, b corner) float64 {
	dx, dy := float64(b.col-a.col), float64(b.row-a.row)
	if dx == 0 && dy == 0 {
		ex, ey := float64(p.col-a.col), float64(p.row-a.row)
		return math.Sqrt(ex*ex + ey*ey)
	}
	num := dx*float64(p.row-a.row) - dy*float64(p.col-a.col)
	if num < 0 {
		num = -num
	}
	return num / math.Sqrt(dx*dx+dy*dy)
}

// ToMultiPolygonWKT traces the boundary and renders it as MULTIPOLYGON WKT in
// degrees, holes nested under the shell that contains them. toleranceDegrees
// 0 keeps the exact lattice outline (roundtrip-safe); positive values
// simplify each ring for display. Returns an error for an empty grid.
func (cs *CellSet) ToMultiPolygonWKT(toleranceDegrees float64) (string, error) {
	rings := cs.TraceBoundary()
	if len(rings) == 0 {
		return "", fmt.Errorf("cellset: empty grid has no boundary")
	}
	tolCells := toleranceDegrees / CellDegrees

	type polygon struct {
		shell tracedRing
		holes []tracedRing
	}
	var shells []polygon
	var holes []tracedRing
	for _, r := range rings {
		if r.area2 > 0 {
			shells = append(shells, polygon{shell: r})
		} else {
			holes = append(holes, r)
		}
	}
	// Assign each hole to the smallest shell containing its representative
	// point (half a cell to the RIGHT of the hole's first edge, which points
	// into the enclosed uncovered area and sits strictly off lattice lines).
	for _, h := range holes {
		px, py := holeInteriorPoint(h)
		bestIdx, bestArea := -1, int64(0)
		for i, s := range shells {
			if pointInRing(px, py, s.shell.verts) {
				if bestIdx == -1 || s.shell.area2 < bestArea {
					bestIdx, bestArea = i, s.shell.area2
				}
			}
		}
		if bestIdx >= 0 {
			shells[bestIdx].holes = append(shells[bestIdx].holes, h)
		}
		// A hole with no containing shell cannot occur on a well-formed
		// trace; dropping it is the safe rendering if it ever did.
	}

	var sb strings.Builder
	sb.WriteString("MULTIPOLYGON(")
	wrote := false
	for _, p := range shells {
		sv := simplifyRing(p.shell.verts, tolCells)
		if sv == nil {
			continue
		}
		if wrote {
			sb.WriteByte(',')
		}
		wrote = true
		sb.WriteByte('(')
		cs.writeRing(&sb, sv)
		for _, h := range p.holes {
			hv := simplifyRing(h.verts, tolCells)
			if hv == nil {
				continue
			}
			sb.WriteByte(',')
			cs.writeRing(&sb, hv)
		}
		sb.WriteByte(')')
	}
	sb.WriteByte(')')
	if !wrote {
		return "", fmt.Errorf("cellset: no ring survived simplification")
	}
	return sb.String(), nil
}

// holeInteriorPoint returns a point (in local corner coordinates, as floats)
// strictly inside the uncovered area a hole ring encloses.
func holeInteriorPoint(h tracedRing) (float64, float64) {
	a, b := h.verts[0], h.verts[1]
	mx := (float64(a.col) + float64(b.col)) / 2
	my := (float64(a.row) + float64(b.row)) / 2
	// Right of travel: rotate the direction vector by -90.
	dx, dy := float64(b.col-a.col), float64(b.row-a.row)
	l := math.Sqrt(dx*dx + dy*dy)
	return mx + (dy/l)*0.5, my - (dx/l)*0.5
}

// pointInRing is even-odd point-in-polygon over local corner coordinates.
func pointInRing(px, py float64, verts []corner) bool {
	in := false
	n := len(verts)
	for i := 0; i < n; i++ {
		a, b := verts[i], verts[(i+1)%n]
		ay, by := float64(a.row), float64(b.row)
		if (ay <= py && py < by) || (by <= py && py < ay) {
			t := (py - ay) / (by - ay)
			x := float64(a.col) + t*float64(b.col-a.col)
			if x > px {
				in = !in
			}
		}
	}
	return in
}

// writeRing renders one ring in WKT vertex syntax, closing it by repeating
// the first vertex, converting corner coordinates to degrees exactly.
func (cs *CellSet) writeRing(sb *strings.Builder, verts []corner) {
	sb.WriteByte('(')
	for i := 0; i <= len(verts); i++ {
		v := verts[i%len(verts)]
		if i > 0 {
			sb.WriteByte(',')
		}
		x := float64(cs.MinCol+v.col) * CellDegrees
		y := float64(cs.MinRow+v.row) * CellDegrees
		sb.WriteString(strconv.FormatFloat(x, 'f', -1, 64))
		sb.WriteByte(' ')
		sb.WriteString(strconv.FormatFloat(y, 'f', -1, 64))
	}
	sb.WriteByte(')')
}
