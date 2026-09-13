package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Reposting a Taken or Received post clears its outcome and makes it mailable again, but its
// reach row survives untouched (Taken/Received posts stay in messages_spatial, so the reach
// is never dropped and rebuilt). Nothing else signals the reach pass, which now resumes from a
// mark on rippling_reach.updated_at. JoinAndPostAs therefore bumps updated_at when a reach row
// exists, so the pass re-evaluates the post; the ledger keeps everyone mailed in its first
// life from being mailed twice.
func TestRepostBumpsTheReachRowSoThePassSeesIt(t *testing.T) {
	prefix := uniquePrefix("repost_reach")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Test chair', 'A nice chair', 'A nice chair', NOW(), NOW(), 'Platform')", userID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
	require.NotZero(t, msgID)
	db.Exec("INSERT INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)", msgID, groupID, userID)

	// The post's first life: reach done, taken, reach row left in place with an old updated_at.
	insertReach(msgID, 3, 3)
	db.Exec("UPDATE rippling_reach SET status = 'done', updated_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE msgid = ?", msgID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "JoinAndPost"})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var outcomes int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&outcomes)
	require.Equal(t, int64(0), outcomes, "precondition: the repost cleared the outcome")

	var ageSecs int64
	db.Raw("SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) FROM rippling_reach WHERE msgid = ?", msgID).Scan(&ageSecs)
	assert.Less(t, ageSecs, int64(120), "the repost must bump the reach row so the reach pass re-evaluates the post")
}

// A repost of a post whose reach row was dropped (Withdrawn leaves the index, and the retract
// path removes the row) must not create one: the bump is a no-op without a row, and the repost
// re-enters reach through initialiseNew like a first approval.
func TestRepostWithoutAReachRowCreatesNone(t *testing.T) {
	prefix := uniquePrefix("repost_noreach")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Test lamp', 'A lamp', 'A lamp', NOW(), NOW(), 'Platform')", userID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
	require.NotZero(t, msgID)
	db.Exec("INSERT INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)", msgID, groupID, userID)

	body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "JoinAndPost"})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var rows int64
	db.Raw("SELECT COUNT(*) FROM rippling_reach WHERE msgid = ?", msgID).Scan(&rows)
	assert.Equal(t, int64(0), rows, "no reach row is invented by the repost")
}
