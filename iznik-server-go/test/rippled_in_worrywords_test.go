package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Does a group's OWN worry word fire on a post that merely rippled into it?
//
// Discourse 9829/7 told moderators it does ("if you add it to your per-group worry words
// it'll fire on your group"), and 10102/3 asked the same question again. Two mechanisms
// could answer it and they do different things, so this pins the behaviour rather than
// leaving it to be re-derived by reading:
//
//   - the batch check (ContentCheckService) writes contentcheck_reasons on the row;
//   - the read-time check (checkWorryWords) computes message.worry on every mod fetch.
//
// Only the second one runs here, which is the point: this test asserts what a moderator
// of the rippled-in group actually gets back from the API.
func TestGroupWorryWordFiresOnRippledInCopy(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ripworry")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	origin := CreateTestGroup(t, prefix+"_origin")
	rippled := CreateTestGroup(t, prefix+"_rippled")
	msgID := CreateTestMessage(t, posterID, origin, "OFFER: brown rabbit (Longton ST3)", 51.5, -0.1)

	res := db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", msgID, rippled)
	require.NoError(t, res.Error)

	// Only the RIPPLED-IN group bans animals. The origin group says nothing about them,
	// which is the situation in 10102: Potteries allows them, Walsall does not.
	db.Exec("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), "+
		"'$.spammers', JSON_OBJECT('worrywords', 'rabbit')) WHERE id = ?", rippled)

	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msg map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&msg))

	worry, _ := msg["worry"].([]interface{})
	assert.NotEmpty(t, worry,
		"a moderator of the group the post rippled INTO sees their own group's worry word fire")

	if len(worry) > 0 {
		first, _ := worry[0].(map[string]interface{})
		assert.Equal(t, "rabbit", first["word"], "and is told which word of theirs matched")
	}

	// ...but nothing about the match moves the post: it stays Approved on the rippled-in
	// group, so members there can already see it. The warning is shown, never enforced.
	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, rippled).Scan(&collection)
	assert.Equal(t, "Approved", collection,
		"a worry word match does not hold a rippled-in post for review")
}
