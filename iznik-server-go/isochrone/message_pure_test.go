package isochrone

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestIsochronesTableName(t *testing.T) {
	assert.Equal(t, "isochrones", Isochrones{}.TableName())
}

func TestReachEnvelopeExpr(t *testing.T) {
	// The envelope is always read from the outer bound, never from the legacy polygon.
	assert.Equal(t, "ST_AsText(ST_Envelope(rr.outer_bound))", reachEnvelopeExpr(nil))
}

func TestFilterProbedNilProbeReturnsUntouched(t *testing.T) {
	cells := []byte{1, 2, 3}
	tests := []struct {
		name  string
		cands []reachCandidateRow
	}{
		{"nil slice", nil},
		{"empty slice", []reachCandidateRow{}},
		{"rows keep their cells", []reachCandidateRow{{ID: 1, ReachCells: cells}, {ID: 2}}},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := filterProbed(tc.cands, nil)
			assert.Equal(t, tc.cands, got)
		})
	}
}

func TestFilterProbedAdmittedAndCovered(t *testing.T) {
	grid := oneCellGridAt(probeLng, probeLat)
	probe := &reachProbe{lng: probeLng, lat: probeLat, admitted: map[uint64]struct{}{7: {}}}

	got := filterProbed([]reachCandidateRow{
		{ID: 7, ReachCells: []byte{0xff}}, // ring-admitted: kept whatever its cells say.
		{ID: 8, ReachCells: grid},         // grid covers the viewer: kept, cells dropped.
	}, probe)

	assert.Len(t, got, 2)
	assert.Equal(t, uint64(7), got[0].ID)
	assert.Equal(t, uint64(8), got[1].ID)
	assert.Nil(t, got[1].ReachCells, "cells are dropped once the row is decided")
}

func TestFilterProbedRejectsUncoveredAndCorrupt(t *testing.T) {
	// A grid for a point far from the viewer does not cover them; a non-empty garbage
	// blob is undecidable and fails closed (it is not an "undecided retired grid").
	farGrid := oneCellGridAt(probeLng+1, probeLat+1)
	probe := &reachProbe{lng: probeLng, lat: probeLat, admitted: map[uint64]struct{}{}}

	got := filterProbed([]reachCandidateRow{
		{ID: 1, ReachCells: farGrid},
		{ID: 2, ReachCells: []byte{0xde, 0xad}},
	}, probe)

	assert.Empty(t, got)
}

func TestBlurredDistanceMilesDeterministic(t *testing.T) {
	r := reachCandidateRow{Lat: 51.51005, Lng: -0.09005}
	lat1, lng1, d1 := r.blurredDistanceMiles(51.5, -0.1)
	lat2, lng2, d2 := r.blurredDistanceMiles(51.5, -0.1)

	assert.Equal(t, lat1, lat2)
	assert.Equal(t, lng1, lng2)
	assert.Equal(t, d1, d2)
	assert.GreaterOrEqual(t, d1, 0.0)
	assert.Less(t, d1, 5.0, "a blurred point stays near the real one")

	// Viewer on the blurred point itself is at distance zero.
	_, _, d0 := r.blurredDistanceMiles(lat1, lng1)
	assert.InDelta(t, 0, d0, 1e-9)
}

func TestToSummary(t *testing.T) {
	w := ScoreWeights{Close: 1, Fresh: 1, Budget: 1, Anchor: 0}
	env := ScoreEnv{WindowHours: 48, BudgetDecay: 10, DefaultReachM: 30000, MaxMinutes: 30}
	now := time.Now()
	mins, miles := 12.0, 4.5

	tests := []struct {
		name     string
		row      reachCandidateRow
		myid     uint64
		mine     bool
		roadmins *float64
		roadmile *float64
	}{
		{"own post with drive metrics",
			reachCandidateRow{ID: 5, Fromuser: 9, Lat: 51.51, Lng: -0.09, Arrival: now.Add(-2 * time.Hour), Views: 3, Replies: 1, Unseen: true},
			9, true, &mins, &miles},
		{"someone else's post, no drive metrics",
			reachCandidateRow{ID: 6, Fromuser: 4, Lat: 51.51, Lng: -0.09, Arrival: now.Add(-time.Hour)},
			9, false, nil, nil},
		{"anonymous poster is never mine",
			reachCandidateRow{ID: 7, Fromuser: 0, Lat: 51.51, Lng: -0.09, Arrival: now},
			0, false, nil, nil},
		{"future arrival clamps age to zero",
			reachCandidateRow{ID: 8, Fromuser: 1, Lat: 51.51, Lng: -0.09, Arrival: now.Add(24 * time.Hour),
				ReachLat: 51.5, ReachLng: -0.1, ReachWKT: "POLYGON((-0.1 51.5,-0.0 51.5,-0.0 51.6,-0.1 51.6,-0.1 51.5))"},
			2, false, nil, nil},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			s := tc.row.toSummary(51.5, -0.1, w, env, tc.myid, tc.roadmins, tc.roadmile)

			assert.Equal(t, tc.row.ID, s.ID)
			assert.Equal(t, tc.row.Unseen, s.Unseen)
			assert.Equal(t, tc.mine, s.Mine)
			assert.Equal(t, tc.roadmins, s.Roadmins)
			assert.Equal(t, tc.roadmile, s.Roadmiles)

			bl, bg, dist := tc.row.blurredDistanceMiles(51.5, -0.1)
			assert.Equal(t, bl, s.Lat, "summary exposes the blurred latitude")
			assert.Equal(t, bg, s.Lng, "summary exposes the blurred longitude")
			assert.Equal(t, dist, s.Distance)
			assert.NotEqual(t, tc.row.Lat, s.Lat, "real coordinates are never exposed")
			assert.GreaterOrEqual(t, s.Score, 0.0)
		})
	}
}

func TestCandDriveMetricsNoCandidates(t *testing.T) {
	mins, miles := candDriveMetrics(51.5, -0.1, nil)
	assert.Empty(t, mins)
	assert.Empty(t, miles)
}
