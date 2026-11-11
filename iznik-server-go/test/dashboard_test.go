package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
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

// createPopularPostsViewer is a national moderator with a session, the poster of the posts the
// Popular Posts tests count.
func createPopularPostsViewer(t *testing.T, prefix string) (uint64, string) {
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)
	return modID, token
}

// popularPostsSystemwide asks for the network-wide Popular Posts from startDaysAgo to today. Each
// test passes a different start so it is not served another test's cached answer.
func popularPostsSystemwide(t *testing.T, token string, startDaysAgo int) map[uint64]int {
	start := time.Now().AddDate(0, 0, -startDaysAgo).Format("2006-01-02")
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=PopularPosts&systemwide=true&start=%s&end=today&jwt=%s", start, token), nil)
	resp, _ := getApp().Test(req, 60000)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	pp, ok := result["components"].(map[string]interface{})["PopularPosts"].([]interface{})
	assert.True(t, ok, "PopularPosts should be an array")

	views := map[uint64]int{}
	for _, row := range pp {
		post := row.(map[string]interface{})
		views[uint64(post["id"].(float64))] = int(post["views"].(float64))
	}
	return views
}

func storeViewCount(t *testing.T, msgID uint64, arrivalDaysAgo int, views int, countedDaysAgo int) {
	db := database.DBConn
	db.Table("messages_viewcounts").Create(map[string]interface{}{
		"msgid":      msgID,
		"arrival":    time.Now().AddDate(0, 0, -arrivalDaysAgo),
		"views":      views,
		"counted_at": time.Now().AddDate(0, 0, -countedDaysAgo),
	})
	t.Cleanup(func() { db.Table("messages_viewcounts").Where("msgid = ?", msgID).Delete(nil) })
}

func setArrival(msgID uint64, daysAgo int) {
	database.DBConn.Table("messages").Where("id = ?", msgID).Update("arrival", time.Now().AddDate(0, 0, -daysAgo))
}

// The network-wide list reads the counts messages:count-views stored, rather than counting
// tens of millions of messages_likes rows live (Sentry 7821578277), and drops a stored post that
// has since been deleted.
func TestGetDashboardPopularPostsSystemwideReadsStoredCounts(t *testing.T) {
	prefix := uniquePrefix("DashPPStored")
	modID, token := createPopularPostsViewer(t, prefix)

	stored := CreateTestMessage(t, modID, prefix+" OFFER: counted overnight", 52.5, -1.8)
	setArrival(stored, 5)
	storeViewCount(t, stored, 5, 2000000000, 0)

	deleted := CreateTestMessage(t, modID, prefix+" OFFER: deleted since", 52.5, -1.8)
	setArrival(deleted, 5)
	storeViewCount(t, deleted, 5, 2100000000, 0)
	database.DBConn.Table("messages").Where("id = ?", deleted).Update("deleted", time.Now())

	views := popularPostsSystemwide(t, token, 20)
	assert.Equal(t, 2000000000, views[stored], "the stored count is used for a post counted overnight")
	assert.NotContains(t, views, deleted, "a post deleted since it was counted is not listed")
}

// Posts that arrived on or after the day the batch last ran are counted live, so a stored count
// for one of them is not used.
func TestGetDashboardPopularPostsSystemwideCountsSinceTheLastRunLive(t *testing.T) {
	prefix := uniquePrefix("DashPPTail")
	modID, token := createPopularPostsViewer(t, prefix)
	db := database.DBConn

	// The batch last ran two days ago and covered up to then.
	older := CreateTestMessage(t, modID, prefix+" OFFER: older", 52.5, -1.8)
	setArrival(older, 6)
	storeViewCount(t, older, 6, 1, 2)

	// A post from yesterday with three real views and a stale stored count that must be ignored.
	recent := CreateTestMessage(t, modID, prefix+" OFFER: since the run", 52.5, -1.8)
	setArrival(recent, 1)
	storeViewCount(t, recent, 1, 2000000000, 2)
	for i := 0; i < 3; i++ {
		viewer := CreateTestUser(t, fmt.Sprintf("%s_v%d", prefix, i), utils.SYSTEMROLE_USER)
		db.Table("messages_likes").Create(map[string]interface{}{"msgid": recent, "userid": viewer, "type": "View"})
	}
	t.Cleanup(func() { db.Table("messages_likes").Where("msgid = ?", recent).Delete(nil) })

	views := popularPostsSystemwide(t, token, 21)
	assert.Equal(t, 3, views[recent], "a post since the last run is counted live, not read from the stored row")
}

// The active volunteers list is one row per moderator, most recent first, and leaves out a
// moderator who approved nothing. A moderator's time is the arrival of the latest post they
// approved, however many they approved.
func TestGetDashboardModeratorsActiveOnePerModeratorMostRecentFirst(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("DashMAOrder")

	viewer := CreateTestUser(t, prefix+"_viewer", utils.SYSTEMROLE_MODERATOR)
	_, token := CreateTestSession(t, viewer)

	// Approved two posts: ten days ago and an hour ago.
	twoPosts := CreateTestUser(t, prefix+"_two", utils.SYSTEMROLE_MODERATOR)
	// Approved one post, five days ago.
	onePost := CreateTestUser(t, prefix+"_one", utils.SYSTEMROLE_MODERATOR)

	approve := func(mod uint64, hoursAgo int, subject string) {
		msg := CreateTestMessage(t, mod, subject, 52.5, -1.8)
		db.Exec("UPDATE messages SET collection = 'Approved', approvedby = ?, approvedat = NOW(), arrival = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?",
			mod, hoursAgo, msg)
	}
	approve(twoPosts, 10*24, prefix+" OFFER: ten days ago")
	approve(twoPosts, 1, prefix+" OFFER: an hour ago")
	approve(onePost, 5*24, prefix+" OFFER: five days ago")

	// A start no other test uses, so the answer is not one another test cached.
	start := time.Now().AddDate(0, 0, -97).Format("2006-01-02")
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=ModeratorsActive&start=%s&jwt=%s", start, token), nil)
	resp, _ := getApp().Test(req, 60000)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	mods, ok := result["components"].(map[string]interface{})["ModeratorsActive"].([]interface{})
	assert.True(t, ok, "ModeratorsActive should be an array")

	// Moderators are a national pool and the test database is shared, so look only at this
	// test's three.
	ids := []uint64{}
	lastactive := map[uint64]string{}
	for _, m := range mods {
		row := m.(map[string]interface{})
		id := uint64(row["id"].(float64))
		if id == viewer || id == twoPosts || id == onePost {
			ids = append(ids, id)
			lastactive[id] = row["lastactive"].(string)
		}
	}
	assert.Equal(t, []uint64{twoPosts, onePost}, ids, "one row per moderator, most recent first, nobody who approved nothing")
	assert.Greater(t, lastactive[twoPosts], lastactive[onePost], "the time shown is the most recent approval")
}
