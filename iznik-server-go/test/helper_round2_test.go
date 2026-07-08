package test

import (
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// firstReplierID returns the helper_repliers.id for the batch of a message.
func firstReplierID(t *testing.T, msgid uint64) uint64 {
	var id uint64
	database.DBConn.Raw("SELECT r.id FROM helper_repliers r INNER JOIN helper_batches b ON b.id = r.batchid WHERE b.msgid = ? ORDER BY r.id ASC LIMIT 1", msgid).Scan(&id)
	return id
}

// TestHelperSetStatusAutomode: the three-way control records automode, and an
// invalid automode is rejected.
func TestHelperSetStatusAutomode(t *testing.T) {
	_, _, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpautomode"))
	db := database.DBConn

	code, _ := postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active", "automode": "approve"})
	require.Equal(t, 200, code)
	var automode string
	db.Raw("SELECT automode FROM helper_batches WHERE msgid = ?", msgID).Scan(&automode)
	assert.Equal(t, "approve", automode)

	// GET returns it to the page.
	_, out := getHelper(t, ownerToken, msgID)
	batch := out["batch"].(map[string]interface{})
	assert.Equal(t, "approve", batch["automode"])

	// Back to automatic.
	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active", "automode": "automatic"})
	require.Equal(t, 200, code)
	db.Raw("SELECT automode FROM helper_batches WHERE msgid = ?", msgID).Scan(&automode)
	assert.Equal(t, "automatic", automode)

	// Invalid automode rejected.
	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active", "automode": "bogus"})
	assert.Equal(t, 400, code)
}

// TestHelperApproveModeHoldsSendAsProposal: in Approve mode the Send action does
// not message the replier; it queues an editable 'message' proposal. Resolving it
// sends the offerer's edited text.
func TestHelperApproveModeHoldsSendAsProposal(t *testing.T) {
	_, replierUserID, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpapprove"))
	db := database.DBConn

	code, _ := postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active", "automode": "approve"})
	require.Equal(t, 200, code)

	// Send is held, not sent.
	code, out := postHelper(t, ownerToken, map[string]interface{}{"action": "Send", "msgid": msgID, "userid": replierUserID, "body": "Hi there", "kind": "gathering"})
	require.Equal(t, 200, code)
	assert.Equal(t, "Proposed", out["status"])
	require.NotNil(t, out["proposalid"])
	pid := uint64(out["proposalid"].(float64))

	var ptype, ptext, pstatus string
	db.Raw("SELECT type, COALESCE(proposed_text,''), status FROM helper_proposals WHERE id = ?", pid).Row().Scan(&ptype, &ptext, &pstatus)
	assert.Equal(t, "message", ptype)
	assert.Equal(t, "Hi there", ptext)
	assert.Equal(t, "pending", pstatus)

	// Nothing sent to the replier yet.
	var sent int64
	db.Raw("SELECT COUNT(*) FROM helper_sent_messages s INNER JOIN helper_batches b ON b.id = s.batchid WHERE b.msgid = ?", msgID).Scan(&sent)
	assert.Equal(t, int64(0), sent, "approve mode must not auto-send")

	// Resolve with an edited message → the edited text is what's sent.
	code, out = postHelper(t, ownerToken, map[string]interface{}{"action": "ResolveProposal", "proposalid": pid, "decision": "send", "text": "Hi there, edited"})
	require.Equal(t, 200, code)
	require.NotNil(t, out["chatmsgid"])
	cmid := uint64(out["chatmsgid"].(float64))
	require.NotZero(t, cmid)
	var msg string
	db.Raw("SELECT message FROM chat_messages WHERE id = ?", cmid).Scan(&msg)
	assert.Equal(t, "Hi there, edited", msg)
}

// TestHelperEscalationProposalSetsState: confirming an escalation proposal moves
// the replier to ESCALATED with the AI's reason.
func TestHelperEscalationProposalSetsState(t *testing.T) {
	_, replierUserID, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpescal"))
	db := database.DBConn

	// Create the replier record, then an escalation proposal for it.
	postHelper(t, ownerToken, map[string]interface{}{"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID, "state": "GATHERING"})
	replierID := firstReplierID(t, msgID)
	require.NotZero(t, replierID)

	code, out := postHelper(t, ownerToken, map[string]interface{}{
		"action": "Proposal", "msgid": msgID, "type": "escalation", "replierid": replierID,
		"summary": "Asked a question I can't answer",
	})
	require.Equal(t, 200, code)
	pid := uint64(out["proposalid"].(float64))

	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "ResolveProposal", "proposalid": pid, "decision": "send"})
	require.Equal(t, 200, code)

	var state, reason string
	db.Raw("SELECT state, COALESCE(escalation_reason,'') FROM helper_repliers WHERE id = ?", replierID).Row().Scan(&state, &reason)
	assert.Equal(t, "ESCALATED", state)
	assert.Equal(t, "Asked a question I can't answer", reason)
}

// TestGetHelperEscalatedListsAcrossClearances: the ModTools queue endpoint lists
// ESCALATED repliers to Clearance-permission holders and forbids everyone else.
func TestGetHelperEscalatedListsAcrossClearances(t *testing.T) {
	_, replierUserID, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpescq"))

	postHelper(t, ownerToken, map[string]interface{}{"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID, "state": "ESCALATED", "escalation_reason": "needs a human"})

	// Clearance holder (the owner) sees the escalated row.
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/helper/escalated?jwt="+ownerToken, nil), 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var rows []map[string]interface{}
	json.Unmarshal(rsp(resp), &rows)
	found := false
	for _, r := range rows {
		if uint64(r["msgid"].(float64)) == msgID {
			found = true
			assert.Equal(t, "needs a human", r["escalation_reason"])
		}
	}
	assert.True(t, found, "the escalated replier should appear in the queue")

	// A user without the Clearance permission is forbidden.
	strangerToken := getToken(t, CreateTestUser(t, uniquePrefix("helpescqstranger"), "User"))
	resp, err = getApp().Test(httptest.NewRequest("GET", "/api/helper/escalated?jwt="+strangerToken, nil), 10000)
	require.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}
