package test

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// messages_groups.msgtype is a denormalised copy of messages.type. The ripple,
// move and email paths all fill it in; the two paths a member's own post takes
// did not, so the origin membership of a web or app post carried NULL.
//
// That row is the one the spatial index picks as representative, and browse's
// type filter, the sitemap Google reads and the languishing chase-up all treat
// a NULL type as neither an Offer nor a Wanted. The post went missing from all
// three.

// The compose flow saves a draft first and submits it with JoinAndPost. The
// membership row created at submit must carry the type the draft was written
// with.
func TestJoinAndPostSetsMembershipMsgtype(t *testing.T) {
	prefix := uniquePrefix("msgtype_jap")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) "+
		"VALUES (?, 'Wanted', 'Wanted: Test ladder', 'A ladder please', 'A ladder please', NOW(), NOW(), 'Platform')",
		userID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
	require.NotZero(t, msgID)
	db.Exec("INSERT INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)", msgID, groupID, userID)

	body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "JoinAndPost"})
	req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msgtype *string
	db.Raw("SELECT msgtype FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Row().Scan(&msgtype)
	require.NotNil(t, msgtype, "the membership created at submit must record the type, not leave it NULL")
	assert.Equal(t, "Wanted", *msgtype)
}

// A post submitted straight to a group the member already belongs to skips the
// draft step, and its membership row must carry the type too.
func TestPutMessageSetsMembershipMsgtype(t *testing.T) {
	prefix := uniquePrefix("msgtype_put")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	body, _ := json.Marshal(map[string]interface{}{
		"type":       "Wanted",
		"item":       "Test ladder " + prefix,
		"collection": "Pending",
		"groupid":    groupID,
	})
	req := httptest.NewRequest("PUT", "/api/message?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
	idf, ok := result["id"].(float64)
	require.True(t, ok, "submit must return the new message id")
	msgID := uint64(idf)

	var msgtype *string
	db.Raw("SELECT msgtype FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Row().Scan(&msgtype)
	require.NotNil(t, msgtype, "the membership created at submit must record the type, not leave it NULL")
	assert.Equal(t, "Wanted", *msgtype)
}
