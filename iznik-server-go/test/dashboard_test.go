package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
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

func TestGetDashboardDiscourseTopicsNoConfig(t *testing.T) {
	// A moderator gets nil when DISCOURSE_API/DISCOURSE_APIKEY are not set.
	prefix := uniquePrefix("DashDiscNC")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Moderator")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=DiscourseTopics&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	comps := result["components"].(map[string]interface{})
	// Without DISCOURSE_API env var, should return nil.
	assert.Nil(t, comps["DiscourseTopics"])
}

func TestDashboardNewMessagesNoDoubleCount(t *testing.T) {
	// A message on two groups should be counted once, not twice.
	prefix := uniquePrefix("DashNoDup")
	groupA := CreateTestGroup(t, prefix+"A")
	groupB := CreateTestGroup(t, prefix+"B")
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupA, "Moderator")
	CreateTestMembership(t, userID, groupB, "Moderator")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn

	// Create a message on groupA using the helper.
	msgID := CreateTestMessage(t, userID, groupA, prefix+" Test", 55.9533, -3.1883)

	// Add the same message to groupB (multi-group).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, autoreposts) "+
		"VALUES (?, ?, 'Approved', NOW(), 0)", msgID, groupB)

	// Legacy dashboard with allgroups should count this message once.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?allgroups=true&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	dash := result["dashboard"].(map[string]interface{})
	assert.Equal(t, float64(1), dash["newmessages"], "Multi-group message should be counted once, not twice")

	// RecentCounts component should also count once.
	req2 := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=RecentCounts&allgroups=true&jwt=%s", token), nil)
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, 200, resp2.StatusCode)

	var result2 map[string]interface{}
	json2.Unmarshal(rsp(resp2), &result2)
	comps := result2["components"].(map[string]interface{})
	rc := comps["RecentCounts"].(map[string]interface{})
	assert.Equal(t, float64(1), rc["newmessages"], "RecentCounts should not double-count multi-group messages")
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

// createModDashboardFixtures creates a moderator with a group, message,
// membership and chat data suitable for testing dashboard components.
func createModDashboardFixtures(t *testing.T, prefix string) (uint64, uint64, string) {
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, userID, groupID, "Moderator")
	_, token := CreateTestSession(t, userID)

	// Create a message in the group so components have data.
	msgID := CreateTestMessage(t, userID, groupID, prefix+" OFFER: test item", 52.5, -1.8)

	// Approve the message so it shows in approved queries.
	db.Exec("UPDATE messages_groups SET collection = 'Approved', approvedby = ?, approvedat = NOW() WHERE msgid = ? AND groupid = ?",
		userID, msgID, groupID)

	// Create a chat reply referencing the message.
	poster := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	var chatID uint64
	db.Exec("INSERT INTO chat_rooms (user1, user2, chattype) VALUES (?, ?, 'User2User')", userID, poster)
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&chatID)
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date) VALUES (?, ?, 'interested', 'Interested', ?, NOW())",
		chatID, poster, msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
		db.Exec("DELETE FROM chat_rooms WHERE id = ?", chatID)
	})

	return groupID, userID, token
}

func TestGetDashboardUsersPosting(t *testing.T) {
	prefix := uniquePrefix("DashUP")
	groupID, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=UsersPosting&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	up := comps["UsersPosting"]
	assert.NotNil(t, up, "UsersPosting should not be nil for moderator")

	users, ok := up.([]interface{})
	assert.True(t, ok, "UsersPosting should be an array")
	assert.Greater(t, len(users), 0, "Should have at least one posting user")
}

func TestGetDashboardUsersReplying(t *testing.T) {
	prefix := uniquePrefix("DashUR")
	groupID, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=UsersReplying&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	ur := comps["UsersReplying"]
	assert.NotNil(t, ur, "UsersReplying should not be nil for moderator")

	users, ok := ur.([]interface{})
	assert.True(t, ok, "UsersReplying should be an array")
	assert.Greater(t, len(users), 0, "Should have at least one replying user")
}

func TestGetDashboardModeratorsActive(t *testing.T) {
	prefix := uniquePrefix("DashMA")
	groupID, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=ModeratorsActive&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	ma := comps["ModeratorsActive"]
	assert.NotNil(t, ma, "ModeratorsActive should not be nil for moderator")

	mods, ok := ma.([]interface{})
	assert.True(t, ok, "ModeratorsActive should be an array")
	// The moderator approved a message, so should appear.
	assert.Greater(t, len(mods), 0, "Should have at least one active moderator")
}

func TestGetDashboardMessageBreakdown(t *testing.T) {
	prefix := uniquePrefix("DashMB")
	db := database.DBConn
	groupID, _, token := createModDashboardFixtures(t, prefix)

	// Insert a stats row for MessageBreakdown.
	today := time.Now().Format("2006-01-02")
	db.Exec("INSERT INTO stats (type, groupid, date, end, count, breakdown) VALUES ('MessageBreakdown', ?, ?, ?, 1, ?)",
		groupID, today, today, `{"Offer":3,"Wanted":1}`)
	t.Cleanup(func() {
		db.Exec("DELETE FROM stats WHERE type = 'MessageBreakdown' AND groupid = ?", groupID)
	})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=MessageBreakdown&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	mb := comps["MessageBreakdown"].(map[string]interface{})
	assert.Equal(t, float64(3), mb["Offer"])
	assert.Equal(t, float64(1), mb["Wanted"])
}

