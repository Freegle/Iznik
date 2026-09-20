package main

import (
	"testing"
)

// Single-track roads (two-way, one lane - Highland and island roads with
// passing places) are driven at ~28mph regardless of class or signed limit:
// physical narrowness sets the speed.  They get a FIXED base (the 60mph
// single-carriageway national limit) times a calibrated factor, replacing the
// class speed entirely.

func TestSingleTrackViaLanesTag(t *testing.T) {
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000},
	}
	normal := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "unclassified"},
	}, nil)
	single := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "unclassified", Lanes: "1"},
	}, nil)

	n := driveSecs(normal, 1, 2)
	s := driveSecs(single, 1, 2)
	if n <= 0 || s <= 0 {
		t.Fatalf("expected drivable edges, got %f / %f", n, s)
	}
	// expected single-track seconds: dist / (26.8 * factor)
	d := haversineM(51.500, -1.000, 51.501, -1.000)
	want := float32(d / (26.8 * float64(driveSingleTrackFactor)))
	if absF32(s-want) > 0.05 {
		t.Errorf("single-track edge: got %fs, want %fs (fixed 26.8 m/s base x %f)", s, want, driveSingleTrackFactor)
	}
	// a oneway lanes=1 way is NOT single-track (it is one direction of a pair)
	oneway := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "unclassified", Lanes: "1", Oneway: true},
	}, nil)
	if absF32(driveSecs(oneway, 1, 2)-n) > 0.05 {
		t.Errorf("oneway lanes=1 should keep its class speed")
	}
	// walk/cycle unaffected
	if absF32(modeSecs(single, 1, 2, Walk)-modeSecs(normal, 1, 2, Walk)) > 0.01 {
		t.Errorf("walk should not change on single-track")
	}
}

func TestSingleTrackViaPassingPlaces(t *testing.T) {
	// A way whose interior nodes carry >=2 highway=passing_place tags is
	// single-track even without a lanes tag; a single passing-place node
	// (e.g. shared with a single-track side road at a junction) is not enough.
	mk := func(tag2, tag3 string) *Graph {
		nodes := []RawNodeSpec{
			{OSMID: 1, Lat: 51.500, Lng: -1.000},
			{OSMID: 2, Lat: 51.501, Lng: -1.000, Highway: tag2},
			{OSMID: 3, Lat: 51.502, Lng: -1.000, Highway: tag3},
			{OSMID: 4, Lat: 51.503, Lng: -1.000},
		}
		return BuildGraphFromRaw(nodes, []RawWaySpec{
			{NodeIDs: []int64{1, 2, 3, 4}, Highway: "unclassified"},
		}, nil)
	}
	plain := mk("", "")
	one := mk("passing_place", "")
	two := mk("passing_place", "passing_place")

	if absF32(driveSecs(one, 1, 2)-driveSecs(plain, 1, 2)) > 0.05 {
		t.Errorf("one passing place must not mark the way single-track")
	}
	d := haversineM(51.500, -1.000, 51.501, -1.000)
	want := float32(d / (26.8 * float64(driveSingleTrackFactor)))
	if absF32(driveSecs(two, 1, 2)-want) > 0.05 {
		t.Errorf("two passing places should mark the way single-track: got %f want %f", driveSecs(two, 1, 2), want)
	}
}

// The GB national speed limit is 60mph on single carriageways and 70mph on
// dual carriageways (mapped as oneway ways).  Roundabouts are oneway but not
// dual carriageways.
func TestNationalLimitSingleVsDual(t *testing.T) {
	if v := parseMaxspeedCtx("national", false); absF32(v-26.8) > 0.01 {
		t.Errorf("national on a two-way (single carriageway) should be 26.8 m/s, got %f", v)
	}
	if v := parseMaxspeedCtx("national", true); absF32(v-31.3) > 0.01 {
		t.Errorf("national on a oneway (dual carriageway) should be 31.3 m/s, got %f", v)
	}
	if v := parseMaxspeedCtx("GB:nsl_single", true); absF32(v-26.8) > 0.01 {
		t.Errorf("GB:nsl_single is explicit: 26.8 m/s, got %f", v)
	}
	if v := parseMaxspeedCtx("GB:nsl_dual", false); absF32(v-31.3) > 0.01 {
		t.Errorf("GB:nsl_dual is explicit: 31.3 m/s, got %f", v)
	}
	// numeric values unchanged
	if v := parseMaxspeedCtx("30 mph", false); absF32(v-13.4) > 0.01 {
		t.Errorf("numeric maxspeed must pass through, got %f", v)
	}
}

// Unpaved surfaces cap the BASE speed at 11.1 m/s (40 km/h, OSRM's value)
// before the class factor applies.
func TestUnpavedSurfaceCapsBaseSpeed(t *testing.T) {
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},
		{OSMID: 2, Lat: 51.501, Lng: -1.000},
	}
	paved := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "primary", Maxspeed: "60 mph"},
	}, nil)
	gravel := BuildGraphFromRaw(nodes, []RawWaySpec{
		{NodeIDs: []int64{1, 2}, Highway: "primary", Maxspeed: "60 mph", Surface: "gravel"},
	}, nil)
	p := driveSecs(paved, 1, 2)
	g := driveSecs(gravel, 1, 2)
	if !(g > p*2) {
		t.Errorf("gravel 60mph primary should be far slower than paved: paved=%f gravel=%f", p, g)
	}
	d := haversineM(51.500, -1.000, 51.501, -1.000)
	want := float32(d / (11.1 * float64(driveSpeedFactorFor("primary", true))))
	if absF32(g-want) > 0.05 {
		t.Errorf("gravel base should cap at 11.1 m/s: got %f want %f", g, want)
	}
}

// give_way and stop nodes carry a fixed penalty like signals do.
func TestYieldPenaltyAddedToDriveSeconds(t *testing.T) {
	g := chainWithMidNodeTag("give_way")
	into := driveSecs(g, 1, 2)
	onward := driveSecs(g, 2, 3)
	if drivePenalties.Yield <= 0 {
		t.Fatalf("expected a positive yield penalty")
	}
	if absF32(into-(onward+drivePenalties.Yield)) > 0.01 {
		t.Errorf("edge into give_way node: got %f, want %f", into, onward+drivePenalties.Yield)
	}
	g2 := chainWithMidNodeTag("stop")
	if absF32(driveSecs(g2, 1, 2)-(driveSecs(g2, 2, 3)+drivePenalties.Yield)) > 0.01 {
		t.Errorf("stop node should carry the yield penalty too")
	}
}
