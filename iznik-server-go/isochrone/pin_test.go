package isochrone

import (
	"testing"

	"github.com/freegle/iznik-server-go/message"
)

// Browse "first two closest" pin: the two posts nearest the viewer go to the front
// (nearest first), the rest keep their existing order, and posts without coordinates
// are never pinned.
func TestPinClosestTwo(t *testing.T) {
	vlat, vlng := 51.50, -0.10 // viewer

	res := []message.MessageSummary{
		{ID: 1, Lat: 51.90, Lng: -0.10}, // ~44 km
		{ID: 2, Lat: 51.51, Lng: -0.10}, // ~1.1 km  (2nd nearest)
		{ID: 3, Lat: 51.70, Lng: -0.10}, // ~22 km
		{ID: 4, Lat: 51.505, Lng: -0.10}, // ~0.55 km (nearest)
		{ID: 5, Lat: 51.80, Lng: -0.10}, // ~33 km
	}

	out := pinClosestTwo(res, vlat, vlng)
	if out[0].ID != 4 || out[1].ID != 2 {
		t.Fatalf("expected nearest two (4,2) pinned, got %d,%d", out[0].ID, out[1].ID)
	}
	// The rest keep their original relative order: 1,3,5.
	for i, want := range []uint64{1, 3, 5} {
		if out[i+2].ID != want {
			t.Fatalf("rest order not preserved: got id=%d at %d, want %d", out[i+2].ID, i+2, want)
		}
	}

	// No-ops: viewer with no location, and lists of two or fewer.
	if got := pinClosestTwo(res, 0, 0); got[0].ID != 1 {
		t.Fatalf("no viewer location should be a no-op, got first id=%d", got[0].ID)
	}
	if got := pinClosestTwo(res[:2], vlat, vlng); len(got) != 2 || got[0].ID != 1 {
		t.Fatalf("<=2 posts should be a no-op")
	}

	// A coordinate-less post is never pinned even if listed first.
	res2 := []message.MessageSummary{
		{ID: 10, Lat: 0, Lng: 0},        // no coords
		{ID: 11, Lat: 51.90, Lng: -0.10}, // far
		{ID: 12, Lat: 51.505, Lng: -0.10}, // nearest
		{ID: 13, Lat: 51.51, Lng: -0.10}, // 2nd nearest
	}
	out2 := pinClosestTwo(res2, vlat, vlng)
	if out2[0].ID != 12 || out2[1].ID != 13 {
		t.Fatalf("expected (12,13) pinned, got %d,%d", out2[0].ID, out2[1].ID)
	}
}
