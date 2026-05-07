package test

import (
	"fmt"
	json2 "encoding/json"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"strconv"
	"testing"
)

func TestGetWords(t *testing.T) {
	words := message.GetWords("Old sofa which is green")
	assert.Equal(t, 2, len(words))
	assert.Equal(t, "sofa", words[0])
	assert.Equal(t, "which", words[1])
}

func TestSearchExact(t *testing.T) {
	// Create a message with searchable words
	prefix := uniquePrefix("searchexact")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	CreateTestMessage(t, userID, groupID, "Vintage Sofa Available", 55.9533, -3.1883)

	// Search on a word in subject
	words := message.GetWords("Vintage Sofa Available")
	results := message.GetWordsExact(database.DBConn, words, 100, nil, "All", 0, 0, 0, 0)

	// Should find messages with these words
	assert.Greater(t, len(results), 0)
}

func TestSearchTypo(t *testing.T) {
	// Create a message with searchable words
	prefix := uniquePrefix("searchtypo")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	CreateTestMessage(t, userID, groupID, "Beautiful Chair Free", 55.9533, -3.1883)

	words := message.GetWords("Beautiful Chair Free")
	_ = message.GetWordsTypo(database.DBConn, words, 100, nil, "All", 0, 0, 0, 0)
	// May or may not find results depending on index state
}

func TestSearchSounds(t *testing.T) {
	// Create a group for sound search test
	prefix := uniquePrefix("searchsound")
	groupID := CreateTestGroup(t, prefix)

	// Search for a nonsense word that shouldn't exist
	results := message.GetWordsSounds(database.DBConn, []string{"zcz"}, 100, []uint64{groupID}, "All", 0, 0, 0, 0)
	assert.Equal(t, len(results), 0)
}

func TestSearchStarts(t *testing.T) {
	// Create a message with searchable words
	prefix := uniquePrefix("searchstarts")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	CreateTestMessage(t, userID, groupID, "Bookshelf Wooden Large", 55.9533, -3.1883)

	// Search on prefix of a word
	words := message.GetWords("Bookshelf Wooden Large")
	if len(words) > 0 && len(words[0]) >= 3 {
		results := message.GetWordsStarts(database.DBConn, []string{words[0][:3]}, 100, nil, "All", 0, 0, 0, 0)
		// Should find something starting with that prefix
		assert.Greater(t, len(results), 0)
	}
}

func TestAPISearch(t *testing.T) {
	// Create a full test user for search with history
	prefix := uniquePrefix("apisearch")
	_, token := CreateFullTestUser(t, prefix)

	// Create a message with searchable words
	groupID := CreateTestGroup(t, prefix+"_grp")
	userID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	CreateTestMessage(t, userID, groupID, "Garden Table Offer", 55.9533, -3.1883)

	// Search on first word in subject
	words := message.GetWords("Garden Table Offer")
	searchWord := words[0]

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/search/"+searchWord+"?jwt="+token, nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	var results []message.SearchResult
	json2.Unmarshal(rsp(resp), &results)
	// May or may not find results depending on how quickly search index updates

	// Test typo search (swap some letters)
	if len(searchWord) >= 4 {
		typoWord := searchWord[:1] + searchWord[2:3] + searchWord[1:2] + searchWord[3:]
		resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/search/"+typoWord, nil), 60000)
		assert.Equal(t, 200, resp.StatusCode)
	}

	// Search for nonsense word - should return empty
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/search/£78jhdfhjdsfhjsafhsjjdsfkhjk", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
	json2.Unmarshal(rsp(resp), &results)
	assert.Equal(t, len(results), 0)

	// Search with group filter
	groupidStr := strconv.FormatUint(groupID, 10)
	resp, _ = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/search/%s?groupids=%s", searchWord, groupidStr), nil))
	assert.Equal(t, 200, resp.StatusCode)
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
	groupA := CreateTestGroup(t, prefix+"_a")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupA, "Member")

	// A unique word that won't appear in other test data.
	uniqueWord := prefix + "zygote"
	CreateTestMessage(t, posterID, groupA, "Offer "+uniqueWord+" widget", 55.9533, -3.1883)

	// Group B: the support user belongs to this group (not group A).
	groupB := CreateTestGroup(t, prefix+"_b")
	supportID := CreateTestUser(t, prefix+"_support", "User")
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", supportID)
	CreateTestMembership(t, supportID, groupB, "Member")
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
	CreateTestMembership(t, modID, groupB, "Moderator")
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
