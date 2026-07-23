package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// 9954: editing a message's subject must invalidate its search indexes. Both the keyword
// index (messages_index) and the vector embedding (messages_embeddings) are populated once
// for messages "missing" from those tables and never refreshed on edit, so a term the edit
// introduces would never be searchable. applyPatchMessageCore now drops the stale rows so
// the background indexer/embedder rebuild from the new text. This mirrors the reported bug:
// a Wanted's subject was edited to add "Moulinex" and a Support Tools search for "Moulinex"
// found nothing, while the original subject wording still matched.
func TestPatchMessageSubjectEditInvalidatesSearchIndexes(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexSubj")

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

	// The owner edits the subject to add a new word - subjectChanged must trigger the
	// search-index invalidation, since messages_index is derived from subject alone
	// (MessageSearchService::indexUnindexedMessages / indexString).
	newSubject := fmt.Sprintf("WANTED: Spindle stem for Moulinex food processor %s", prefix)
	body := fmt.Sprintf(`{"id":%d,"subject":%q}`, msgID, newSubject)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	// Both stale index rows must be gone, so the background jobs re-index/re-embed from
	// the new subject.
	var idxAfter, embAfter int64
	db.Raw("SELECT COUNT(*) FROM messages_index WHERE msgid = ?", msgID).Scan(&idxAfter)
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embAfter)
	assert.Equal(t, int64(0), idxAfter, "editing the subject clears the stale keyword index")
	assert.Equal(t, int64(0), embAfter, "editing the subject clears the stale vector embedding")
}

// 9954 follow-up: messages_index is derived from the SUBJECT only (MessageSearchService
// never reads textbody), while messages_embeddings is derived from subject+textbody
// (GenerateEmbeddingsCommand). Editing only the body must therefore invalidate the
// embedding (which just went stale) but must NOT touch the keyword index: those rows are
// still an accurate reflection of the unchanged subject, and dropping them would make the
// message briefly unsearchable by keyword for no reason until the next background run.
func TestPatchMessageBodyOnlyEditPreservesKeywordIndex(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexBody")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "WANTED: Spindle stem "+prefix, 55.0, -1.0)

	db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)",
		msgID, []byte{0x00}, "test")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_index WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
	})

	var idxBefore, embBefore int64
	db.Raw("SELECT COUNT(*) FROM messages_index WHERE msgid = ?", msgID).Scan(&idxBefore)
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embBefore)
	require.Greater(t, idxBefore, int64(0), "message should be keyword-indexed before the edit")
	require.Equal(t, int64(1), embBefore, "message should have an embedding before the edit")

	// The owner edits the body only — subject is untouched.
	body := fmt.Sprintf(`{"id":%d,"textbody":"Now looking for a Moulinex food processor %s"}`, msgID, prefix)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	var idxAfter, embAfter int64
	db.Raw("SELECT COUNT(*) FROM messages_index WHERE msgid = ?", msgID).Scan(&idxAfter)
	db.Raw("SELECT COUNT(*) FROM messages_embeddings WHERE msgid = ?", msgID).Scan(&embAfter)
	assert.Equal(t, idxBefore, idxAfter, "a body-only edit must not clear the still-valid subject-derived keyword index")
	assert.Equal(t, int64(0), embAfter, "a body-only edit clears the stale vector embedding")
}

// 9954 (adversarial-review follow-up): deleting the messages_embeddings row is necessary
// but not sufficient. apiv2 serves vector search entirely from an in-process store
// (embedding.Global) that Refresh()es on a timer and is presence-keyed, so if the batch
// re-embeds the new content before the store ever observes the msgid as absent, the STALE
// in-memory blob keeps matching the OLD wording (see Store.Refresh's "Known limitation").
// invalidateMessageSearchIndexes therefore also evicts the msgid from the store. This test
// seeds the store with the message's soon-to-be-stale entry, edits it, and asserts the
// entry is gone so the next Refresh reloads the regenerated embedding.
func TestPatchMessageEditEvictsStaleInMemoryEmbedding(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("reindexEvict")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "WANTED: Spindle stem "+prefix, 55.0, -1.0)

	db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)",
		msgID, []byte{0x00}, "test")
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_index WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
	})

	// Seed the in-memory store with this message's (soon-to-be-stale) embedding, mirroring
	// a message embedded before the edit. Reset afterwards, like the vector-search tests.
	embedding.Global.SetEntries([]embedding.Entry{{Msgid: msgID, Groupid: groupID, Msgtype: "Wanted"}})
	defer embedding.Global.SetEntries(nil)
	if _, ok := embedding.Global.FindByMsgid(msgID); !ok {
		t.Fatal("precondition: seeded entry should be in the store")
	}

	newSubject := fmt.Sprintf("WANTED: Spindle stem for Moulinex food processor %s", prefix)
	body := fmt.Sprintf(`{"id":%d,"subject":%q}`, msgID, newSubject)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	_, stillThere := embedding.Global.FindByMsgid(msgID)
	assert.False(t, stillThere, "editing a message must evict its stale embedding from the in-memory store so vector search stops matching the old wording")
}
