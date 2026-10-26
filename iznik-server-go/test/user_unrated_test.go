package test

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// GET /user/unrated: the people the member handed an item over with lately and has not rated.

func getUnrated(t *testing.T, token string) []user.Unrated {
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/user/unrated?jwt="+token, nil))
	require.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	require.Equal(t, 200, resp.StatusCode, string(body))

	var out []user.Unrated
	require.NoError(t, json.Unmarshal(body, &out))
	return out
}

func handedOver(t *testing.T, msgid, taker uint64, daysAgo int) {
	db := database.DBConn
	res := db.Exec("INSERT INTO messages_by (msgid, userid, timestamp) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))", msgid, taker, daysAgo)
	require.NoError(t, res.Error)
}

func TestUserUnrated_NotLoggedIn(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/unrated", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestUserUnrated_BothDirectionsAndRated(t *testing.T) {
	prefix := uniquePrefix("unrated")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	me := CreateTestUser(t, prefix+"me", "User")
	taker := CreateTestUser(t, prefix+"taker", "User")
	giver := CreateTestUser(t, prefix+"giver", "User")
	_, token := CreateTestSession(t, me)

	// I offered a sofa and marked it taken by taker.
	mine := CreateTestMessage(t, me, group, fmt.Sprintf("OFFER: Sofa %s (Here)", prefix), 51.5, -0.1)
	handedOver(t, mine, taker, 1)

	// Giver offered a lamp and marked it taken by me.
	theirs := CreateTestMessage(t, giver, group, fmt.Sprintf("OFFER: Lamp %s (Here)", prefix), 51.5, -0.1)
	handedOver(t, theirs, me, 2)
	defer db.Exec("DELETE FROM messages_by WHERE msgid IN (?, ?)", mine, theirs)

	out := getUnrated(t, token)
	require.Len(t, out, 2)

	// Newest first.
	assert.Equal(t, taker, out[0].Userid)
	assert.Equal(t, mine, out[0].Msgid)
	assert.True(t, out[0].Gave, "I gave the sofa")
	assert.Contains(t, out[0].Subject, "Sofa")

	assert.Equal(t, giver, out[1].Userid)
	assert.Equal(t, theirs, out[1].Msgid)
	assert.False(t, out[1].Gave, "I received the lamp")

	// Rating the taker drops them, and so does a rating that was later taken back (rating NULL).
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp) VALUES (?, ?, 'Up', NOW())", me, taker)
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp) VALUES (?, ?, NULL, NOW())", me, giver)
	defer db.Exec("DELETE FROM ratings WHERE rater = ?", me)

	out = getUnrated(t, token)
	assert.Len(t, out, 0)

	// Someone else rating them makes no difference to what I am asked.
	db.Exec("DELETE FROM ratings WHERE rater = ?", me)
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp) VALUES (?, ?, 'Up', NOW())", giver, taker)
	defer db.Exec("DELETE FROM ratings WHERE rater = ?", giver)
	out = getUnrated(t, token)
	assert.Len(t, out, 2)
}

func TestUserUnrated_WantedOldAndDuplicates(t *testing.T) {
	prefix := uniquePrefix("unrated2")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	me := CreateTestUser(t, prefix+"me", "User")
	other := CreateTestUser(t, prefix+"other", "User")
	stale := CreateTestUser(t, prefix+"stale", "User")
	_, token := CreateTestSession(t, me)

	// I asked for a bike and other gave it to me: on a Wanted the poster receives.
	wanted := CreateTestMessage(t, me, group, fmt.Sprintf("WANTED: Bike %s (Here)", prefix), 51.5, -0.1)
	db.Exec("UPDATE messages SET type = 'Wanted' WHERE id = ?", wanted)
	handedOver(t, wanted, other, 3)

	// The same person also took an offer of mine: one entry for them, the most recent handover.
	offer := CreateTestMessage(t, me, group, fmt.Sprintf("OFFER: Chair %s (Here)", prefix), 51.5, -0.1)
	handedOver(t, offer, other, 1)

	// A handover older than the window is not asked about.
	old := CreateTestMessage(t, me, group, fmt.Sprintf("OFFER: Table %s (Here)", prefix), 51.5, -0.1)
	handedOver(t, old, stale, user.UNRATED_DAYS+1)
	defer db.Exec("DELETE FROM messages_by WHERE msgid IN (?, ?, ?)", wanted, offer, old)

	out := getUnrated(t, token)
	require.Len(t, out, 1)
	assert.Equal(t, other, out[0].Userid)
	assert.Equal(t, offer, out[0].Msgid)
	assert.True(t, out[0].Gave)

	// Take the offer away and the Wanted is what is left: I received, so gave is false.
	db.Exec("DELETE FROM messages_by WHERE msgid = ?", offer)
	out = getUnrated(t, token)
	require.Len(t, out, 1)
	assert.Equal(t, wanted, out[0].Msgid)
	assert.False(t, out[0].Gave)
}
