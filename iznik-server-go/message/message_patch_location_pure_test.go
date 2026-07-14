package message

import (
	"testing"

	"github.com/freegle/iznik-server-go/location"
)

// Pure unit tests for resolvePatchLocationID, the PATCH /message location-derivation
// helper behind Discourse 9908: a TN edit that supplies corrected lat/lng but no
// locationid must not leave the stale locationid in place, since locationid (not raw
// lat/lng) drives both the subject line and the owner/mod-facing postcode display.
func TestResolvePatchLocationID(t *testing.T) {
	nearestMK2 := func(lat, lng float32) location.Location { return location.Location{ID: 555, Name: "MK2 5AB"} }
	notFound := func(lat, lng float32) location.Location { return location.Location{} }
	shouldNotBeCalled := func(lat, lng float32) location.Location {
		t.Fatal("nearestPostcode should not be called when an explicit locationid is supplied or coordinates are incomplete")
		return location.Location{}
	}

	lat, lng := 52.0, -0.75
	explicitID := uint64(42)

	tests := []struct {
		name     string
		explicit *uint64
		lat, lng *float64
		nearest  func(lat, lng float32) location.Location
		wantID   uint64
		wantNil  bool
	}{
		{
			name:     "explicit locationid wins over lat/lng",
			explicit: &explicitID,
			lat:      &lat,
			lng:      &lng,
			nearest:  shouldNotBeCalled,
			wantID:   42,
		},
		{
			name:    "lat/lng without locationid derives nearest postcode",
			lat:     &lat,
			lng:     &lng,
			nearest: nearestMK2,
			wantID:  555,
		},
		{
			name:    "no lat, no lng, no locationid leaves it untouched",
			nearest: shouldNotBeCalled,
			wantNil: true,
		},
		{
			name:    "lat without lng leaves it untouched (incomplete coordinate pair)",
			lat:     &lat,
			nearest: shouldNotBeCalled,
			wantNil: true,
		},
		{
			name:    "nearest postcode not found leaves it untouched",
			lat:     &lat,
			lng:     &lng,
			nearest: notFound,
			wantNil: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got := resolvePatchLocationID(tt.explicit, tt.lat, tt.lng, tt.nearest)
			if tt.wantNil {
				if got != nil {
					t.Fatalf("resolvePatchLocationID() = %v, want nil", *got)
				}
				return
			}
			if got == nil {
				t.Fatalf("resolvePatchLocationID() = nil, want %d", tt.wantID)
			}
			if *got != tt.wantID {
				t.Fatalf("resolvePatchLocationID() = %d, want %d", *got, tt.wantID)
			}
		})
	}
}
