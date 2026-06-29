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

// postHelper posts a Helper action as the given user and returns the decoded body.
func postHelper(t *testing.T, token string, payload map[string]interface{}) (int, map[string]interface{}) {
	b, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/helper?jwt="+token, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	var out map[string]interface{}
	json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func getHelper(t *testing.T, token string, msgid uint64) (int, map[string]interface{}) {
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/helper/%d?jwt=%s", msgid, token), nil), 10000)
	require.NoError(t, err)
	var out map[string]interface{}
	json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

// helperFixture creates an owner, a clearance message with one item, and a replier
// who has expressed interest. Returns ids + the owner token.
func helperFixture(t *testing.T, prefix string) (ownerID, replierID, msgID, itemID uint64, ownerToken string) {
	groupID := CreateTestGroup(t, prefix)
	ownerID = CreateTestUser(t, prefix+"_owner", "User")
	replierID = CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	msgID = CreateTestMessage(t, ownerID, groupID, prefix+" Office Clearance", 55.95, -3.18)
	itemID = addBulkItem(t, msgID, "Office desk", 4, "Good")
	db := database.DBConn
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 2, 'Interested')", itemID, msgID, replierID)
	ownerToken = getToken(t, ownerID)
	return
}

// TestHelperEnsureBatchAndGet: EnsureBatch creates a batch; GET returns it; a
// stranger is forbidden.
func TestHelperEnsureBatchAndGet(t *testing.T) {
	ownerID, _, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpbatch"))
	_ = ownerID

	// No batch yet → GET returns nil batch.
	code, out := getHelper(t, ownerToken, msgID)
	require.Equal(t, 200, code)
	assert.Nil(t, out["batch"])

	code, out = postHelper(t, ownerToken, map[string]interface{}{"action": "EnsureBatch", "msgid": msgID})
	require.Equal(t, 200, code)
	require.NotNil(t, out["batchid"])

	code, out = getHelper(t, ownerToken, msgID)
	require.Equal(t, 200, code)
	require.NotNil(t, out["batch"])
	batch := out["batch"].(map[string]interface{})
	assert.Equal(t, "active", batch["status"])
	assert.Equal(t, float64(msgID), batch["msgid"])

	// A stranger cannot read or write.
	strangerID := CreateTestUser(t, uniquePrefix("helpstranger"), "User")
	strangerToken := getToken(t, strangerID)
	code, _ = getHelper(t, strangerToken, msgID)
	assert.Equal(t, 403, code)
	code, _ = postHelper(t, strangerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "paused"})
	assert.Equal(t, 403, code)
}

// TestHelperSetStatus: pause sets status+pausedat; resume clears it.
func TestHelperSetStatus(t *testing.T) {
	_, _, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpstatus"))
	db := database.DBConn

	code, _ := postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "paused"})
	require.Equal(t, 200, code)
	var status string
	var pausedNull int
	db.Raw("SELECT status, pausedat IS NULL FROM helper_batches WHERE msgid = ?", msgID).Row().Scan(&status, &pausedNull)
	assert.Equal(t, "paused", status)
	assert.Equal(t, 0, pausedNull, "pausedat should be set when paused")

	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active"})
	require.Equal(t, 200, code)
	db.Raw("SELECT status, pausedat IS NULL FROM helper_batches WHERE msgid = ?", msgID).Row().Scan(&status, &pausedNull)
	assert.Equal(t, "active", status)
	assert.Equal(t, 1, pausedNull, "pausedat should be cleared when resumed")

	// Invalid status rejected.
	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "bogus"})
	assert.Equal(t, 400, code)
}

// TestHelperUpsertReplierAndItemState: knowledge record + per-item score round-trips
// through GET, and partial updates don't clobber prior fields.
func TestHelperUpsertReplierAndItemState(t *testing.T) {
	_, replierUserID, msgID, itemID, ownerToken := helperFixture(t, uniquePrefix("helpknow"))

	code, out := postHelper(t, ownerToken, map[string]interface{}{
		"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID,
		"state": "GATHERING", "collection_ok": "yes", "distance_miles": 3.5, "is_connector": true,
	})
	require.Equal(t, 200, code)
	replierID := uint64(out["replierid"].(float64))
	require.NotZero(t, replierID)

	// Partial update: only criteria_met — must not reset state/collection_ok.
	code, _ = postHelper(t, ownerToken, map[string]interface{}{
		"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID, "criteria_met": "yes",
	})
	require.Equal(t, 200, code)

	code, _ = postHelper(t, ownerToken, map[string]interface{}{
		"action": "SetItemState", "replierid": replierID, "bulkitemid": itemID,
		"state": "QUALIFIED", "qty_wanted": 2, "score": 87.5,
	})
	require.Equal(t, 200, code)

	code, out = getHelper(t, ownerToken, msgID)
	require.Equal(t, 200, code)
	repliers := out["repliers"].([]interface{})
	require.Len(t, repliers, 1)
	r := repliers[0].(map[string]interface{})
	assert.Equal(t, "GATHERING", r["state"])
	assert.Equal(t, "yes", r["collection_ok"])
	assert.Equal(t, "yes", r["criteria_met"])
	assert.Equal(t, true, r["is_connector"])
	assert.Equal(t, 3.5, r["distance_miles"])
	items := r["item_states"].([]interface{})
	require.Len(t, items, 1)
	it := items[0].(map[string]interface{})
	assert.Equal(t, "QUALIFIED", it["state"])
	assert.Equal(t, float64(87.5), it["score"])
}

