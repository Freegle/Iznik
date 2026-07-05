package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

func TestGetWords(t *testing.T) {
	words := message.GetWords("Old sofa which is green")
	assert.Equal(t, 2, len(words))
	assert.Equal(t, "sofa", words[0])
	assert.Equal(t, "which", words[1])
}

// The keyword-index query functions (GetWordsExact/Typo/Starts/Sounds) and their
// unit tests were removed when the keyword index was retired. Search is now pure
// vector with an in-memory lexical guarantee; see embedding_vectorsearch_test.go
// (TestVectorSearchLexicalGuarantee) and the endpoint tests below.

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
	msgID := CreateTestMessage(t, posterID, groupA, "Offer "+uniqueWord+" widget", 55.9533, -3.1883)

	// Seed the in-memory embedding store so the pure-vector search finds the
	// message via its lexical guarantee (the subject contains uniqueWord). The
	// group filtering under test happens against the store, so this exercises the
	// real authorisation path. Mock the sidecar for the query embedding.
	embedding.ResetQueryCache()
	defer embedding.ResetQueryCache()
	vec := makeTestVec(1.0)
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: msgID, Groupid: groupA, Msgtype: "Offer", Lat: 55.9533, Lng: -3.1883,
			Subject: "Offer " + uniqueWord + " widget", Arrival: time.Now(), SubjectVec: vec},
	})
	defer embedding.Global.SetEntries(nil)
	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

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

func TestSearchByMessageID(t *testing.T) {
	// Reported on Discourse (topic 9585): searching for a message id returned posts
	// whose title merely contained those digits, not the message with that id. A
	// purely-numeric term (with or without a leading "#") must return that message.
	prefix := uniquePrefix("searchbyid")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, "Exercise Bike Lewisham", 55.9533, -3.1883)
	_, token := CreateTestSession(t, userID)

	findsIt := func(term string) bool {
		u := fmt.Sprintf("/api/message/search/%s?groupids=%d&jwt=%s", term, groupID, token)
		resp, _ := getApp().Test(httptest.NewRequest("GET", u, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var results []message.SearchResult
		json2.NewDecoder(resp.Body).Decode(&results)
		for _, r := range results {
			if r.Msgid == msgID {
				return true
			}
		}
		return false
	}

	// "%23" is the URL-encoded "#".
	assert.True(t, findsIt(fmt.Sprintf("%%23%d", msgID)), "#<id> should return that message")
	assert.True(t, findsIt(fmt.Sprintf("%d", msgID)), "a bare numeric id should also return that message")
}
