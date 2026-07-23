package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// 9954: editing a message's subject/body must invalidate its search indexes. Both the
// keyword index (messages_index) and the vector embedding (messages_embeddings) are
// populated once for messages "missing" from those tables and never refreshed on edit,
// so a term the edit introduces would never be searchable. applyPatchMessageCore now
// drops both stale rows so the background indexer/embedder rebuild from the new text.
func TestPatchMessageInvalidatesSearchIndexes(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexEdit")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	// CreateTestMessage indexes the subject words into messages_index.
	msgID := CreateTestMessage(t, ownerID, groupID, "WANTED: Spindle stem "+prefix, 55.0, -1.0)

	// Give it a vector embedding row too (subject_embedding is a NOT NULL blob).
	db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)",
		msgID, []byte{0x00}, "test")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_index WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
	})

	// Precondition: both indexes are populated.
	var idxBefore, embBefore int64
	db.Raw("SELECT COUNT(*) FROM messages_index WHERE msgid = ?", msgID).Scan(&idxBefore)
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embBefore)
	require.Greater(t, idxBefore, int64(0), "message should be keyword-indexed before the edit")
	require.Equal(t, int64(1), embBefore, "message should have an embedding before the edit")

	// The owner edits the body — textChanged triggers the search-index invalidation.
	body := fmt.Sprintf(`{"id":%d,"textbody":"Now looking for a Moulinex food processor %s"}`, msgID, prefix)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	// Both stale index rows must be gone, so the background jobs re-index/re-embed.
	var idxAfter, embAfter int64
	db.Raw("SELECT COUNT(*) FROM messages_index WHERE msgid = ?", msgID).Scan(&idxAfter)
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embAfter)
	assert.Equal(t, int64(0), idxAfter, "editing the body clears the stale keyword index")
	assert.Equal(t, int64(0), embAfter, "editing the body clears the stale vector embedding")
}
