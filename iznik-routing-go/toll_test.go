package main

import (
	"testing"

	"github.com/paulmach/osm"
)

// wayWith builds an osm.Way carrying the given key/value tag pairs.
func wayWith(kv ...string) *osm.Way {
	w := &osm.Way{}
	for i := 0; i+1 < len(kv); i += 2 {
		w.Tags = append(w.Tags, osm.Tag{Key: kv[i], Value: kv[i+1]})
	}
	return w
}

// TestWaySpeedsAndOneway_TollExcludesDriving checks that ways tagged toll=yes
// are dropped from car routing (Drive = -1) while walking and cycling are left
// untouched. This is what keeps the UK toll crossings — the Humber Bridge,
// Dartford Crossing, M6 Toll, Mersey Gateway and Tyne Tunnel — out of drive
// isochrones. Verified end-to-end against real Humber OSM data: with this in
// place a drive isochrone from Hull no longer crosses the Humber Bridge, while
// the cycle isochrone (using the separate, untolled foot/cycle way) still does.
func TestWaySpeedsAndOneway_TollExcludesDriving(t *testing.T) {
	// Baseline: an untolled primary road allows all three modes.
	base, _, _ := waySpeedsAndOneway(wayWith("highway", "primary"))
	if base[Drive] <= 0 {
		t.Fatalf("untolled primary should be drivable, got Drive=%v", base[Drive])
	}
	if base[Walk] <= 0 || base[Cycle] <= 0 {
		t.Fatalf("untolled primary should allow walk/cycle, got Walk=%v Cycle=%v", base[Walk], base[Cycle])
	}

	// toll=yes on the same road drops the car edge only.
	tolled, _, _ := waySpeedsAndOneway(wayWith("highway", "primary", "toll", "yes"))
	if tolled[Drive] != -1 {
		t.Errorf("toll=yes primary must exclude driving (Drive=-1), got %v", tolled[Drive])
	}
	if tolled[Walk] != base[Walk] {
		t.Errorf("toll=yes must leave walking intact, got Walk=%v want %v", tolled[Walk], base[Walk])
	}
	if tolled[Cycle] != base[Cycle] {
		t.Errorf("toll=yes must leave cycling intact, got Cycle=%v want %v", tolled[Cycle], base[Cycle])
	}

	// The Humber Bridge carriageway is highway=trunk + toll=yes (trunk already
	// excludes foot/cycle; those cross on separate untagged ways). The car edge
	// must still be dropped.
	trunkToll, _, _ := waySpeedsAndOneway(wayWith("highway", "trunk", "toll", "yes"))
	if trunkToll[Drive] != -1 {
		t.Errorf("toll=yes trunk (Humber Bridge) must exclude driving, got %v", trunkToll[Drive])
	}
}
