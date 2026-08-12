package test

import (
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

// The tick timetable, pinned. Tick k is live at hazardHours[k-1], with tick 1 live from
// arrival because it is the clamped initial value rather than a threshold.
//
// This mapping is worth a test of its own because it is easy to get wrong by one and
// impossible to notice when you do: MaxReachService::computePassthroughSavings uses
// hazardHours[k-2] for the same question, and live rows settle it - reaches that finish
// at tick k do so exactly hazardHours[k] hours after arrival (tick 1 at 3.0h, tick 4 at
// 24.0h, tick 8 at 168.0h), which is the moment they would have advanced again.
func TestCoverageTickTimetable(t *testing.T) {
	hazard := rippling.DefaultHazardHours() // 1,3,6,12,24,48,72,120,168
	arrival := time.Date(2026, 8, 12, 9, 0, 0, 0, time.UTC)

	// Budgets chosen so a given drive time first fits at a known tick.
	ticks := []rippling.ScheduleTick{
		{Tick: 1, DriveMin: 10},
		{Tick: 2, DriveMin: 20},
		{Tick: 3, DriveMin: 30},
		{Tick: 9, DriveMin: 40},
	}

	cases := []struct {
		name     string
		driveMin float64
		wantHrs  float64
	}{
		{"inside tick 1 is live from arrival, not from hazard[0]", 5, 0},
		{"tick 2 is live at 3h", 15, 3},
		{"tick 3 is live at 6h", 25, 6},
		{"the final tick is live at 168h", 35, 168},
	}

	for _, c := range cases {
		got, ok := rippling.CoverageAt(ticks, hazard, arrival, c.driveMin, true)
		if assert.True(t, ok, c.name) {
			assert.True(t, got.Covered, c.name)
			assert.Equal(t, arrival.Add(time.Duration(c.wantHrs*float64(time.Hour))), got.At, c.name)
		}
	}
}

// Beyond the widest budget the post will ever have, the answer is still a time: the reach
// stops expanding and releaseAll('maxed') passes the held reply on regardless. That is the
// common case, not the edge case - on live, 78% of held repliers are outside the eventual
// reach.
func TestCoverageBeyondFinalBudget(t *testing.T) {
	hazard := rippling.DefaultHazardHours()
	arrival := time.Date(2026, 8, 12, 9, 0, 0, 0, time.UTC)
	ticks := []rippling.ScheduleTick{
		{Tick: 1, DriveMin: 27},
		{Tick: 9, DriveMin: 30.7},
	}

	// Forty minutes out: no tick's budget ever gets there.
	got, ok := rippling.CoverageAt(ticks, hazard, arrival, 40, true)
	if assert.True(t, ok) {
		assert.False(t, got.Covered, "no tick covers them")
		assert.Equal(t, arrival.Add(168*time.Hour), got.At, "waits for the reach to finish")
	}

	// The routing search not reaching them inside its budget means the same thing, and
	// must not be mistaken for "covered at tick 1".
	got, ok = rippling.CoverageAt(ticks, hazard, arrival, 0, false)
	if assert.True(t, ok) {
		assert.False(t, got.Covered, "unreachable is not covered")
		assert.Equal(t, arrival.Add(168*time.Hour), got.At)
	}
}

// Ordering is by tick, not by position in the JSON, and the first tick whose budget
// reaches them is the one that matters - not the last.
func TestCoverageUsesFirstCoveringTick(t *testing.T) {
	hazard := rippling.DefaultHazardHours()
	arrival := time.Date(2026, 8, 12, 9, 0, 0, 0, time.UTC)

	ticks := rippling.ParseSchedule(`[{"tick":9,"drive_min":30},{"tick":2,"drive_min":25},{"tick":5,"drive_min":28}]`)
	if assert.Len(t, ticks, 3) {
		assert.Equal(t, 2, ticks[0].Tick, "parsed schedule is ordered by tick")
	}

	got, ok := rippling.CoverageAt(ticks, hazard, arrival, 24, true)
	if assert.True(t, ok) {
		assert.True(t, got.Covered)
		assert.Equal(t, arrival.Add(3*time.Hour), got.At, "tick 2 covers them, so 3h - not tick 9's 168h")
	}
}

// Unusable inputs give no estimate rather than a wrong one.
func TestCoverageRefusesUnusableInput(t *testing.T) {
	hazard := rippling.DefaultHazardHours()
	arrival := time.Now()

	_, ok := rippling.CoverageAt(nil, hazard, arrival, 10, true)
	assert.False(t, ok, "no schedule → no estimate")

	_, ok = rippling.CoverageAt([]rippling.ScheduleTick{{Tick: 1, DriveMin: 30}}, nil, arrival, 10, true)
	assert.False(t, ok, "no hazard schedule → no estimate")

	// A tick number past the end of the hazard schedule cannot be dated. This happens if
	// the two ever fall out of step, and inventing a time would be worse than silence.
	_, ok = rippling.CoverageAt([]rippling.ScheduleTick{{Tick: 99, DriveMin: 5}}, hazard, arrival, 10, true)
	assert.False(t, ok, "tick beyond the hazard schedule → no estimate")

	assert.Nil(t, rippling.ParseSchedule(""), "empty schedule column")
	assert.Nil(t, rippling.ParseSchedule("not json"), "malformed schedule column")
	assert.Nil(t, rippling.ParseSchedule("[]"), "empty tick list")
}
