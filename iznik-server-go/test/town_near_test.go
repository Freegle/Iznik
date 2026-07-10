package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/town"
	"github.com/stretchr/testify/assert"
)

func townDriveMin(v float64) *float64 { return &v }

// SelectNear picks the FURTHEST towns reachable within the drive-time budget (so the "Near: ..."
// hint changes as the slider widens) and returns their names in descending-population order
// (ascending town id). Unreachable (nil) and beyond-budget towns are excluded.
func TestTownSelectNear(t *testing.T) {
	cands := []town.TownCand{
		{ID: 1, Name: "Big", DriveMin: townDriveMin(5)},
		{ID: 2, Name: "MidFar", DriveMin: townDriveMin(28)},
		{ID: 3, Name: "Near2", DriveMin: townDriveMin(8)},
		{ID: 4, Name: "FarSmall", DriveMin: townDriveMin(25)},
		{ID: 5, Name: "Unreachable", DriveMin: nil},
		{ID: 6, Name: "TooFar", DriveMin: townDriveMin(40)},
	}
	// Furthest 3 reachable within 30 min, displayed by population (ascending id).
	assert.Equal(t, []string{"MidFar", "Near2", "FarSmall"}, town.SelectNear(cands, 30, 3))
	// Fewer reachable than the limit -> all reachable, population order.
	assert.Equal(t, []string{"Big", "MidFar", "Near2", "FarSmall"}, town.SelectNear(cands, 30, 5))
	// None reachable in a tight budget, and empty input.
	assert.Empty(t, town.SelectNear(cands, 3, 5))
	assert.Empty(t, town.SelectNear(nil, 30, 5))
}
