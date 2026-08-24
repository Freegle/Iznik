package test

// TDD (Discourse /t/cant-release-post-in-order-to-reject/9894): rejecting a HELD
// message must clear heldby.
//
// Bug: a mod held a rippled post on its origin group, then rejected it. handleReject
// moved the row to the Rejected collection but never cleared `heldby`, so the rejected
// row (deleted=0, heldby still set) left the post looking held. A mod on ANOTHER group
// the post rippled into then saw a "Held" banner + a Release button, was blocked from
// rejecting their own copy, and Release could not clear it.
//
// Holds are per-group (messages_groups.heldby); there is no longer a message-level
// mirror, so one group's stale hold can no longer describe the post as a whole. What
// remains worth pinning is the per-group behaviour: reject clears the hold on the rows
// it rejects, and leaves other groups' copies alone.

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

	// Hold the pending message: sets messages_groups.heldby on this group's row.
	postAction(map[string]interface{}{"id": msgID, "action": "Hold"})

	var heldbyAfterHold *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&heldbyAfterHold)
	require.NotNil(t, heldbyAfterHold, "precondition: this group's copy should be held after Hold")

	// Reject with an explanation (moves the Pending row to Rejected, deleted stays 0).
	postAction(map[string]interface{}{
		"id":      msgID,
		"action":  "Reject",
		"subject": prefix + " rejected: breaks the rules",
	})

	// A rejected copy is no longer held (Discourse 9894).
	var groupHeldby *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&groupHeldby)
	assert.Nil(t, groupHeldby, "messages_groups.heldby must be NULL after rejecting a held message")

	// Sanity: the reject actually took effect.
	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Rejected", collection, "the copy should be Rejected")
}

// The cross-group half of 9894: rejecting a held copy on one group must not leave a
// phantom hold on the copy another group is still working. The two used to be coupled
// through the message-level mirror, which is exactly what stranded the other group.
func TestRejectHeldCopyLeavesOtherGroupUnheld(t *testing.T) {
	prefix := uniquePrefix("RejectHeldOtherGroup")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modA := CreateTestUser(t, prefix+"_moda", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modA, groupA, "Moderator")
	_, modAToken := CreateTestSession(t, modA)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, contentcheck_checked_at) VALUES (?, ?, NOW(), 'Pending', 0, NOW())",
		msgID, groupB)
	defer func() {
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	}()

	postAction := func(body map[string]interface{}) {
		b, _ := json.Marshal(body)
		req := httptest.NewRequest("POST",
			fmt.Sprintf("/api/message?jwt=%s", modAToken), bytes.NewBuffer(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
	}

	postAction(map[string]interface{}{"id": msgID, "action": "Hold", "groupid": groupA})
	postAction(map[string]interface{}{
		"id":      msgID,
		"action":  "Reject",
		"groupid": groupA,
		"subject": prefix + " rejected: breaks the rules",
	})

	var heldA, heldB *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldA)
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldB)
	assert.Nil(t, heldA, "the rejected copy must not stay held")
	assert.Nil(t, heldB, "group B's copy was never held and must not appear held")

	var collB string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collB)
	assert.Equal(t, "Pending", collB, "group B still has its own copy to moderate")
}

// The live failure behind this test (Vale of White Horse, msgid 121384453):
// a post auto-flagged into the Spam collection appears in ModTools' pending
// queue with the same Reject action, but handleReject's awaiting-moderation
// gate only accepted Pending - so Reject answered ret=1 and did NOTHING,
// silently, across every browser the mods tried. A spam-flagged post is still
// awaiting moderation; rejecting it must work exactly as for Pending. The
// (re-)approved-to-live no-op (Discourse 9815) is unaffected: Approved is
// still outside the gate.
func TestRejectWorksOnSpamCollection(t *testing.T) {
	prefix := uniquePrefix("RejectSpamColl")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupID, prefix)
	db.Exec("UPDATE messages_groups SET collection = 'Spam' WHERE msgid = ? AND groupid = ?", msgID, groupID)

	b, _ := json.Marshal(map[string]interface{}{
		"id":      msgID,
		"action":  "Reject",
		"subject": prefix + " rejected: not suitable",
		"body":    "Please see the group rules.",
	})
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/message?jwt=%s", modToken), bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"], "rejecting a spam-flagged post must succeed, not silently no-op")

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Rejected", collection, "the spam-flagged copy should be Rejected")
}
