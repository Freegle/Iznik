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

// 9954: editing a message's subject must invalidate its search index. The vector embedding
// (messages_embeddings) is populated once for messages "missing" from that table and never
// refreshed on edit, so a term the edit introduces would never be searchable.
// applyPatchMessageCore now drops the stale row so the background embedder rebuilds from
// the new text. This mirrors the reported bug: a Wanted's subject was edited to add
// "Moulinex" and a Support Tools search for "Moulinex" found nothing, while the original
// subject wording still matched.
func TestPatchMessageSubjectEditInvalidatesSearchIndexes(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexSubj")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, "WANTED: Spindle stem "+prefix, 55.0, -1.0)

	// Give it a vector embedding row (subject_embedding is a NOT NULL blob).
	db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)",
		msgID, []byte{0x00}, "test")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
	})

	// Precondition: the embedding is populated.
	var embBefore int64
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embBefore)
	require.Equal(t, int64(1), embBefore, "message should have an embedding before the edit")

	// The owner edits the subject to add a new word - subjectChanged must trigger the
	// search-index invalidation.
	newSubject := fmt.Sprintf("WANTED: Spindle stem for Moulinex food processor %s", prefix)
	body := fmt.Sprintf(`{"id":%d,"subject":%q}`, msgID, newSubject)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	// The stale row must be gone, so the background job re-embeds from the new subject.
	var embAfter int64
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embAfter)
	assert.Equal(t, int64(0), embAfter, "editing the subject clears the stale vector embedding")
}

// 9954 follow-up: messages_embeddings is derived from subject+textbody
// (GenerateEmbeddingsCommand), so editing only the body invalidates it just as a subject
// edit does. This is the counterpart to the test above: invalidation must not depend on the
// subject having changed.
func TestPatchMessageBodyOnlyEditInvalidatesEmbedding(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexBody")

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, "WANTED: Spindle stem "+prefix, 55.0, -1.0)

	db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)",
		msgID, []byte{0x00}, "test")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
	})

	var embBefore int64
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embBefore)
	require.Equal(t, int64(1), embBefore, "message should have an embedding before the edit")

	// The owner edits the body only — subject is untouched.
	body := fmt.Sprintf(`{"id":%d,"textbody":"Now looking for a Moulinex food processor %s"}`, msgID, prefix)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var embAfter int64
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embAfter)
	assert.Equal(t, int64(0), embAfter, "a body-only edit clears the stale vector embedding")
}
