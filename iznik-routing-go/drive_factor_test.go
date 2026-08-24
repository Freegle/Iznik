package main

import (
	"math"
	"testing"
)

// The per-class drive-speed factors are layered on top of an optional
// ROUTING_DRIVE_SPEED_FACTOR override.  We can't easily mutate the override
// at test time (it's read once at package init), so assertions either divide
// it out or use ratios between classes, which cancel it.
//
// The factors were calibrated against ~2,500 Google Routes journeys (see the
// calibration comment in graph.go and cmd/calibrate).  These tests pin the
// STRUCTURE the calibration found, so an accidental edit that breaks the
// shape of the model fails loudly:
//
//  1. tagged factors never exceed 1.05 (average speed can't beat the limit)
//  2. untagged factors for non-SRN classes are >= 1 (the class defaults are
//     conservative; rural A/B roads really flow near the national limit)
//  3. motorways are the fastest effective class

func TestDriveSpeedFactorTaggedNeverExceedsLimit(t *testing.T) {
	for tag, pair := range driveClassFactors {
		if pair[1] > 1.05 {
			t.Errorf("%s: tagged factor %f exceeds 1.05 - average speed cannot beat the posted limit", tag, pair[1])
		}
	}
	if driveFallbackFactor[1] > 1.05 {
		t.Errorf("fallback tagged factor %f exceeds 1.05", driveFallbackFactor[1])
	}
}

func TestDriveSpeedFactorUntaggedLiftsConservativeDefaults(t *testing.T) {
	// Calibration found the untagged base speeds for A/B/unclassified roads
	// were far too slow (the old blanket 0.57 was ~35% pessimistic in rural
	// areas).  Guard the correction: untagged factors for these classes stay
	// at or above 1.0.
	for _, tag := range []string{
		"primary", "primary_link",
		"secondary", "secondary_link",
		"tertiary", "tertiary_link",
		"unclassified", "service",
	} {
		got := driveSpeedFactorFor(tag, false) / driveSpeedFactorOverride
		if got < 1.0 {
			t.Errorf("%s: untagged factor %f fell below 1.0 - the conservative-default correction was lost", tag, got)
		}
	}
}

func TestDriveSpeedFactorForTaggedVsUntaggedDiffer(t *testing.T) {
	// A maxspeed-tagged way's base is the legal limit; an untagged way's base
	// is a conservative default.  The two factors must be independent - on
	// A-roads the calibrated values differ by ~1.7x.
	un := driveSpeedFactorFor("primary", false)
	tg := driveSpeedFactorFor("primary", true)
	if un <= tg {
		t.Errorf("primary: untagged factor (%f) should exceed tagged (%f)", un, tg)
	}
}

func TestDriveSpeedFactorForUnknownClassFallsBackToARoad(t *testing.T) {
	for _, tag := range []string{"", "bridleway", "raceway", "no_such_tag"} {
		gotU := driveSpeedFactorFor(tag, false) / driveSpeedFactorOverride
		gotT := driveSpeedFactorFor(tag, true) / driveSpeedFactorOverride
		if math.Abs(float64(gotU-driveFallbackFactor[0])) > 1e-6 ||
			math.Abs(float64(gotT-driveFallbackFactor[1])) > 1e-6 {
			t.Errorf("%s (unknown): expected fallback pair %v, got {%f, %f}", tag, driveFallbackFactor, gotU, gotT)
		}
	}
}

func TestEffectiveSpeedOrderingMotorwayFastest(t *testing.T) {
	// Effective untagged speeds (base x factor) must keep motorways fastest,
	// so route choice still prefers the strategic network.
	mw := 27.8 * driveSpeedFactorFor("motorway", false)
	pr := 13.9 * driveSpeedFactorFor("primary", false)
	res := 8.3 * driveSpeedFactorFor("residential", false)
	if !(mw > pr && pr > res) {
		t.Errorf("expected motorway (%f) > primary (%f) > residential (%f) effective m/s", mw, pr, res)
	}
}

func TestDriveSpeedFactorAppliedToGraphEdges(t *testing.T) {
	// End-to-end: two parallel single-edge graphs with identical-length ways
	// but different highway classes have drive times in the inverse ratio of
	// their effective speeds (no junction features here, so no penalties).
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
	expected := float32(8.3*driveSpeedFactorFor("residential", false)) /
		float32(27.8*driveSpeedFactorFor("motorway", false))
	if math.Abs(float64(ratio-expected)) > 0.01 {
		t.Errorf("motorway/residential drive-time ratio: got %f, expected ≈%f", ratio, expected)
	}
	if mwSec >= resSec {
		t.Errorf("motorway should be quicker than residential, got %f vs %f", mwSec, resSec)
	}
}