// TestHelperSendRecordsSentMessage: Send auto-sends a chat message and records it
// with auto=true so the page can badge it.
func TestHelperSendRecordsSentMessage(t *testing.T) {
	ownerID, replierUserID, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helpsend"))
	db := database.DBConn

	code, out := postHelper(t, ownerToken, map[string]interface{}{
		"action": "Send", "msgid": msgID, "userid": replierUserID,
		"body": "Thanks for your interest! When could you collect?", "kind": "gathering",
	})
	require.Equal(t, 200, code)
	chatmsgid := uint64(out["chatmsgid"].(float64))
	require.NotZero(t, chatmsgid)

	// The chat message exists, sent by the offerer.
	var fromUser uint64
	var body string
	db.Raw("SELECT userid, message FROM chat_messages WHERE id = ?", chatmsgid).Row().Scan(&fromUser, &body)
	assert.Equal(t, ownerID, fromUser)
	assert.Contains(t, body, "When could you collect")
	// The FIRST auto-sent message carries the automated-assistant disclosure.
	assert.Contains(t, body, "automated assistant")

	// It's recorded as a Helper-sent message (auto).
	var auto int
	var kind string
	db.Raw("SELECT auto, kind FROM helper_sent_messages WHERE chatmsgid = ?", chatmsgid).Row().Scan(&auto, &kind)
	assert.Equal(t, 1, auto)
	assert.Equal(t, "gathering", kind)

	// A SECOND auto-send to the same replier must NOT repeat the disclosure.
	_, out2 := postHelper(t, ownerToken, map[string]interface{}{
		"action": "Send", "msgid": msgID, "userid": replierUserID,
		"body": "Quick nudge — still keen?", "kind": "nudge",
	})
	chatmsgid2 := uint64(out2["chatmsgid"].(float64))
	var body2 string
	db.Raw("SELECT message FROM chat_messages WHERE id = ?", chatmsgid2).Scan(&body2)
	assert.NotContains(t, body2, "automated assistant")
}

// TestHelperSendRefusedWhenPaused: once paused, an auto-send is refused server-side
// (closes the timing window where an in-flight brain run messages after the human
// stepped in).
func TestHelperSendRefusedWhenPaused(t *testing.T) {
	_, replierUserID, msgID, _, ownerToken := helperFixture(t, uniquePrefix("helppause"))

	code, _ := postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "paused"})
	require.Equal(t, 200, code)

	code, _ = postHelper(t, ownerToken, map[string]interface{}{
		"action": "Send", "msgid": msgID, "userid": replierUserID, "body": "should not send", "kind": "gathering",
	})
	assert.Equal(t, 409, code, "auto-send must be refused while paused")

	// Resuming re-enables sending.
	postHelper(t, ownerToken, map[string]interface{}{"action": "SetStatus", "msgid": msgID, "status": "active"})
	code, _ = postHelper(t, ownerToken, map[string]interface{}{
		"action": "Send", "msgid": msgID, "userid": replierUserID, "body": "now ok", "kind": "gathering",
	})
	assert.Equal(t, 200, code)
}

