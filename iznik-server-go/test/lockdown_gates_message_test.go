package test

// Gate tests for message.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md):
//   - "mods": dispatchPostMessageAction allows Approve only when it carries no
//     subject/body/stdmsgid (a composed moderator message), and refuses the rest of
//     10.5's moderator actions outright.
//   - "posts": PUT /message direct-approve path for an unmoderated (trusted) member is
//     forced back to Pending; a member's PATCH edit of a live post is forced through the
//     same pending-edits review a moderated member's edit would need. A moderator editing
//     someone else's post is refused under "mods" instead (a member edit, even by someone
//     who is also a moderator, uses "posts").
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so these
// tests never write a real "lockdowns" row and cannot race the other packages' test
// binaries that `go test ./...` runs concurrently against the same test database.

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
)

func postsHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"posts": true},
	})
}

// postMessageAction is defined in message_test.go (same package) - reused here.

// --- "mods": Approve with composed text is refused, counted as a moderator action ---

func TestLockdownRefusesApproveWithSubject(t *testing.T) {
	prefix := uniquePrefix("ld_msg_appr_subj")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	restore := modsHeld()
	defer restore()

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Approve", "subject": "Welcome!",
	})
	assert.Equal(t, 409, status)

	var collection string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Pending", collection, "an Approve carrying text must not go through while mods is held")
}

func TestLockdownRefusesApproveWithStdmsg(t *testing.T) {
	prefix := uniquePrefix("ld_msg_appr_std")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	restore := modsHeld()
	defer restore()

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Approve", "stdmsgid": 42,
	})
	assert.Equal(t, 409, status)
}

// The basic Approve button (no subject/body/stdmsgid) stays allowed - it is the escape
// hatch every other gated moderator action deliberately lacks.
func TestLockdownDoesNotRefuseApproveBasic(t *testing.T) {
	prefix := uniquePrefix("ld_msg_appr_ok")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	restore := modsHeld()
	defer restore()

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Approve",
	})
	assert.Equal(t, 200, status)

	var collection string
	database.DBConn.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
	assert.Equal(t, "Approved", collection)
}

// --- "mods": the rest of section 10.5's moderator actions are refused outright ---

func TestLockdownRefusesPostMessageModeratorActions(t *testing.T) {
	for _, action := range []string{
		"Reject", "Delete", "Spam", "Hold", "Release",
		"ApproveEdits", "RevertEdits", "Move", "BackToPending", "RejectToDraft", "BackToDraft",
	} {
		t.Run(action, func(t *testing.T) {
			prefix := uniquePrefix("ld_msg_" + action)
			groupID := CreateTestGroup(t, prefix)
			posterID := CreateTestUser(t, prefix+"_poster", "User")
			modID := CreateTestUser(t, prefix+"_mod", "User")
			CreateTestMembership(t, posterID, groupID, "Member")
			CreateTestMembership(t, modID, groupID, "Moderator")
			_, modToken := CreateTestSession(t, modID)
			msgID := createPendingMessage(t, posterID, groupID, prefix)

			restore := modsHeld()
			defer restore()

			status := postMessageAction(t, modToken, map[string]interface{}{
				"id": msgID, "action": action, "groupid": groupID,
			})
			assert.Equal(t, 409, status, action+" must be refused while mods is held")
		})
	}
}

// --- "posts": PUT /message direct-approve forced to Pending for an unmoderated member ---

func TestLockdownForcesPutMessagePendingWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_msg_putpending")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	// Unmoderated (DEFAULT) member on a group that does not moderate all posts -
	// without a hold this would go straight to Approved (see
	// TestPatchMessageEditNoReviewUnmoderatedMember's same setup).
	db.Exec("UPDATE memberships SET ourPostingStatus = 'DEFAULT' WHERE userid = ? AND groupid = ?", userID, groupID)
	db.Exec("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.moderated', 0, '$.closed', 0) WHERE id = ?", groupID)

	restore := postsHeld()
	defer restore()

	body := map[string]interface{}{
		"groupid": groupID,
		"type":    "Offer",
		"subject": prefix + " Test Offer",
		// A blank/omitted collection defaults to "Draft" (message.go:5243), which
		// stores the message in messages_drafts instead of messages_groups, so
		// this must be non-Draft to exercise the direct-submit path being
		// tested here - the server ignores the client's value and computes the
		// real one from posting status (and, with posts held, the lockdown
		// override below), so "Pending" here is just "not a draft", matching
		// TestPutMessageGeneratesSyntheticMessageID's same setup.
		"collection": "Pending",
		"textbody":   "A test offer message",
		"item":       "Test Item",
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/message?jwt="+token, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "the write itself must still succeed")

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	newID := uint64(result["id"].(float64))

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", newID, groupID).Scan(&collection)
	assert.Equal(t, "Pending", collection, "an unmoderated member's post must still be forced to Pending while posts is held")
}

// --- "mods": a moderator editing someone else's post is refused (not "posts") ---

func TestLockdownRefusesModeratorEditOfOthersPost(t *testing.T) {
	prefix := uniquePrefix("ld_msg_modedit")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	restore := modsHeld()
	defer restore()

	body := map[string]interface{}{"id": msgID, "subject": prefix + " edited by mod"}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}

// --- "posts": a member's own edit of a live post is forced through pending-edits
// review, even for a member normally trusted to skip it. ---

func TestLockdownForcesReviewOnOwnEditWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_msg_ownedit_review")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)

	// Unmoderated (DEFAULT) member, non-moderated group - same setup as
	// TestPatchMessageEditNoReviewUnmoderatedMember, which shows reviewrequired=0
	// without a hold. Here "posts" is held instead, so it must flip to 1.
	db.Exec("UPDATE memberships SET ourPostingStatus = 'DEFAULT' WHERE userid = ? AND groupid = ?", ownerID, groupID)
	db.Exec("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.moderated', 0, '$.closed', 0) WHERE id = ?", groupID)

	msgID := createPendingMessage(t, ownerID, groupID, prefix)
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", msgID, groupID)
	db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID)

	restore := postsHeld()
	defer restore()

	body := map[string]interface{}{"id": msgID, "subject": prefix + " edited subject"}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "the edit itself must still succeed")

	var reviewRequired int
	db.Raw("SELECT reviewrequired FROM messages_edits WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&reviewRequired)
	assert.Equal(t, 1, reviewRequired, "an unmoderated member's own edit must be forced through review while posts is held")
}
