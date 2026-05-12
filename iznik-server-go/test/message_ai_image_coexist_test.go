package test

// TestMessagePatch_AIImageRemovedWhenUserAddsPhoto tests that an AI illustration
// is automatically removed when the owner adds their own photo to a post.
//
// Bug: the frontend includes the AI attachment ID in the PATCH keep-list alongside
// the user's new photo, so both remain attached. The frontend fix (compose.js)
// avoids sending AI IDs when a real photo exists, but the API should also enforce
// this as a defence-in-depth measure.
//
// STEP 2 (INVERTED): asserts the correct behaviour — only the user's photo remains.
// FAILS on current (buggy) code; PASSES after a backend fix adds AI-eviction logic
// to applyPatchMessage when the keep-list contains user-supplied attachments.

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestMessagePatch_AIImageRemovedWhenUserAddsPhoto(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("aicoexist2")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Lamp "+prefix+" (TestTown)", 55.0, -1.0)

	// Simulate batch service having attached an AI illustration.
	aiName := "aicoexist2-" + prefix
	aiUID := "freegletusd-ai2-" + prefix
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, 'active')", aiName, aiUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? ORDER BY id DESC LIMIT 1", aiName).Scan(&aiImageID)
	assert.NotZero(t, aiImageID)

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var aiAttachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&aiAttachID)
	assert.NotZero(t, aiAttachID)

	// Simulate user uploading their own photo (also linked to this message).
	userUID := "freegletusd-user2-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, NULL, 0)", msgID, userUID)
	var userAttachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND externaluid = ? ORDER BY id DESC LIMIT 1", msgID, userUID).Scan(&userAttachID)
	assert.NotZero(t, userAttachID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
		db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
	})

	// Owner sends PATCH with BOTH the AI attachment and their own photo in the keep-list.
	// This mirrors how the frontend used to work before the compose.js fix; the API
	// should enforce the invariant independently as a defence-in-depth measure.
	body := fmt.Sprintf(`{"id":%d,"attachments":[%d,%d]}`, msgID, aiAttachID, userAttachID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// STEP 2 (INVERTED) — FAILS on buggy code, PASSES after fix.
	// When the owner adds their own photo, the PATCH should automatically remove
	// any AI-generated attachments so the post shows only the user's own image.

	var attachCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ?", msgID).Scan(&attachCount)
	assert.Equal(t, int64(1), attachCount, "message should have exactly 1 attachment after user adds their own photo (AI should be removed)")

	var remainingUID string
	db.Raw("SELECT externaluid FROM messages_attachments WHERE msgid = ? LIMIT 1", msgID).Scan(&remainingUID)
	assert.Equal(t, userUID, remainingUID, "only the user's own photo should remain, not the AI attachment")

	var aiAttachStillExists int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND id = ?", msgID, aiAttachID).Scan(&aiAttachStillExists)
	assert.Equal(t, int64(0), aiAttachStillExists, "AI attachment must be removed when user adds their own photo")
}
