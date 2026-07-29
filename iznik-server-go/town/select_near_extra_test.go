package town

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func dm(v float64) *float64 { return &v }

// SelectNear picks the furthest reachable towns within maxMinutes, capped at
// limit, then re-orders the result by population (ascending id) for display.
func TestSelectNear_FiltersUnreachable(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "Reachable", DriveMin: dm(10)},
		{ID: 2, Name: "TooFar", DriveMin: dm(50)},
		{ID: 3, Name: "Unreachable", DriveMin: nil},
	}
	got := SelectNear(cands, 20, 5)
	assert.Equal(t, []string{"Reachable"}, got)
}

func TestSelectNear_FurthestFirstThenLimited(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "Near", DriveMin: dm(5)},
		{ID: 2, Name: "Mid", DriveMin: dm(15)},
		{ID: 3, Name: "Far", DriveMin: dm(25)},
	}
	// Limit to 2: keeps the two FURTHEST (Far, Mid), then displays by id asc.
	got := SelectNear(cands, 30, 2)
	assert.Equal(t, []string{"Mid", "Far"}, got)
}

func TestSelectNear_TieBreakByPopulationSmallerIDWins(t *testing.T) {
	cands := []TownCand{
		{ID: 10, Name: "SmallerPopBigID", DriveMin: dm(20)},
		{ID: 2, Name: "BiggerPopSmallID", DriveMin: dm(20)},
	}
	// Both equally far; limit 1 keeps the tie-break winner (smaller id = bigger
	// population).
	got := SelectNear(cands, 30, 1)
	assert.Equal(t, []string{"BiggerPopSmallID"}, got)
}

func TestSelectNear_DisplayOrderIsPopulationDescending(t *testing.T) {
	cands := []TownCand{
		{ID: 5, Name: "Fifth", DriveMin: dm(5)},
		{ID: 1, Name: "First", DriveMin: dm(15)},
		{ID: 3, Name: "Third", DriveMin: dm(10)},
	}
	got := SelectNear(cands, 30, 5)
	// All reachable; displayed by ascending id regardless of drive-time order.
	assert.Equal(t, []string{"First", "Third", "Fifth"}, got)
}

func TestSelectNear_EmptyCandidates(t *testing.T) {
	assert.Empty(t, SelectNear(nil, 30, 5))
}

func TestSelectNear_NoneReachable(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Far", DriveMin: dm(999)}}
	assert.Empty(t, SelectNear(cands, 10, 5))
}

func TestSelectNear_ExactlyAtMaxMinutesIsReachable(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Edge", DriveMin: dm(30)}}
	got := SelectNear(cands, 30, 5)
	assert.Equal(t, []string{"Edge"}, got)
}

func TestSelectNear_LimitLargerThanCandidates(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Only", DriveMin: dm(5)}}
	got := SelectNear(cands, 30, 100)
	assert.Equal(t, []string{"Only"}, got)
}
