package isochrone

import (
	"encoding/binary"
	"testing"
)

// reachProbe.keep is the DEGRADED-PATH admission decision, and it merged
// untested: isochrone/reachbounds.go went in at 44.7% statement coverage with
// the whole of keep among the uncovered blocks.
//
// It matters because of when it runs. reachContainmentSQL narrows to the
// outer_bound SUPERSET whenever it cannot ask the exact question in SQL, and
// then every candidate row the feed returns has to be refined here, in Go,
// against that row's stored cells. So this function decides who sees a post
// in exactly the situation where nothing else is checking.
//
// Two behaviours are worth pinning down, and they pull in opposite directions:
// a ring-admitted post is in WHATEVER the committed reach says (the OR arm in
// the SQL), while an undecidable grid must fail CLOSED. Getting the second one
// backwards is the dangerous direction - a wrongly-held reply can be released
// later, a wrongly-admitted one has already been delivered.

// cellDegrees mirrors rippling's unexported constant (and CellSetService's, and
// the spatial server's). If they ever diverge these tests stop describing the
// real lattice, which is itself worth knowing.
const testCellDegrees = 0.0003

// floorCell is rippling.floorDivCellSet, replicated because it is unexported.
// The grid has to be built the same way CellSetContains reads it, or the test
// proves nothing about the real format.
func floorCell(v float64) int32 {
	q := v / testCellDegrees
	f := float64(int64(q))
	if q < f {
		f--
	}
	return int32(f)
}

// oneCellGridAt builds the smallest valid cell set that COVERS the given
// point: a 1x1 grid on that point's own cell, encoded as the wire format's
// alternating run stream (runs start CLEAR, so a zero-length clear run then a
// single covered cell).
func oneCellGridAt(lng, lat float64) []byte {
	b := make([]byte, 20)
	binary.LittleEndian.PutUint32(b[0:4], 0x31534343) // "CCS1"
	binary.LittleEndian.PutUint32(b[4:8], uint32(floorCell(lng)))
	binary.LittleEndian.PutUint32(b[8:12], uint32(floorCell(lat)))
	binary.LittleEndian.PutUint32(b[12:16], 1) // cols
	binary.LittleEndian.PutUint32(b[16:20], 1) // rows
	// LEB128, and both values are < 0x80, so one byte each.
	return append(b, 0x00, 0x01)
}

// Coordinates deliberately off a cell boundary: on one, the floor could land
// either side of it under floating-point error and the test would be flaky for
// a reason that has nothing to do with the code.
const probeLng, probeLat = -0.09005, 51.51005

func TestReachProbeKeepsAPointInsideTheStoredCells(t *testing.T) {
	p := &reachProbe{lng: probeLng, lat: probeLat, admitted: map[uint64]struct{}{}}

	if !p.keep(1, oneCellGridAt(probeLng, probeLat)) {
		t.Error("a point inside the stored grid was dropped, so the viewer loses a post that does reach them")
	}
}

func TestReachProbeDropsAPointOutsideTheStoredCells(t *testing.T) {
	p := &reachProbe{lng: probeLng, lat: probeLat, admitted: map[uint64]struct{}{}}

	// A grid a long way from the probe: definitely outside, and CellSetContains
	// says so definitely rather than declining to answer.
	if p.keep(1, oneCellGridAt(1.5, 52.5)) {
		t.Error("a point outside the stored grid was admitted")
	}
}

// THE fail-closed case. Post-drop a healthy row always has readable cells, so
// an unreadable blob is a row that must not decide anything - and the safe
// direction is to hold, not to admit.
func TestReachProbeFailsClosedOnUndecidableCells(t *testing.T) {
	cases := map[string][]byte{
		"nil":              nil,
		"empty":            {},
		"bad magic":        {0x00, 0x01, 0x02, 0x03, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0},
		"truncated header": {0x43, 0x43, 0x53, 0x31},
		"zero-sized grid":  append([]byte{0x43, 0x43, 0x53, 0x31}, make([]byte, 16)...),
		"header but no runs": {0x43, 0x43, 0x53, 0x31,
			0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0},
	}
	for name, cells := range cases {
		t.Run(name, func(t *testing.T) {
			p := &reachProbe{lng: probeLng, lat: probeLat, admitted: map[uint64]struct{}{}}

			if p.keep(1, cells) {
				t.Errorf("%s cells admitted the viewer; an undecidable grid must fail closed", name)
			}
		})
	}
}

// A ring-admitted post is in however the committed reach reads, which is what
// the OR arm in the SQL says - so the probe must not second-guess it. This is
// the one case where unreadable cells still yield true, and it is deliberate:
// the ring, not the reach, is what admitted this member.
func TestReachProbeKeepsRingAdmittedPostsRegardlessOfTheirCells(t *testing.T) {
	p := &reachProbe{
		lng:      probeLng,
		lat:      probeLat,
		admitted: map[uint64]struct{}{77: {}},
	}

	// Cells that place the point firmly outside, and cells that cannot be read
	// at all. Both must still be kept for the admitted msgid.
	if !p.keep(77, oneCellGridAt(1.5, 52.5)) {
		t.Error("a ring-admitted post was dropped because the committed reach did not cover the point")
	}
	if !p.keep(77, nil) {
		t.Error("a ring-admitted post was dropped for unreadable cells; the ring is what admits it")
	}

	// And admission is per-msgid, not a blanket pass for the whole request.
	if p.keep(78, nil) {
		t.Error("admission leaked to a msgid that no ring admits")
	}
}