func TestGetDashboardDonations(t *testing.T) {
	prefix := uniquePrefix("DashDon")
	db := database.DBConn
	groupID, userID, token := createModDashboardFixtures(t, prefix)

	// Insert a test donation.
	db.Exec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, GrossAmount, timestamp, TransactionID) VALUES (?, ?, ?, 5.00, NOW(), ?)",
		userID, prefix+"@test.com", prefix+" Donor", prefix+"_txn")
	t.Cleanup(func() {
		db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", prefix+"_txn")
	})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=Donations&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	don, ok := comps["Donations"].([]interface{})
	assert.True(t, ok, "Donations should be an array")
	assert.Greater(t, len(don), 0, "Should have at least one donation row")
}

func TestGetDashboardDonationsSystemwide(t *testing.T) {
	prefix := uniquePrefix("DashDonSW")
	db := database.DBConn
	_, userID, token := createModDashboardFixtures(t, prefix)

	// Insert a donation for systemwide query using the test user (FK constraint).
	db.Exec("INSERT INTO users_donations (userid, Payer, PayerDisplayName, GrossAmount, timestamp, TransactionID) VALUES (?, ?, ?, 10.00, NOW(), ?)",
		userID, prefix+"@test.com", prefix+" Donor", prefix+"_txn_sw")
	t.Cleanup(func() {
		db.Exec("DELETE FROM users_donations WHERE TransactionID = ?", prefix+"_txn_sw")
	})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=Donations&systemwide=true&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	don, ok := comps["Donations"].([]interface{})
	assert.True(t, ok, "Donations should be an array")
	assert.Greater(t, len(don), 0, "Should have at least one donation row systemwide")
}

func TestGetDashboardHappiness(t *testing.T) {
	prefix := uniquePrefix("DashHappy")
	db := database.DBConn
	groupID, _, token := createModDashboardFixtures(t, prefix)

	// Get the message ID from the fixture.
	var msgID uint64
	db.Raw("SELECT msgid FROM messages_groups WHERE groupid = ? LIMIT 1", groupID).Scan(&msgID)

	// Insert a happiness outcome.
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, timestamp) VALUES (?, 'Taken', 'Happy', NOW())", msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)
	})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=Happiness&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	happy, ok := comps["Happiness"].([]interface{})
	assert.True(t, ok, "Happiness should be an array")
	assert.Greater(t, len(happy), 0, "Should have at least one happiness entry")
}

func TestGetDashboardPopularPostsWithGroup(t *testing.T) {
	prefix := uniquePrefix("DashPP")
	groupID, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=PopularPosts&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	pp, ok := comps["PopularPosts"].([]interface{})
	assert.True(t, ok, "PopularPosts should be an array")
	// Should have the test message.
	assert.Greater(t, len(pp), 0, "Should have at least one popular post")
}

func TestGetDashboardPopularPostsSystemwide(t *testing.T) {
	prefix := uniquePrefix("DashPPSW")
	_, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=PopularPosts&systemwide=true&jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})
	_, ok := comps["PopularPosts"].([]interface{})
	assert.True(t, ok, "PopularPosts should be an array")
}

func TestGetDashboardMultipleTimeSeriesComponents(t *testing.T) {
	prefix := uniquePrefix("DashMTS")
	db := database.DBConn
	groupID, _, token := createModDashboardFixtures(t, prefix)

	// Insert stats rows for several time series types.
	today := time.Now().Format("2006-01-02")
	for _, stype := range []string{"Activity", "Replies", "Weight", "Outcomes"} {
		db.Exec("INSERT INTO stats (type, groupid, date, end, count) VALUES (?, ?, ?, ?, 5)",
			stype, groupID, today, today)
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM stats WHERE groupid = ?", groupID)
	})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?components=Activity,Replies,Weight,Outcomes&group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	comps := result["components"].(map[string]interface{})

	for _, comp := range []string{"Activity", "Replies", "Weight", "Outcomes"} {
		arr, ok := comps[comp].([]interface{})
		assert.True(t, ok, comp+" should be an array")
		assert.Greater(t, len(arr), 0, comp+" should have at least one entry")
		entry := arr[0].(map[string]interface{})
		assert.Contains(t, entry["date"], today, comp+" date should contain today")
		assert.Equal(t, float64(5), entry["count"])
	}
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

func TestGetDashboardLegacyWithGroup(t *testing.T) {
	prefix := uniquePrefix("DashLG")
	groupID, _, token := createModDashboardFixtures(t, prefix)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/dashboard?group=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	dash := result["dashboard"].(map[string]interface{})
	// Should have counts (may be 0 or more).
	assert.Contains(t, dash, "newmembers")
	assert.Contains(t, dash, "newmessages")
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
