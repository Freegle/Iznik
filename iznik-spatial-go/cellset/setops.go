package cellset

// Set relations between two grids on the shared global lattice - the cell
// forms of ST_Intersects(reach, area) and ST_Within(reach, area). Like
// Subtract, these are plain bit arithmetic with one possible answer (the
// lattice is fixed, so the operands already line up), so they are safe to
// exist in more than one language; unlike rasterising a boundary, nothing
// here involves a judgement call.

// Intersects reports whether the two grids share at least one covered cell.
func (cs *CellSet) Intersects(other *CellSet) bool {
	loCol, hiCol := maxInt32(cs.MinCol, other.MinCol), minInt32(cs.MinCol+int32(cs.Cols), other.MinCol+int32(other.Cols))
	loRow, hiRow := maxInt32(cs.MinRow, other.MinRow), minInt32(cs.MinRow+int32(cs.Rows), other.MinRow+int32(other.Rows))
	for gr := loRow; gr < hiRow; gr++ {
		for gc := loCol; gc < hiCol; gc++ {
			if cs.getCell(uint32(gc-cs.MinCol), uint32(gr-cs.MinRow)) &&
				other.getCell(uint32(gc-other.MinCol), uint32(gr-other.MinRow)) {
				return true
			}
		}
	}
	return false
}

// Within reports whether every covered cell of cs is also covered by other -
// the cell form of ST_Within(reach, group area). A reach with no covered
// cells is vacuously within anything, matching SQL's behaviour for an empty
// geometry as closely as an empty set can.
func (cs *CellSet) Within(other *CellSet) bool {
	for r := uint32(0); r < cs.Rows; r++ {
		gr := cs.MinRow + int32(r)
		or := gr - other.MinRow
		outsideRow := or < 0 || uint32(or) >= other.Rows
		for c := uint32(0); c < cs.Cols; c++ {
			if !cs.getCell(c, r) {
				continue
			}
			if outsideRow {
				return false
			}
			oc := cs.MinCol + int32(c) - other.MinCol
			if oc < 0 || uint32(oc) >= other.Cols || !other.getCell(uint32(oc), uint32(or)) {
				return false
			}
		}
	}
	return true
}

func minInt32(a, b int32) int32 {
	if a < b {
		return a
	}
	return b
}

func maxInt32(a, b int32) int32 {
	if a > b {
		return a
	}
	return b
}
