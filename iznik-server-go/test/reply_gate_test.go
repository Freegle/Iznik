package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Micro-volunteering as the reply gate. A member who has already replied to
// REPLY_GATE_AFTER posts in the last day must pass a graded check (a micro-volunteering task
// whose answer is already settled by other members) before the next reply is accepted.
// Nobody has to be there for the gate to work, and a wrong answer does not open it.

type replyGateFixture struct {
	replierID uint64
	token     string
}

func setupReplyGate(t *testing.T, prefix string) replyGateFixture {
	replierID := CreateTestUser(t, prefix+"_replier", utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, replierID)
	return replyGateFixture{replierID: replierID, token: token}
}

// A post by somebody else, with a User2User chat between the replier and the poster, ready
// for an Interested reply.
func postAndRoom(t *testing.T, f replyGateFixture, prefix string, n int) (msgID uint64, chatID uint64) {
	posterID := CreateTestUser(t, fmt.Sprintf("%s_poster%d", prefix, n), utils.SYSTEMROLE_USER)
	msgID = CreateTestMessage(t, posterID, fmt.Sprintf("OFFER: gate item %d", n), 51.5, -0.1)
	chatID = CreateTestChatRoom(t, f.replierID, &posterID, "User2User")
	return
}

// Record an earlier reply the replier already sent today, without going through the API.
func priorReply(t *testing.T, f replyGateFixture, prefix string, n int) {
	db := database.DBConn
	msgID, chatID := postAndRoom(t, f, prefix, n)
	res := db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, message, date, processingrequired, processingsuccessful) "+
		"VALUES (?, ?, 'Interested', ?, 'Yes please', NOW(), 0, 1)", chatID, f.replierID, msgID)
	if res.Error != nil {
		t.Fatalf("prior reply: %v", res.Error)
	}
}

func sendInterested(t *testing.T, f replyGateFixture, chatID, msgID uint64) int {
	body, _ := json.Marshal(map[string]interface{}{"message": "Yes please, I can collect", "refmsgid": msgID})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, f.token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	if err != nil {
		t.Fatalf("send: %v", err)
	}
	return resp.StatusCode
}

// A post other members have settled: n Approve or Reject verdicts from people who are not the
// replier.
func settledPost(t *testing.T, f replyGateFixture, prefix string, verdict string, n int) uint64 {
	db := database.DBConn
	posterID := CreateTestUser(t, prefix+"_settledposter_"+verdict, utils.SYSTEMROLE_USER)
	// Near the members (test users live in Edinburgh): a review task only offers posts near the reviewer.
	msgID := CreateTestMessage(t, posterID, "OFFER: settled "+verdict+" item", 55.9533, -3.1883)
	for i := 0; i < n; i++ {
		voterID := CreateTestUser(t, fmt.Sprintf("%s_voter_%s_%d", prefix, verdict, i), utils.SYSTEMROLE_USER)
		db.Exec("INSERT INTO microactions (actiontype, userid, msgid, result, comments, timestamp, score_negative) VALUES ('CheckMessage', ?, ?, ?, 'settled', NOW(), 0)",
			voterID, msgID, verdict)
	}
	return msgID
}

func recordVerdict(t *testing.T, f replyGateFixture, msgID uint64, result string) {
	db := database.DBConn
	db.Exec("INSERT INTO microactions (actiontype, userid, msgid, result, timestamp, score_negative) VALUES ('CheckMessage', ?, ?, ?, NOW(), 0)",
		f.replierID, msgID, result)
}

func TestReplyGate_DefaultsToFiveWhenUnset(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "")
	assert.Equal(t, 5, chat.ReplyGateAfter(), "default is 5 when the gate isn't configured")
}

func TestReplyGate_ExplicitZeroDisables(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "0")
	assert.Equal(t, 0, chat.ReplyGateAfter(), "an explicit 0 turns the gate off")

	prefix := uniquePrefix("gateoff")
	f := setupReplyGate(t, prefix)
	for i := 0; i < 6; i++ {
		priorReply(t, f, prefix, i)
	}
	msgID, chatID := postAndRoom(t, f, prefix, 99)
	assert.Equal(t, 200, sendInterested(t, f, chatID, msgID), "with the gate off every reply is accepted, even above the default threshold")
}

