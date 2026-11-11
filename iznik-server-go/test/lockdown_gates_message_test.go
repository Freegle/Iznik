package test

// Gate tests for message.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md):
//   - "mods": a moderator editing someone else's post is refused. (The moderator
//     actions on POST /message - Approve, Reject, Hold and the rest - no longer exist.)
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
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func postsHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"posts": true},
	})
}

// --- "posts": PUT /message direct-approve forced to Pending for an unmoderated member ---

func TestLockdownForcesPutMessagePendingWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_msg_putpending")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	// Unmoderated (DEFAULT) member - without a hold this would go straight to Approved.
	db.Exec("UPDATE users SET postingstatus = 'DEFAULT' WHERE id = ?", userID)

	restore := postsHeld()
	defer restore()

	body := map[string]interface{}{
		"type":    "Offer",
		"subject": prefix + " Test Offer",
		// A blank/omitted collection defaults to "Draft" (message.go:5243), which
		// stores the message in messages_drafts instead of as a live post, so
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
	db.Raw("SELECT collection FROM messages WHERE id = ?", newID).Scan(&collection)
	assert.Equal(t, "Pending", collection, "an unmoderated member's post must still be forced to Pending while posts is held")
}

// --- "mods": a moderator editing someone else's post is refused (not "posts") ---

func TestLockdownRefusesModeratorEditOfOthersPost(t *testing.T) {
	prefix := uniquePrefix("ld_msg_modedit")
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	msgID := CreateTestMessage(t, posterID, prefix+" offer", 52.5, -1.8)

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

	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	_, ownerToken := CreateTestSession(t, ownerID)

	// Unmoderated (DEFAULT) member with a live post: without a hold the edit needs no
	// review (reviewrequired=0). Here "posts" is held instead, so it must flip to 1.
	db.Exec("UPDATE users SET postingstatus = 'DEFAULT' WHERE id = ?", ownerID)

	msgID := CreateTestMessage(t, ownerID, prefix+" offer", 52.5, -1.8)
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
