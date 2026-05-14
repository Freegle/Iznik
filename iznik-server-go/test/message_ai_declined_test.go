package test

// TestPatchMessage_RemovingAIAttachmentWritesDeclinedRow verifies that when a
// moderator removes an AI-generated attachment via PATCH /message, the server
// inserts a row into messages_ai_declined so the illustrations cron never
// re-injects an AI image for that message.
//
// Root cause: V1 (iznik-server/include/message/Message.php:3974–3989) does
// INSERT IGNORE INTO messages_ai_declined whenever an attachment with
// externalmods.ai===true is removed.  The Go recordAIDeletions function only
// cast a Reject microaction and omitted this insert, leaving the message
// unprotected against the cron re-adding a cached illustration.

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestPatchMessage_RemovingAIAttachmentWritesDeclinedRow(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("aiDeclined")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "WANTED: Chicken mesh "+prefix, 55.0, -1.0)

	// Simulate the batch service having attached an AI illustration.
	aiUID := "freegletusd-ai-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_ai_declined WHERE msgid = ?", msgID)
	})

	// PATCH with an empty attachments list — removes the AI illustration.
	body := fmt.Sprintf(`{"id":%d,"attachments":[]}`, msgID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// The cron (messages:generate-illustrations) guards via
	//   LEFT JOIN messages_ai_declined … AND maid.msgid IS NULL
	// so a missing row here means the cron will re-inject a cached AI image.
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_ai_declined WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(1), count,
		"removing an AI attachment via PATCH must insert into messages_ai_declined so the cron won't re-add it")
}