func TestReplyGate_RefusesFrequentReplierWithoutAPass(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "3")
	prefix := uniquePrefix("gateon")
	f := setupReplyGate(t, prefix)
	settledPost(t, f, prefix, "Approve", microvolunteering.ApprovalQuorum)

	msgID, chatID := postAndRoom(t, f, prefix, 0)
	assert.Equal(t, 200, sendInterested(t, f, chatID, msgID), "the first reply of the day is free")

	for i := 1; i < 3; i++ {
		priorReply(t, f, prefix, i)
	}
	msgID, chatID = postAndRoom(t, f, prefix, 99)
	assert.Equal(t, 428, sendInterested(t, f, chatID, msgID), "the fourth reply needs a pass first")

	var stored int64
	database.DBConn.Table("chat_messages").Where("chatid = ? AND userid = ?", chatID, f.replierID).Count(&stored)
	assert.Equal(t, int64(0), stored, "a refused reply is not stored")
}

func TestReplyGate_OpensOnACorrectGradedAnswerOnly(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "2")
	prefix := uniquePrefix("gatepass")
	f := setupReplyGate(t, prefix)
	for i := 0; i < 2; i++ {
		priorReply(t, f, prefix, i)
	}

	approved := settledPost(t, f, prefix, "Approve", microvolunteering.ApprovalQuorum)
	rejected := settledPost(t, f, prefix, "Reject", microvolunteering.ApprovalQuorum)

	assert.Equal(t, "Approve", microvolunteering.SettledVerdict(database.DBConn, approved, f.replierID))
	assert.Equal(t, "Reject", microvolunteering.SettledVerdict(database.DBConn, rejected, f.replierID))
	unsettled, _ := postAndRoom(t, f, prefix, 50)
	assert.Equal(t, "", microvolunteering.SettledVerdict(database.DBConn, unsettled, f.replierID), "no quorum, no verdict")

	// A wrong answer does not open the gate.
	recordVerdict(t, f, rejected, "Approve")
	assert.False(t, microvolunteering.HasRecentGradedPass(database.DBConn, f.replierID))
	msgID, chatID := postAndRoom(t, f, prefix, 99)
	assert.Equal(t, 428, sendInterested(t, f, chatID, msgID), "a wrong answer keeps the gate shut")

	// A right answer does.
	recordVerdict(t, f, approved, "Approve")
	assert.True(t, microvolunteering.HasRecentGradedPass(database.DBConn, f.replierID))
	assert.Equal(t, 200, sendInterested(t, f, chatID, msgID), "a correct answer opens the gate")
}

func TestReplyGate_GradedChallengeIsASettledPostAndTheAnswerIsMarked(t *testing.T) {
	prefix := uniquePrefix("graded")
	f := setupReplyGate(t, prefix)
	approved := settledPost(t, f, prefix, "Approve", microvolunteering.ApprovalQuorum)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+f.token+"&graded=1", nil), -1)
	assert.Equal(t, 200, resp.StatusCode)
	var challenge map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&challenge)
	assert.Equal(t, "CheckMessage", challenge["type"])
	assert.Equal(t, float64(approved), challenge["msgid"], "the graded challenge is a post other members have settled")
	assert.Equal(t, true, challenge["graded"])

	answer := func(result string) map[string]interface{} {
		body, _ := json.Marshal(map[string]interface{}{"msgid": approved, "response": result})
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+f.token, bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		r, _ := getApp().Test(req, -1)
		assert.Equal(t, 200, r.StatusCode)
		var out map[string]interface{}
		json.NewDecoder(r.Body).Decode(&out)
		return out
	}

	assert.Equal(t, false, answer("Reject")["graded"], "disagreeing with the settled verdict is marked wrong")
	// The first answer on a settled post is final: sending the other answer does not turn a
	// wrong answer into a pass, and does not open the gate.
	assert.Equal(t, false, answer("Approve")["graded"], "the first answer stands")
	var storedResult string
	database.DBConn.Raw("SELECT result FROM microactions WHERE actiontype = 'CheckMessage' AND userid = ? AND msgid = ?", f.replierID, approved).Scan(&storedResult)
	assert.Equal(t, "Reject", storedResult, "a changed answer does not overwrite the stored one")
	assert.False(t, microvolunteering.HasRecentGradedPass(database.DBConn, f.replierID), "re-answering does not open the gate")

	// Once answered, the same post is not offered again.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+f.token+"&graded=1", nil), -1)
	var again map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&again)
	assert.NotEqual(t, float64(approved), again["msgid"])
}

