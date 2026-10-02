package town

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func dm(v float64) *float64 { return &v }

// SelectNear names the biggest places in the outer half of the reach (maxMinutes/2 to
// maxMinutes), falling back to the biggest reachable places when the outer half is empty,
// capped at limit, biggest first.
func TestSelectNear_FiltersUnreachable(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "Reachable", Population: 5000, DriveMin: dm(10)},
		{ID: 2, Name: "TooFar", Population: 90000, DriveMin: dm(50)},
		{ID: 3, Name: "Unreachable", Population: 90000, DriveMin: nil},
	}
	got := SelectNear(cands, 20, 5)
	assert.Equal(t, []string{"Reachable"}, got)
}

func TestSelectNear_PrefersTheOuterHalf(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "BigNear", Population: 200000, DriveMin: dm(5)},
		{ID: 2, Name: "Mid", Population: 8000, DriveMin: dm(16)},
		{ID: 3, Name: "Far", Population: 40000, DriveMin: dm(25)},
	}
	// 30 minutes: only Mid and Far are 15+ minutes out, so the big place next door is left out
	// and the list moves outwards as the slider widens.
	got := SelectNear(cands, 30, 5)
	assert.Equal(t, []string{"Far", "Mid"}, got)
}

func TestSelectNear_BiggestWinTheLimit(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "EdgeVillage", Population: 3200, DriveMin: dm(29)},
		{ID: 2, Name: "City", Population: 245000, DriveMin: dm(20)},
		{ID: 3, Name: "Town", Population: 56000, DriveMin: dm(22)},
	}
	// The furthest place is a village; the limit keeps the places people know.
	got := SelectNear(cands, 30, 2)
	assert.Equal(t, []string{"City", "Town"}, got)
}

func TestSelectNear_FallsBackToAllReachableWhenOuterHalfEmpty(t *testing.T) {
	cands := []TownCand{
		{ID: 1, Name: "Small", Population: 4000, DriveMin: dm(1)},
		{ID: 2, Name: "Home", Population: 56000, DriveMin: dm(2)},
	}
	// 10 minutes: nothing 5+ minutes out, so name what is reachable, biggest first.
	got := SelectNear(cands, 10, 5)
	assert.Equal(t, []string{"Home", "Small"}, got)
}

func TestSelectNear_TieBreakBySmallerID(t *testing.T) {
	cands := []TownCand{
		{ID: 10, Name: "BigID", Population: 5000, DriveMin: dm(20)},
		{ID: 2, Name: "SmallID", Population: 5000, DriveMin: dm(20)},
	}
	got := SelectNear(cands, 30, 1)
	assert.Equal(t, []string{"SmallID"}, got)
}

func TestSelectNear_EmptyCandidates(t *testing.T) {
	assert.Empty(t, SelectNear(nil, 30, 5))
}

func TestSelectNear_NoneReachable(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Far", Population: 5000, DriveMin: dm(999)}}
	assert.Empty(t, SelectNear(cands, 10, 5))
}

func TestSelectNear_ExactlyAtMaxMinutesIsReachable(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Edge", Population: 5000, DriveMin: dm(30)}}
	got := SelectNear(cands, 30, 5)
	assert.Equal(t, []string{"Edge"}, got)
}

func TestSelectNear_LimitLargerThanCandidates(t *testing.T) {
	cands := []TownCand{{ID: 1, Name: "Only", Population: 5000, DriveMin: dm(5)}}
	got := SelectNear(cands, 30, 100)
	assert.Equal(t, []string{"Only"}, got)
}
