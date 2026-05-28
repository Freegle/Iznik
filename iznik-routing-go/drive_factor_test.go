package main

import (
	"math"
	"testing"
)

// The per-class drive-speed factor is layered on top of an optional
// ROUTING_DRIVE_SPEED_FACTOR override.  We can't easily mutate the override
// at test time (it's read once at package init), so most assertions use
// ratios between classes — those cancel the override and leave the
// calibrated DfT proportions visible (motorway 0.81, A-roads 0.57,
// residential 0.50).

func TestDriveSpeedFactorFor_MotorwayBand(t *testing.T) {
	// All four motorway/trunk variants share the SRN 0.81 factor.
	for _, tag := range []string{"motorway", "motorway_link", "trunk", "trunk_link"} {
		got := driveSpeedFactorFor(tag) / driveSpeedFactorOverride
		if math.Abs(float64(got-0.81)) > 1e-6 {
			t.Errorf("%s: expected 0.81 (DfT SRN), got %f", tag, got)
		}
	}
}

func TestDriveSpeedFactorFor_ARoadBand(t *testing.T) {
	// primary/secondary/tertiary (and their _link variants) share the
	// 0.57 local-A-road factor.
	for _, tag := range []string{
		"primary", "primary_link",
		"secondary", "secondary_link",
		"tertiary", "tertiary_link",
	} {
		got := driveSpeedFactorFor(tag) / driveSpeedFactorOverride
		if math.Abs(float64(got-0.57)) > 1e-6 {
			t.Errorf("%s: expected 0.57 (DfT local A-roads), got %f", tag, got)
		}
	}
}

func TestDriveSpeedFactorFor_LocalRoadBand(t *testing.T) {
	// Residential / unclassified / service share the 0.50 estimate
	// (slower than A-roads due to side-friction; not directly measured
	// by DfT).
	for _, tag := range []string{
		"residential", "unclassified", "living_street", "service", "track",
	} {
		got := driveSpeedFactorFor(tag) / driveSpeedFactorOverride
		if math.Abs(float64(got-0.50)) > 1e-6 {
			t.Errorf("%s: expected 0.50 (residential estimate), got %f", tag, got)
		}
	}
}

func TestDriveSpeedFactorFor_UnknownClassFallsBackToARoad(t *testing.T) {
	// Anything we don't recognise is treated as A-road equivalent so we
	// don't accidentally over-scale weird OSM tags.
	for _, tag := range []string{"", "bridleway", "raceway", "no_such_tag"} {
		got := driveSpeedFactorFor(tag) / driveSpeedFactorOverride
		if math.Abs(float64(got-0.57)) > 1e-6 {
			t.Errorf("%s (unknown): expected 0.57 fallback, got %f", tag, got)
		}
	}
}

func TestDriveSpeedFactorFor_OrderingMatchesDfT(t *testing.T) {
	// Strict ordering of the three bands — guards against accidental
	// re-tweaking that would flip the relative attractiveness of road
	// classes (which is what makes the per-class change meaningful vs
	// the old flat 0.7).
	srn := driveSpeedFactorFor("motorway")
	aRoad := driveSpeedFactorFor("primary")
	local := driveSpeedFactorFor("residential")
	if !(srn > aRoad && aRoad > local) {
		t.Errorf("expected motorway (%f) > primary (%f) > residential (%f)", srn, aRoad, local)
	}
}

func TestDriveSpeedFactorFor_AppliedToGraphEdges(t *testing.T) {
	// End-to-end: two parallel single-edge graphs with identical-length
	// ways but different highway classes should have Drive times in the
	// ratio of (raw speed × per-class factor)⁻¹.
	//
	// Reference values from highwaySpeed[Drive]:
	//   motorway:    27.8 m/s  × 0.81 = 22.5 m/s effective
	//   residential:  8.3 m/s  × 0.50 = 4.15 m/s effective
	// → motorway / residential = 0.184 (motorway is ~5.4× faster)

	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.45, Lng: -2.59},
		{OSMID: 2, Lat: 51.45, Lng: -2.58},
	}

	motorwayG := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "motorway", Oneway: true},
	}, nil)
	residentialG := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "residential", Oneway: true},
	}, nil)

	var mwSec, resSec float32
	for _, e := range motorwayG.EdgesFrom(1) {
		if e.To == 2 {
			mwSec = e.Seconds[Drive]
		}
	}
	for _, e := range residentialG.EdgesFrom(1) {
		if e.To == 2 {
			resSec = e.Seconds[Drive]
		}
	}
	if mwSec <= 0 || resSec <= 0 {
		t.Fatalf("expected positive drive seconds, got motorway=%f residential=%f", mwSec, resSec)
	}

	ratio := mwSec / resSec
	// Expected: (8.3 × 0.50) / (27.8 × 0.81) ≈ 0.184.
	// The override multiplier cancels in this ratio.
	expected := float32(8.3*0.50) / float32(27.8*0.81)
	if math.Abs(float64(ratio-expected)) > 0.01 {
		t.Errorf("motorway/residential drive-time ratio: got %f, expected ≈%f", ratio, expected)
	}

	// And — crucially — motorway is faster.  This is the property that
	// changed when we moved off the flat 0.7× factor.
	if mwSec >= resSec {
		t.Errorf("motorway should be quicker than residential, got %f vs %f", mwSec, resSec)
	}
}
