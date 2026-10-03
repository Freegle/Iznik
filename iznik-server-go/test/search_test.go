package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

func TestGetWords(t *testing.T) {
	words := message.GetWords("Old sofa which is green")
	assert.Equal(t, 2, len(words))
	assert.Equal(t, "sofa", words[0])
	assert.Equal(t, "which", words[1])
}

func TestAPISearch_WithoutAuth(t *testing.T) {
	// Search without auth should still work (just won't record search history)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/search/table", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestAPISearch_WithMessageType(t *testing.T) {
	// Search with messagetype filter
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/search/sofa?messagetype=Offer", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/search/sofa?messagetype=Wanted", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestAPISearch_V2Path(t *testing.T) {
	// Verify v2 path works
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/message/search/chair", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}

// TestAPISearch_SupportUserSearchesAllGroups verifies that a support/admin user
// searching with groupids=0 ("All communities") sees messages from groups they
// are NOT a member of. Regular users should still be restricted to their groups.
func TestAPISearch_SupportUserSearchesAllGroups(t *testing.T) {
	prefix := uniquePrefix("srch_support")
	db := database.DBConn

	// Group A: the message lives here; the support user is NOT a member.
	posterID := CreateTestUser(t, prefix+"_poster", "User")

	// A unique word that won't appear in other test data.
	uniqueWord := prefix + "zygote"
	CreateTestMessage(t, posterID, "Offer "+uniqueWord+" widget", 55.9533, -3.1883)

	// Group B: the support user belongs to this group (not group A).
	supportID := CreateTestUser(t, prefix+"_support", "User")
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", supportID)
	_, supportToken := CreateTestSession(t, supportID)

	// Support user searches with groupids=0 (All communities).
	url := fmt.Sprintf("/api/message/search/%s?groupids=0&jwt=%s", uniqueWord, supportToken)
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	var results []message.SearchResult
	json2.Unmarshal(rsp(resp), &results)
	found := false
	for _, r := range results {
		if r.Msgid != 0 {
			found = true
		}
	}
	assert.True(t, found, "support user should find messages from groups they are not a member of")

	// Regular mod in group B searching with groupids=0 should NOT see group A messages.
	modID := CreateTestUser(t, prefix+"_mod", "User")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	url = fmt.Sprintf("/api/message/search/%s?groupids=0&jwt=%s", uniqueWord, modToken)
	resp, _ = getApp().Test(httptest.NewRequest("GET", url, nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &results)
	foundAsMod := false
	for _, r := range results {
		if r.Msgid != 0 {
			foundAsMod = true
		}
	}
	assert.False(t, foundAsMod, "regular mod should NOT see messages from groups they are not a member of")
}
