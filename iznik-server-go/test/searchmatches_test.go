package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The saved-search matcher holds the EMAIL threshold, not the browsing one.
//
// Same reasoning as TestPostMatchesThresholdExcludesMidBand, and the same band
// matters: a term scoring between MinSimilarScore (0.80) and
// MinMatchedPostScore (0.85) is good enough to show on a strip somebody chose to
// look at, and not good enough to put mail in their inbox. Precision measured on
// hand-judged live posts is 0.92 at 0.85-0.90 and 0.43 at 0.80-0.85.
//
// Without this, dropping the search signal to the strip's threshold stays green.
func TestSearchMatchesHoldTheEmailThreshold(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchmatch")

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	strong := CreateTestUser(t, prefix+"_strong", "User")
	weak := CreateTestUser(t, prefix+"_weak", "User")

	msgID := CreateTestMessage(t, poster, groupID, "OFFER: Pine bookcase", 51.5, -0.1)

	// The post's own vector, served from the in-memory index.
	base := makeTestVec(0.5)
	embedding.Global.SetEntries([]embedding.Entry{{
		Msgid: msgID, Fromuser: poster, Groupid: groupID, Msgtype: "Offer",
		Lat: 51.5, Lng: -0.1, Subject: "Pine bookcase", Arrival: time.Now(), SubjectVec: base,
	}})
	defer embedding.Global.SetEntries(nil)

	// Saved searches have no in-memory index, so these are read from the table -
	// which is also the path the endpoint actually uses.
	// Check the writes. GORM returns errors on the result value rather than
	// panicking, so a missing table would otherwise surface as "no matches" -
	// which is indistinguishable from the threshold doing its job.
	seed := func(user uint64, term string, cos float64) {
		var sid uint64
		require.NoError(t, db.Exec(
			"INSERT INTO users_searches (userid, term, date, deleted) VALUES (?, ?, NOW(), 0)",
			user, term).Error)
		require.NoError(t, db.Raw(
			"SELECT id FROM users_searches WHERE userid = ? ORDER BY id DESC LIMIT 1",
			user).Scan(&sid).Error)
		require.NotZero(t, sid, "saved search did not insert")
		require.NoError(t, db.Exec(
			"INSERT INTO users_searches_embeddings (searchid, term_embedding, model_version) VALUES (?, ?, ?)",
			sid, packSubjectVec(makeVecWithCosine(base, cos)), "test").Error)
		t.Cleanup(func() { db.Exec("DELETE FROM users_searches WHERE id = ?", sid) })
	}

	seed(strong, "pine bookcase", 0.95) // clears the email bar
	seed(weak, "bookend", 0.82)         // clears the strip's bar only
	seed(poster, "bookcase", 0.99)      // their own post

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d/searchmatches", msgID), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var matches []message.SearchMatch
	require.NoError(t, json.Unmarshal(rsp(resp), &matches))

	got := map[uint64]bool{}
	for _, m := range matches {
		got[m.Userid] = true
	}

	assert.True(t, got[strong], "0.95 is well clear of the email threshold")
	assert.False(t, got[weak], "0.82 would show on the similar-posts strip but must not be mailed")
	assert.False(t, got[poster], "never match somebody against their own post")
}

// A post with no embedding matches nothing, rather than falling back to
// something looser.
func TestSearchMatchesWithoutAnEmbeddingReturnNothing(t *testing.T) {
	prefix := uniquePrefix("searchnoembed")
	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, poster, groupID, "OFFER: No vector here", 51.5, -0.1)

	embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d/searchmatches", msgID), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var matches []message.SearchMatch
	require.NoError(t, json.Unmarshal(rsp(resp), &matches))
	assert.Empty(t, matches)
}
