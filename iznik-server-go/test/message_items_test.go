package test

import (
	"encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A close cosine is not enough for a matched-posts email when the two posts are about
// different items: "Beer dispenser" is not a "Water dispenser". Unknown items match on cosine.
func TestPostMatchesSkipsADifferentItem(t *testing.T) {
	base := makeTestVec(0.5)
	near := makeVecWithCosine(base, 0.95)
	const srcID, sameID, otherID, unknownID = 853001, 853002, 853003, 853004
	const u1, u2 = uint64(95001), uint64(95002)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: srcID, Fromuser: u1, Groupid: 100, Msgtype: "Wanted", Lat: 51.5, Lng: -0.1, Subject: "Beer dispenser", Arrival: time.Now(), SubjectVec: base, ItemKey: "beer dispenser"},
		{Msgid: sameID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Home beer tap dispenser", Arrival: time.Now(), SubjectVec: near, ItemKey: "beer dispenser"},
		{Msgid: otherID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Water dispenser", Arrival: time.Now(), SubjectVec: near, ItemKey: "water dispenser"},
		{Msgid: unknownID, Fromuser: u2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "Dispenser", Arrival: time.Now(), SubjectVec: near},
	})
	defer embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/853001/matches", nil), 60000)
	require.Equal(t, 200, resp.StatusCode)
	var results []message.SimilarResult
	json.NewDecoder(resp.Body).Decode(&results)

	got := map[uint64]bool{}
	for _, r := range results {
		got[r.Msgid] = true
	}
	assert.True(t, got[uint64(sameID)], "the same item is emailed")
	assert.False(t, got[uint64(otherID)], "a different item is not, however close the wording")
	assert.True(t, got[uint64(unknownID)], "a post whose item is not known yet matches on cosine")
}

// A post about exactly what was searched for ranks first, and is found even when its
// embedding is nowhere near the search term.
func TestVectorSearchPutsTheSameItemFirstAndFindsItAnyway(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	query := makeTestVec(0.5)
	const relatedID, sameID, farID = 854001, 854002, 854003
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: relatedID, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Zimmer frame wheels", Arrival: time.Now(), SubjectVec: makeVecWithCosine(query, 0.95), ItemKey: "zimmer frame wheel"},
		{Msgid: sameID, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Aluminium walker", Arrival: time.Now(), SubjectVec: makeVecWithCosine(query, 0.80), ItemKey: "walking frame"},
		{Msgid: farID, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Rollator", Arrival: time.Now(), SubjectVec: makeAntiparallelVec(0.5), ItemKey: "walking frame"},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, query[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, _, err := message.VectorSearch("Walking frames", 10, nil, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)

	order := make([]uint64, 0, len(results))
	for _, r := range results {
		order = append(order, r.Msgid)
	}
	require.Len(t, order, 3)
	assert.ElementsMatch(t, []uint64{sameID, farID}, order[:2], "the two walking frames come first")
	assert.Equal(t, uint64(relatedID), order[2])
}

// The store picks up a post's item from messages_cleaned_items, including one written after the post
// was already in the store.
func TestStoreLoadsItemKeys(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("msgitems")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	msgID := CreateTestMessage(t, userID, groupID, "Aluminium walking frame "+prefix, 51.5, -0.1)
	t.Cleanup(func() { db.Exec("DELETE FROM messages_cleaned_items WHERE msgid = ?", msgID) })

	db.Exec("INSERT INTO messages_cleaned_items (msgid, item, itemkey) VALUES (?, 'walking frame', 'walking frame')", msgID)
	got := embedding.Global.ItemKeysFor([]uint64{msgID})
	assert.Equal(t, "walking frame", got[msgID])

	db.Exec("INSERT INTO messages_cleaned_items (msgid, item, itemkey) VALUES (?, 'none', NULL) ON DUPLICATE KEY UPDATE itemkey = NULL", msgID)
	assert.Empty(t, embedding.Global.ItemKeysFor([]uint64{msgID})[msgID], "a post with no item has no key")
}