// TestHelperProposalAndResolveAllocation: an allocation proposal, when sent by the
// human, reserves the interest, records a promise, sends the (edited) message and
// marks the proposal sent.
func TestHelperProposalAndResolveAllocation(t *testing.T) {
	ownerID, replierUserID, msgID, itemID, ownerToken := helperFixture(t, uniquePrefix("helpalloc"))
	_ = ownerID
	db := database.DBConn
	// Give the offer access instructions so a Reserve triggers their delivery.
	db.Exec("UPDATE messages SET accessinstructions = 'Round the back, code 1234' WHERE id = ?", msgID)

	// Driver records the replier + item then proposes an allocation.
	_, ro := postHelper(t, ownerToken, map[string]interface{}{"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID, "state": "QUALIFIED"})
	replierID := uint64(ro["replierid"].(float64))
	postHelper(t, ownerToken, map[string]interface{}{"action": "SetItemState", "replierid": replierID, "bulkitemid": itemID, "state": "QUALIFIED", "qty_wanted": 2})

	code, out := postHelper(t, ownerToken, map[string]interface{}{
		"action": "Proposal", "msgid": msgID, "type": "allocation",
		"replierid": replierID, "bulkitemid": itemID,
		"summary": "Allocate 2 desks", "proposed_text": "Good news, the desks are yours!",
		"payload": `{"qty":2}`, "rationale": "Only qualified candidate",
	})
	require.Equal(t, 200, code)
	proposalID := uint64(out["proposalid"].(float64))
	require.NotZero(t, proposalID)

	// Human edits the text and sends.
	code, out = postHelper(t, ownerToken, map[string]interface{}{
		"action": "ResolveProposal", "proposalid": proposalID, "decision": "send",
		"text": "Great news - the 2 desks are allocated to you!",
	})
	require.Equal(t, 200, code)

	// Interest reserved.
	var istate string
	db.Raw("SELECT state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", itemID, replierUserID).Scan(&istate)
	assert.Equal(t, "Reserved", istate)
	// Promise recorded.
	var promises int64
	db.Raw("SELECT COUNT(*) FROM messages_promises WHERE msgid = ? AND userid = ?", msgID, replierUserID).Scan(&promises)
	assert.Equal(t, int64(1), promises)
	// Proposal marked sent with the edited text.
	var pstatus, rtext string
	db.Raw("SELECT status, COALESCE(resolved_text,'') FROM helper_proposals WHERE id = ?", proposalID).Row().Scan(&pstatus, &rtext)
	assert.Equal(t, "sent", pstatus)
	assert.Contains(t, rtext, "allocated to you")
	// The sent message is recorded non-auto and linked to the proposal.
	var auto int
	var linked uint64
	db.Raw("SELECT auto, COALESCE(proposalid,0) FROM helper_sent_messages WHERE proposalid = ?", proposalID).Row().Scan(&auto, &linked)
	assert.Equal(t, 0, auto)
	assert.Equal(t, proposalID, linked)
	// Item + replier states moved to ALLOCATED.
	var itstate, rstate string
	db.Raw("SELECT state FROM helper_item_states WHERE replierid = ? AND bulkitemid = ?", replierID, itemID).Scan(&itstate)
	db.Raw("SELECT state FROM helper_repliers WHERE id = ?", replierID).Scan(&rstate)
	assert.Equal(t, "ALLOCATED", itstate)
	assert.Equal(t, "ALLOCATED", rstate)
}

// TestHelperResolveDismiss: dismissing a proposal marks it dismissed and does not
// reserve anything.
func TestHelperResolveDismiss(t *testing.T) {
	_, replierUserID, msgID, itemID, ownerToken := helperFixture(t, uniquePrefix("helpdismiss"))
	db := database.DBConn

	_, ro := postHelper(t, ownerToken, map[string]interface{}{"action": "UpsertReplier", "msgid": msgID, "userid": replierUserID, "state": "QUALIFIED"})
	replierID := uint64(ro["replierid"].(float64))
	_, out := postHelper(t, ownerToken, map[string]interface{}{
		"action": "Proposal", "msgid": msgID, "type": "allocation", "replierid": replierID, "bulkitemid": itemID,
		"summary": "Allocate", "proposed_text": "yours",
	})
	proposalID := uint64(out["proposalid"].(float64))

	code, _ := postHelper(t, ownerToken, map[string]interface{}{"action": "ResolveProposal", "proposalid": proposalID, "decision": "dismiss"})
	require.Equal(t, 200, code)

	var pstatus, istate string
	db.Raw("SELECT status FROM helper_proposals WHERE id = ?", proposalID).Scan(&pstatus)
	db.Raw("SELECT state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", itemID, replierUserID).Scan(&istate)
	assert.Equal(t, "dismissed", pstatus)
	assert.Equal(t, "Interested", istate, "dismiss must not reserve the item")

	// Re-resolving an already-resolved proposal is a conflict.
	code, _ = postHelper(t, ownerToken, map[string]interface{}{"action": "ResolveProposal", "proposalid": proposalID, "decision": "send"})
	assert.Equal(t, 409, code)
}
