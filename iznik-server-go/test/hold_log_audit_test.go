package test

// AssertFlip test for bug: Message/Hold log entries are absent from the
// messages logtype audit log that moderators see in the Messages-log tab.
//
// Symptom: After performing the 'Hold' action on a pending message, the
// moderator cannot find any record of the action in the Messages audit log.
// The Hold log entry is silently dropped because SUBTYPE_HOLD is not included
// in the default subtype filter for logtype=messages in logs.go (line 94):
//
//   subtypes = []string{LOG_SUBTYPE_RECEIVED, LOG_SUBTYPE_APPROVED,
//       LOG_SUBTYPE_REJECTED, LOG_SUBTYPE_DELETED, LOG_SUBTYPE_AUTO_REPOSTED,
//       LOG_SUBTYPE_AUTO_APPROVED, LOG_SUBTYPE_OUTCOME}
//
// LOG_SUBTYPE_HOLD is missing from the list above.
//
// AssertFlip Step 1 — BUGGY behaviour (PASSES on current code, not committed):
//   holdFound := false
//   assert.False(t, holdFound, "BUG: Hold log absent from messages log")
//   → Passes because Hold entry is absent (it's not in the filter).
//
// AssertFlip Step 2 — CORRECT behaviour (FAILS on current code, committed here):
//   assert.True(t, holdFound, "Hold log must appear in messages log")
//   → Fails because the filter omits SUBTYPE_HOLD.

import (
	jenc "encoding/json"
	"bytes"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestHoldLogAppearsInMessagesLog(t *testing.T) {
	prefix := uniquePrefix("HoldMsgAudit")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupID, prefix)

	// Perform the Hold action via the API.
	holdBody := map[string]interface{}{
		"id":     msgID,
		"action": "Hold",
	}
	holdBytes, _ := jenc.Marshal(holdBody)
	holdReq := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?jwt=%s", modToken),
		bytes.NewBuffer(holdBytes))
	holdReq.Header.Set("Content-Type", "application/json")
	holdResp, err := getApp().Test(holdReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, holdResp.StatusCode)

	// Fetch the messages log for this group (logtype=messages is what the
	// ModTools Messages-log tab uses to review message audit history).
	logsReq := httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/logs?logtype=messages&groupid=%d&jwt=%s",
			groupID, modToken),
		nil)
	logsResp, err := getApp().Test(logsReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, logsResp.StatusCode)

	var result map[string]interface{}
	jenc.Unmarshal(rsp(logsResp), &result)
	assert.Equal(t, float64(0), result["ret"])

	logs, _ := result["logs"].([]interface{})

	// Scan for a Hold log entry for this specific message.
	holdFound := false
	for _, entry := range logs {
		e, ok := entry.(map[string]interface{})
		if !ok {
			continue
		}
		if e["type"] != "Message" {
			continue
		}
		subtype, _ := e["subtype"].(string)
		msgidFloat, _ := e["msgid"].(float64)
		if subtype == "Hold" && uint64(msgidFloat) == msgID {
			holdFound = true
			break
		}
	}

	// Step 2 (AssertFlip): assert CORRECT behaviour — FAILS on current buggy code.
	// Fix: add LOG_SUBTYPE_HOLD to the messages logtype subtype filter in logs.go.
	assert.True(t, holdFound,
		"Hold log entry must appear in the messages log (logtype=messages) "+
			"after performing a Hold action — currently FAILS because "+
			"LOG_SUBTYPE_HOLD is absent from the messages-logtype subtype filter in logs.go")
}
