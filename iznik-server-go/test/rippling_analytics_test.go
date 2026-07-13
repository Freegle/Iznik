package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

// StratumFilter maps a density stratum to a total_freeglers SQL predicate (terciles from live
// data: rural <1700, suburban 1700-3800, dense >3800). "all"/unknown add no bound.
func TestStratumFilter(t *testing.T) {
	assert.Equal(t, "", rippling.StratumFilter("all"))
	assert.Equal(t, "", rippling.StratumFilter("nonsense"))

	rural := rippling.StratumFilter("rural")
	assert.Contains(t, rural, "total_freeglers < 1700")

	sub := rippling.StratumFilter("suburban")
	assert.Contains(t, sub, ">= 1700")
	assert.Contains(t, sub, "< 3800")

	dense := rippling.StratumFilter("dense")
	assert.Contains(t, dense, ">= 3800")

	// Every non-empty filter is an AND-clause so it can be appended into a WHERE.
	for _, s := range []string{rural, sub, dense} {
		assert.True(t, strings.HasPrefix(strings.TrimSpace(s), "AND"),
			"stratum filter must be an appendable AND clause: %q", s)
	}
}

// The reliability bullseye bands reply->take conversion by drive-time ring (0-10, 10-15, 15-20,
// 20-25, 25-30, 30-45). Boundaries are the whole point (a value ON a non-last edge belongs to the
// OUTER ring; the last ring is inclusive of the isochrone cap so nothing is dropped), so they're
// pinned here along with the conversion maths.
func TestBullseye(t *testing.T) {
	// 6 replies placed to exercise each boundary rule and empty rings.
	mins := []float64{3, 10, 12, 27, 30, 45}
	takers := []bool{true, false, true, false, false, true}
	bands := rippling.Bullseye(mins, takers)

	// One band per (0,10,15,20,25,30,45] edge pair.
	assert.Len(t, bands, 6)

	// 0-10 ring: just the 3-minute reply, and it took.
	assert.Equal(t, 0, bands[0].MinLo)
	assert.Equal(t, 10, bands[0].MinHi)
	assert.Equal(t, 1, bands[0].NReplies)
	assert.Equal(t, 1, bands[0].NTakers)
	assert.Equal(t, 100.0, bands[0].ConvPct)

	// 10-15 ring: the 10.0 lands here (edge belongs to the OUTER ring, not 0-10) plus the 12; one took.
	assert.Equal(t, 10, bands[1].MinLo)
	assert.Equal(t, 15, bands[1].MinHi)
	assert.Equal(t, 2, bands[1].NReplies)
	assert.Equal(t, 1, bands[1].NTakers)
	assert.Equal(t, 50.0, bands[1].ConvPct)
	assert.Greater(t, bands[1].CIHalf, 0.0)

	// Empty middle rings (15-20, 20-25) report zeroes, not NaN, so the UI renders them blank.
	for _, idx := range []int{2, 3} {
		assert.Equal(t, 0, bands[idx].NReplies, "ring %d expected empty", idx)
		assert.Equal(t, 0.0, bands[idx].ConvPct)
		assert.Equal(t, 0.0, bands[idx].CIHalf)
	}

	// 25-30 ring: the 27 did not take.
	assert.Equal(t, 25, bands[4].MinLo)
	assert.Equal(t, 1, bands[4].NReplies)
	assert.Equal(t, 0.0, bands[4].ConvPct)

	// 30-45 ring is inclusive of the 45-minute cap: both the 30 and the 45 land here, one took.
	assert.Equal(t, 30, bands[5].MinLo)
	assert.Equal(t, 45, bands[5].MinHi)
	assert.Equal(t, 2, bands[5].NReplies)
	assert.Equal(t, 1, bands[5].NTakers)
	assert.Equal(t, 50.0, bands[5].ConvPct)

	// A shorter takers slice must not panic and counts no takes for the missing tail.
	safe := rippling.Bullseye(mins, []bool{true})
	assert.Equal(t, 1, safe[0].NTakers)
	assert.Equal(t, 0, safe[1].NTakers)

	// No observations -> 6 empty rings, no panic.
	empty := rippling.Bullseye(nil, nil)
	assert.Len(t, empty, 6)
	assert.Equal(t, 0, empty[0].NReplies)
}

// The sysadmin analytics UI loads drive-times from a SEPARATE endpoint
// (/rippling/analytics/drivetime) so the slow routing pass doesn't block the fast panels. This
// guards that the route is actually registered: it was added as a handler but not wired into the
// router, so the live UI got a 404 and silently hid the whole bullseye panel. The routing pass is
// best-effort (unreachable routing just yields an empty sample), so the response is a well-formed
// 200 carrying the 6 fixed bullseye rings regardless of the graph being reachable here.
func TestRipplingAnalyticsDriveTimeEndpoint(t *testing.T) {
	prefix := uniquePrefix("rippledrivetime")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/rippling/analytics/drivetime?jwt=%s", token), nil), -1)
	assert.Equal(t, 200, resp.StatusCode, "drivetime route must be registered (was 404)")

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	bullseye, ok := result["bullseye"].([]interface{})
	assert.True(t, ok, "drivetime response carries a bullseye array")
	assert.Len(t, bullseye, 6, "bullseye always has the 6 fixed drive-time rings")
}
