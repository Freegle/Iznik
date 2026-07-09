package test

// TDD (Discourse /t/cant-release-post-in-order-to-reject/9894): rejecting a HELD
// message must clear heldby.
//
// Bug: a mod held a rippled post on its origin group, then rejected it. handleReject
// moves the row to the Rejected collection but never clears `heldby`, so the rejected
// row (deleted=0, heldby still set) keeps the message-level `messages.heldby` pinned
// (the release handler's "still held on any group?" check counts a rejected-but-held
// row). A mod on ANOTHER group the post rippled into then sees a "Held" banner + a
// Release button, is blocked from rejecting their own copy, and Release cannot clear
// it. A rejected post is no longer held, so reject must clear heldby on the rejected
// rows and, if that was the last held copy, clear messages.heldby too.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestRejectClearsHeldby(t *testing.T) {
	prefix := uniquePrefix("RejectClearsHeld")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupID, prefix)

	postAction := func(body map[string]interface{}) {
		b, _ := json.Marshal(body)
		req := httptest.NewRequest("POST",
			fmt.Sprintf("/api/message?jwt=%s", modToken), bytes.NewBuffer(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}

	// Hold the pending message: sets messages_groups.heldby + messages.heldby.
	postAction(map[string]interface{}{"id": msgID, "action": "Hold"})

	var heldbyAfterHold *uint64
	db.Raw("SELECT heldby FROM messages WHERE id = ?", msgID).Scan(&heldbyAfterHold)
	assert.NotNil(t, heldbyAfterHold, "precondition: message should be held after Hold")

	// Reject with an explanation (moves the Pending row to Rejected, deleted stays 0).
	postAction(map[string]interface{}{
		"id":      msgID,
		"action":  "Reject",
		"subject": prefix + " rejected: breaks the rules",
	})

	// A rejected post is no longer held - heldby must be cleared at both levels so it
	// stops showing "Held" and never blocks a mod on another group (Discourse 9894).
	var msgHeldby *uint64
	db.Raw("SELECT heldby FROM messages WHERE id = ?", msgID).Scan(&msgHeldby)
	assert.Nil(t, msgHeldby, "messages.heldby must be NULL after rejecting a held message")

	var groupHeldby *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&groupHeldby)
	assert.Nil(t, groupHeldby, "messages_groups.heldby must be NULL after rejecting a held message")

	// Sanity: the reject actually took effect.
	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Rejected", collection, "the copy should be Rejected")
}
