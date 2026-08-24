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

// The fast-SQL analytics endpoint runs its four query blocks concurrently (and the two inside
// section 3 likewise) - serially they added up past the production gateway timeout. This guards
// that the route is registered and that the concurrent handler assembles a complete, well-formed
// payload (every section present, no panic from the goroutine fan-out) even on an empty test DB.
func TestRipplingAnalyticsEndpoint(t *testing.T) {
	prefix := uniquePrefix("rippleanalytics")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	url := fmt.Sprintf("/api/rippling/analytics?jwt=%s&stratum=rural", token)
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), 30000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "analytics route must be registered and answer")

	var body map[string]interface{}
	json.Unmarshal(rsp(resp), &body)
	assert.NotNil(t, body["sample_target"], "carries the drive-time sample target for the UI")
	assert.Equal(t, "rural", body["stratum"], "echoes the requested stratum")

	s1, ok := body["section1"].(map[string]interface{})
	assert.True(t, ok, "section1 KPI block present")
	assert.NotNil(t, s1["posts"], "section1 carries the post count")

	s2, ok := body["section2"].(map[string]interface{})
	assert.True(t, ok, "section2 trend block present")
	_, hasKpis := s2["kpis"]
	assert.True(t, hasKpis, "trend block carries the per-day kpis series")

	s3, ok := body["section3"].(map[string]interface{})
	assert.True(t, ok, "section3 rippled-out block present")
	assert.NotNil(t, s3["rescued_takes"], "section3 carries the rescue floor")
}

// The sysadmin analytics UI loads drive-times from a SEPARATE set of endpoints so the slow routing
// pass never blocks the fast panels and never 504s. The client drives it: fetch the SAMPLE, score
// it one chunk after another (serial), then aggregate. This guards that all three routes are
// registered (a handler-not-wired-into-the-router regression 404'd and silently hid the bullseye
// panel), and that the aggregate always carries the 6 fixed bullseye rings even when the routing
// graph is unreachable here (an empty sample still produces well-formed stats).
func TestRipplingAnalyticsDriveTimeEndpoint(t *testing.T) {
	prefix := uniquePrefix("rippledrivetime")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	// Step 1: the SAMPLE endpoint returns the posts to score (fast — one query, no routing). An 8s
	// cap fails loudly rather than hanging if it ever regresses into doing the routing pass inline.
	sampleURL := fmt.Sprintf("/api/rippling/analytics/drivetime?jwt=%s", token)
	resp, err := getApp().Test(httptest.NewRequest("GET", sampleURL, nil), 8000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "drivetime sample route must be registered (was 404) and answer promptly")
	var sample map[string]interface{}
	json.Unmarshal(rsp(resp), &sample)
	assert.NotNil(t, sample["total"], "sample reports a total for the client progress bar")
	posts, _ := sample["posts"].([]interface{})
	// posts may be empty in the test DB — the flow still holds.

	// Step 2: score the sample. Serial on the server; routing is unreachable here, so it returns a
	// best-effort (empty) obs set without hanging.
	postsJSON, _ := json.Marshal(map[string]interface{}{"posts": posts})
	scoreReq := httptest.NewRequest("POST",
		fmt.Sprintf("/api/rippling/analytics/drivetime/score?jwt=%s", token), strings.NewReader(string(postsJSON)))
	scoreReq.Header.Set("Content-Type", "application/json")
	sresp, err := getApp().Test(scoreReq, 8000)
	assert.NoError(t, err)
	assert.Equal(t, 200, sresp.StatusCode, "drivetime score route must be registered")
	var scored map[string]interface{}
	json.Unmarshal(rsp(sresp), &scored)
	_, hasObs := scored["obs"]
	assert.True(t, hasObs, "score response carries an obs array")

	// Step 3: aggregate the observations into the drive-time stats. Even an empty sample yields the
	// 6 fixed bullseye rings, so the UI never loses the panel.
	obsJSON, _ := json.Marshal(map[string]interface{}{"obs": scored["obs"]})
	aggReq := httptest.NewRequest("POST",
		fmt.Sprintf("/api/rippling/analytics/drivetime/aggregate?jwt=%s", token), strings.NewReader(string(obsJSON)))
	aggReq.Header.Set("Content-Type", "application/json")
	aresp, err := getApp().Test(aggReq, 8000)
	assert.NoError(t, err)
	assert.Equal(t, 200, aresp.StatusCode, "drivetime aggregate route must be registered")
	var done map[string]interface{}
	json.Unmarshal(rsp(aresp), &done)
	assert.Equal(t, "done", done["status"], "aggregate returns the done payload")
	bullseye, ok := done["bullseye"].([]interface{})
	assert.True(t, ok, "aggregate response carries a bullseye array")
	assert.Len(t, bullseye, 6, "bullseye always has the 6 fixed drive-time rings")
}