// A member whose first answer on a settled post is right is graded right, and a later
// opposite answer cannot take the pass away either: the stored answer is the one graded.
func TestReplyGate_AFirstCorrectAnswerStaysCorrect(t *testing.T) {
	prefix := uniquePrefix("gatefirst")
	f := setupReplyGate(t, prefix)
	approved := settledPost(t, f, prefix, "Approve", microvolunteering.ApprovalQuorum)

	answer := func(result string) map[string]interface{} {
		body, _ := json.Marshal(map[string]interface{}{"msgid": approved, "response": result})
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+f.token, bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		r, _ := getApp().Test(req, -1)
		assert.Equal(t, 200, r.StatusCode)
		var out map[string]interface{}
		json.NewDecoder(r.Body).Decode(&out)
		return out
	}

	assert.Equal(t, true, answer("Approve")["graded"])
	assert.Equal(t, true, answer("Reject")["graded"], "the stored first answer is graded again")
	assert.True(t, microvolunteering.HasRecentGradedPass(database.DBConn, f.replierID))
}

// A member the graded endpoint has nothing to offer cannot pass the gate, so the gate does not
// shut them out: with no settled post near them the reply goes through.
func TestReplyGate_FailsOpenWhenNoGradedTaskCanBeOffered(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "2")
	prefix := uniquePrefix("gateopen")
	f := setupReplyGate(t, prefix)
	// Far from any post other tests have settled.
	database.DBConn.Exec("UPDATE users SET settings = ? WHERE id = ?", `{"mylocation": {"lat": -45.03, "lng": 169.2}}`, f.replierID)
	for i := 0; i < 2; i++ {
		priorReply(t, f, prefix, i)
	}

	// Nothing is settled near this member, so the endpoint has no task for them.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+f.token+"&graded=1", nil), -1)
	var challenge map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&challenge)
	assert.Nil(t, challenge["type"])
	assert.False(t, microvolunteering.GradedChallengeAvailable(database.DBConn, f.replierID))

	msgID, chatID := postAndRoom(t, f, prefix, 99)
	assert.Equal(t, 200, sendInterested(t, f, chatID, msgID), "no task to pass means no gate")
}

func TestReplyGate_FailsOpenForAMemberWhoDeclinedToBeAsked(t *testing.T) {
	t.Setenv("REPLY_GATE_AFTER", "2")
	prefix := uniquePrefix("gatedecl")
	f := setupReplyGate(t, prefix)
	for i := 0; i < 2; i++ {
		priorReply(t, f, prefix, i)
	}
	settledPost(t, f, prefix, "Approve", microvolunteering.ApprovalQuorum)
	msgID, chatID := postAndRoom(t, f, prefix, 99)
	assert.Equal(t, 428, sendInterested(t, f, chatID, msgID), "a settled post is available, so the gate holds")

	database.DBConn.Exec("UPDATE users SET trustlevel = 'Declined' WHERE id = ?", f.replierID)
	assert.Equal(t, 200, sendInterested(t, f, chatID, msgID), "a member who asked not to be asked is not gated")
}

// A settled post that has an outcome is not offered as a graded task.
func TestReplyGate_GradedChallengeSkipsPostsWithAnOutcome(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("gateout")
	f := setupReplyGate(t, prefix)
	// Somewhere no other test has put a post, so this post is the only candidate.
	db.Exec("UPDATE users SET settings = ? WHERE id = ?", `{"mylocation": {"lat": -46.4, "lng": 168.35}}`, f.replierID)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	msgID := CreateTestMessage(t, posterID, "OFFER: far settled item", -46.4, 168.35)
	for i := 0; i < microvolunteering.ApprovalQuorum; i++ {
		voterID := CreateTestUser(t, fmt.Sprintf("%s_voter_%d", prefix, i), utils.SYSTEMROLE_USER)
		db.Exec("INSERT INTO microactions (actiontype, userid, msgid, result, comments, timestamp, score_negative) VALUES ('CheckMessage', ?, ?, 'Approve', 'settled', NOW(), 0)",
			voterID, msgID)
	}
	assert.True(t, microvolunteering.GradedChallengeAvailable(db, f.replierID), "a settled live post is a task")

	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, 'Taken')", msgID)
	assert.False(t, microvolunteering.GradedChallengeAvailable(db, f.replierID), "a taken post is not a task")
}
