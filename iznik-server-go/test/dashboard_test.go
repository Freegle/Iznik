package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestGetDashboardLegacy(t *testing.T) {
	prefix := uniquePrefix("Dashboard")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "dashboard")
	assert.Contains(t, result, "start")
	assert.Contains(t, result, "end")
}

func TestGetDashboardComponents(t *testing.T) {
	prefix := uniquePrefix("DashComp")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=RecentCounts,PopularPosts&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "components")

	comps := result["components"].(map[string]interface{})
	assert.Contains(t, comps, "RecentCounts")
	assert.Contains(t, comps, "PopularPosts")
}

func TestGetDashboardRecentCounts(t *testing.T) {
	prefix := uniquePrefix("DashRC")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=RecentCounts&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	comps := result["components"].(map[string]interface{})
	rc := comps["RecentCounts"].(map[string]interface{})
	assert.Contains(t, rc, "newmembers")
	assert.Contains(t, rc, "newmessages")
}

func TestGetDashboardModOnlyNotMod(t *testing.T) {
	prefix := uniquePrefix("DashModOnly")
	_, token := CreateFullTestUser(t, prefix)

	// UsersPosting requires moderator - regular user should get nil.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=UsersPosting&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	comps := result["components"].(map[string]interface{})
	assert.Nil(t, comps["UsersPosting"])
}

func TestGetDashboardTimeSeries(t *testing.T) {
	prefix := uniquePrefix("DashTS")
	_, token := CreateFullTestUser(t, prefix)

	// Activity reads from stats table - may return empty array for test groups.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=Activity&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	comps := result["components"].(map[string]interface{})
	// Activity should be an array (possibly empty for test data).
	_, ok := comps["Activity"].([]interface{})
	assert.True(t, ok, "Activity should be an array")
}

// TestGetDashboardApprovedMemberCountPublic verifies that member counts are public:
// a non-moderator gets the ApprovedMemberCount time-series (not nil), while the
// genuinely mod-only ActiveUsers stays withheld. (Authority stats page #member-counts.)
func TestGetDashboardApprovedMemberCountPublic(t *testing.T) {
	prefix := uniquePrefix("DashAMC")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=ApprovedMemberCount&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	comps := result["components"].(map[string]interface{})
	_, ok := comps["ApprovedMemberCount"].([]interface{})
	assert.True(t, ok, "ApprovedMemberCount must be a public array for non-moderators")

	// ActiveUsers must remain moderator-only (a non-mod gets nil).
	req2 := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=ActiveUsers&jwt=%s", token), nil)
	resp2, _ := getApp().Test(req2)
	var result2 map[string]interface{}
	json2.Unmarshal(rsp(resp2), &result2)
	comps2 := result2["components"].(map[string]interface{})
	assert.Nil(t, comps2["ActiveUsers"], "ActiveUsers must remain moderator-only")
}

func TestGetDashboardNoAuth(t *testing.T) {
	// Without auth, should still return success but with limited data.
	req := httptest.NewRequest("GET", "/api/dashboard?components=RecentCounts", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestGetDashboardDiscourseTopicsNotMod(t *testing.T) {
	prefix := uniquePrefix("DashDiscNM")
	_, token := CreateFullTestUser(t, prefix)

	// Non-moderator should get nil for DiscourseTopics.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=DiscourseTopics&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	comps := result["components"].(map[string]interface{})
	assert.Nil(t, comps["DiscourseTopics"])
}

func TestGetDashboardV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/dashboard", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestGetDashboardHeatmap(t *testing.T) {
	prefix := uniquePrefix("DashHeat")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?heatmap=true&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "heatmap")
	// Heatmap should be an array (possibly empty).
	_, ok := result["heatmap"].([]interface{})
	assert.True(t, ok, "heatmap should be an array")
}

func TestGetDashboardComponentsArrayStyle(t *testing.T) {
	prefix := uniquePrefix("DashArr")
	_, token := CreateFullTestUser(t, prefix)

	// Test the components[]=X&components[]=Y query style.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components[]=RecentCounts&components[]=PopularPosts&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	assert.Contains(t, comps, "RecentCounts")
	assert.Contains(t, comps, "PopularPosts")
}

func TestGetDashboardParseRelativeDate(t *testing.T) {
	prefix := uniquePrefix("DashPRD")
	_, token := CreateFullTestUser(t, prefix)

	// Test various date formats — URL-encode spaces.
	for _, dateStr := range []string{"today", "7+days+ago", "90+days+ago", "1+year+ago", "2026-01-01"} {
		req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?start=%s&jwt=%s", dateStr, token), nil)
		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode, "Should handle start=%s", dateStr)
	}
}

func TestGetDashboardUnknownComponent(t *testing.T) {
	prefix := uniquePrefix("DashUnk")
	_, token := CreateFullTestUser(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=NonExistent&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	assert.Nil(t, comps["NonExistent"])
}
