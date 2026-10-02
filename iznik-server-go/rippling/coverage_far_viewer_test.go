package rippling_test

import (
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

// A viewer far outside any drive-time budget must get the "reach ends" answer even when
// the routing search cannot be used (down, breaker open, per-request cap). Without it the
// client falls back to "we'll pass it on as soon as it does", which promises an arrival
// that can never happen for someone 130 miles away.
func TestEstimateCoverageFarViewerNeedsNoRouting(t *testing.T) {
	hazard := rippling.DefaultHazardHours()
	arrival := time.Date(2026, 8, 12, 9, 0, 0, 0, time.UTC)
	ticks := []rippling.ScheduleTick{{Tick: 1, DriveMin: 10}, {Tick: 9, DriveMin: 45}}

	routingCalls := 0
	unavailable := func(float64, float64, float64, float64, float64) (rippling.DriveTime, bool) {
		routingCalls++
		return rippling.DriveTime{}, false
	}

	// Roughly 130 miles apart.
	cov, ok := rippling.EstimateCoverage(ticks, hazard, arrival, 52.0, -1.0, 53.9, -1.0, unavailable)
	assert.True(t, ok, "a far viewer still gets an estimate with routing unavailable")
	assert.False(t, cov.Covered, "no tick of the schedule will ever cover them")
	assert.Equal(t, arrival.Add(168*time.Hour), cov.At)
	assert.Equal(t, 0, routingCalls, "no routing search is needed to rule out a far viewer")

	// A nearby viewer still needs routing, and without it there is no estimate.
	_, ok = rippling.EstimateCoverage(ticks, hazard, arrival, 52.0, -1.0, 52.05, -1.0, unavailable)
	assert.False(t, ok)
	assert.Equal(t, 1, routingCalls)
}
