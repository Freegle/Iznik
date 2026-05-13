package test

// TestMessagePatch_AIImageRemovedWhenUserAddsPhoto asserts the CORRECT behaviour:
// when a message owner PATCHes their post with a keep-list containing both an
// AI-generated illustration ID and a user-supplied photo ID, the API must
// automatically evict the AI attachment so only the user's own photo remains.
//
// AssertFlip Step 2 (INVERTED):
//   - FAILS on current (buggy) code — the API keeps both attachments.
//   - PASSES after a backend fix strips AI IDs from the keep-list whenever
//     user-supplied attachments are also present.

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
	prefix := uniquePrefix("aicoexist")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Lamp "+prefix+" (TestTown)", 55.0, -1.0)

	// Simulate the batch service having attached an AI illustration.
	aiUID := "freegletusd-ai-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var aiAttachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND externaluid = ? ORDER BY id DESC LIMIT 1", msgID, aiUID).Scan(&aiAttachID)
	assert.NotZero(t, aiAttachID)

	// Simulate the user uploading their own photo.
	userUID := "freegletusd-user-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, NULL, 0)", msgID, userUID)
	var userAttachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND externaluid = ? ORDER BY id DESC LIMIT 1", msgID, userUID).Scan(&userAttachID)
	assert.NotZero(t, userAttachID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
	})

	// Owner PATCHes with BOTH the AI attachment ID and their own photo ID in the
	// keep-list. This mirrors the scenario where both IDs end up in the request.
	body := fmt.Sprintf(`{"id":%d,"attachments":[%d,%d]}`, msgID, aiAttachID, userAttachID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// AssertFlip Step 2 — FAILS on buggy code, PASSES after the fix.
	// The API must evict AI attachments when non-AI (user-supplied) attachments
	// are also present in the keep-list.

	var attachCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ?", msgID).Scan(&attachCount)
	assert.Equal(t, int64(1), attachCount,
		"PATCH must leave exactly 1 attachment when the user adds their own photo alongside an AI one")

	var remainingUID string
	db.Raw("SELECT externaluid FROM messages_attachments WHERE msgid = ? LIMIT 1", msgID).Scan(&remainingUID)
	assert.Equal(t, userUID, remainingUID,
		"only the user's own photo must remain after PATCH, not the AI attachment")

	var aiStillExists int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND id = ?", msgID, aiAttachID).Scan(&aiStillExists)
	assert.Equal(t, int64(0), aiStillExists,
		"AI attachment must be removed when the user adds their own photo via PATCH")
}
