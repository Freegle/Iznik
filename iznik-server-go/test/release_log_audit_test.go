package test

// Test that Message/Release log entries appear in the messages logtype audit
// log that moderators see in the Messages-log tab.
//
// Bug pattern: the same omission that hid Hold logs (fixed in 096ea69) could
// affect Release — LOG_SUBTYPE_RELEASE was absent from the messages-logtype
// subtype filter in logs.go alongside LOG_SUBTYPE_HOLD.
//
// This test asserts CORRECT behaviour: after holding then releasing a pending
// message, the Release entry must be visible in the messages log.

import (
	jenc "encoding/json"
	"bytes"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestReleaseLogAppearsInMessagesLog(t *testing.T) {
	prefix := uniquePrefix("RelMsgAudit")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupID, prefix)

	// Hold the message first — Release requires a prior Hold.
	holdBody, _ := jenc.Marshal(map[string]interface{}{"id": msgID, "action": "Hold"})
	holdReq := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?jwt=%s", modToken),
		bytes.NewBuffer(holdBody))
	holdReq.Header.Set("Content-Type", "application/json")
	holdResp, err := getApp().Test(holdReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, holdResp.StatusCode)

	// Release the held message.
	releaseBody, _ := jenc.Marshal(map[string]interface{}{"id": msgID, "action": "Release"})
	releaseReq := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?jwt=%s", modToken),
		bytes.NewBuffer(releaseBody))
	releaseReq.Header.Set("Content-Type", "application/json")
	releaseResp, err := getApp().Test(releaseReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, releaseResp.StatusCode)

	// Fetch the messages log and verify the Release entry is present.
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

	releaseFound := false
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
		if subtype == "Release" && uint64(msgidFloat) == msgID {
			releaseFound = true
			break
		}
	}

	assert.True(t, releaseFound,
		"Release log entry must appear in the messages log (logtype=messages) "+
			"after releasing a held message — LOG_SUBTYPE_RELEASE must be in the "+
			"messages-logtype subtype filter in logs.go")
}
