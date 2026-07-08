package test

import (
	"encoding/binary"
	"fmt"
	"math"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// packSubjectVec little-endian-encodes a subject vector, matching
// EmbeddingService::packVector in iznik-batch and Store.decodeFloats.
func packSubjectVec(v [embedding.EmbeddingDim]float32) []byte {
	buf := make([]byte, embedding.EmbeddingDim*4)
	for i, f := range v {
		binary.LittleEndian.PutUint32(buf[i*4:(i+1)*4], math.Float32bits(f))
	}
	return buf
}

// unitVec returns a normalized test vector, distinct per seed.
func unitVec(seed float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = seed + float32(i)*0.01
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

// createOpenTestMessageWithEmbedding inserts a message, an OPEN messages_spatial
// row (successful=0, promised=0 — the predicate Store.Load/Refresh select on),
// and a messages_embeddings row. Registers cleanup of all three.
func createOpenTestMessageWithEmbedding(t *testing.T, userID, groupID uint64, subject string, lat, lng float64, subjectVec [embedding.EmbeddingDim]float32) uint64 {
	db := database.DBConn

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)

	result := db.Exec("INSERT INTO messages (fromuser, subject, textbody, message, type, locationid, arrival) "+
		"VALUES (?, ?, 'Test message body', 'Test message body', 'Offer', ?, NOW())",
		userID, subject, locationID)
	require.NoError(t, result.Error)

	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? AND subject = ? ORDER BY id DESC LIMIT 1",
		userID, subject).Scan(&msgID)
	require.NotZero(t, msgID, "message was created but ID not found")

	result = db.Exec(fmt.Sprintf("INSERT INTO messages_spatial (msgid, point, successful, promised, groupid, arrival, msgtype) "+
		"VALUES (?, ST_GeomFromText(?, %d), 0, 0, ?, NOW(), 'Offer')", utils.SRID),
		msgID, fmt.Sprintf("POINT(%f %f)", lng, lat), groupID)
	require.NoError(t, result.Error)

	result = db.Exec("INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, 'test')",
		msgID, packSubjectVec(subjectVec))
	require.NoError(t, result.Error)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_embeddings WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})

	return msgID
}

// closeTestMessage flips messages_spatial to "no longer open" (successful=1),
// the same predicate change that removes a message from Store's queries.
func closeTestMessage(t *testing.T, msgID uint64) {
	db := database.DBConn
	result := db.Exec("UPDATE messages_spatial SET successful = 1 WHERE msgid = ?", msgID)
	require.NoError(t, result.Error)
}

// searchByVec runs Store.Search with a query vector equal to the target vector
// (cosine ~1) and reports whether msgID is the top hit.
func searchFindsAsTop(t *testing.T, store *embedding.Store, vec [embedding.EmbeddingDim]float32, msgID uint64) bool {
	results := store.Search(vec[:], 5, "", nil, 0, 0, 0, 0)
	require.NotEmpty(t, results, "expected at least one search result")
	return results[0].Msgid == msgID
}

func TestStoreRefreshAddsNewEmbedding(t *testing.T) {
	prefix := uniquePrefix("storerefreshadd")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")

	vec1 := unitVec(0.3)
	msg1 := createOpenTestMessageWithEmbedding(t, userID, groupID, "First "+prefix, 51.5, -0.1, vec1)

	var store embedding.Store
	require.NoError(t, store.Load())
	require.Equal(t, 1, store.Count())

	// Add a second open message + embedding directly in the DB, bypassing Load.
	vec2 := unitVec(5.0)
	msg2 := createOpenTestMessageWithEmbedding(t, userID, groupID, "Second "+prefix, 51.6, -0.2, vec2)

	require.NoError(t, store.Refresh())
	assert.Equal(t, 2, store.Count())

	assert.True(t, searchFindsAsTop(t, &store, vec1, msg1), "msg1 should still be findable via its own vector")
	assert.True(t, searchFindsAsTop(t, &store, vec2, msg2), "newly-added msg2 should be findable via its own vector")
}

func TestStoreRefreshRemovesClosedMessage(t *testing.T) {
	prefix := uniquePrefix("storerefreshremove")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")

	vec1 := unitVec(0.7)
	vec2 := unitVec(9.0)
	msg1 := createOpenTestMessageWithEmbedding(t, userID, groupID, "Keep "+prefix, 51.5, -0.1, vec1)
	msg2 := createOpenTestMessageWithEmbedding(t, userID, groupID, "Close "+prefix, 51.6, -0.2, vec2)

	var store embedding.Store
	require.NoError(t, store.Load())
	require.Equal(t, 2, store.Count())

	closeTestMessage(t, msg2)

	require.NoError(t, store.Refresh())
	assert.Equal(t, 1, store.Count())

	results := store.Search(vec2[:], 5, "", nil, 0, 0, 0, 0)
	for _, r := range results {
		assert.NotEqual(t, msg2, r.Msgid, "closed message must be removed from the store")
	}
	assert.True(t, searchFindsAsTop(t, &store, vec1, msg1), "msg1 must remain after refresh")
}

func TestStoreRefreshNoChange(t *testing.T) {
	prefix := uniquePrefix("storerefreshnochange")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")

	vec1 := unitVec(1.2)
	createOpenTestMessageWithEmbedding(t, userID, groupID, "Stable "+prefix, 51.5, -0.1, vec1)

	var store embedding.Store
	require.NoError(t, store.Load())
	before := store.Count()
	require.Equal(t, 1, before)

	require.NoError(t, store.Refresh())
	assert.Equal(t, before, store.Count(), "Refresh with no DB change must not alter the store size")
}

// TestStoreRefreshPicksUpRegeneratedEmbedding documents a known limitation:
// messages_embeddings.created_at is DEFAULT CURRENT_TIMESTAMP only (no ON
// UPDATE CURRENT_TIMESTAMP — see the "create_messages_embeddings_table"
// migration), and EmbeddingService::processMessages upserts via INSERT ...
// ON DUPLICATE KEY UPDATE that does not touch created_at. So a regenerated
// blob for an already-loaded msgid is invisible to a created_at-based diff,
// and Refresh() does not pick it up until the store is next fully Load()ed
// (e.g. apiv2 restart, which always follows embeddings:regenerate in
// practice). This test pins that documented behaviour rather than asserting
// the (unimplemented) detection would work.
func TestStoreRefreshPicksUpRegeneratedEmbedding(t *testing.T) {
	prefix := uniquePrefix("storerefreshregenerate")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")

	vecOld := unitVec(2.0)
	msg1 := createOpenTestMessageWithEmbedding(t, userID, groupID, "Regen "+prefix, 51.5, -0.1, vecOld)

	var store embedding.Store
	require.NoError(t, store.Load())
	require.Equal(t, 1, store.Count())

	// Simulate embeddings:regenerate rewriting the blob in place via the same
	// upsert EmbeddingService uses — created_at is NOT part of the SET clause,
	// matching production behaviour.
	vecNew := unitVec(8.0)
	db := database.DBConn
	result := db.Exec(
		`INSERT INTO messages_embeddings (msgid, subject_embedding, model_version)
		 VALUES (?, ?, 'test')
		 ON DUPLICATE KEY UPDATE subject_embedding = VALUES(subject_embedding)`,
		msg1, packSubjectVec(vecNew))
	require.NoError(t, result.Error)

	require.NoError(t, store.Refresh())
	require.Equal(t, 1, store.Count(), "Refresh count is unaffected by an in-place blob regeneration")

	// Documented limitation: the store still serves the OLD vector after
	// Refresh() because msgid was already "have" and created_at didn't change.
	assert.True(t, searchFindsAsTop(t, &store, vecOld, msg1),
		"known limitation: Refresh() does not detect in-place blob regeneration; a full Load() is required")

	// A full Load() (as happens on apiv2 restart) does pick up the new blob.
	require.NoError(t, store.Load())
	assert.True(t, searchFindsAsTop(t, &store, vecNew, msg1),
		"a full Load() must pick up the regenerated blob")
}

func TestStoreRefreshFallsBackToLoadWhenEmpty(t *testing.T) {
	prefix := uniquePrefix("storerefreshempty")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "Member")

	vec1 := unitVec(3.3)
	createOpenTestMessageWithEmbedding(t, userID, groupID, "Bootstrap "+prefix, 51.5, -0.1, vec1)

	var store embedding.Store
	// Store starts empty (Count()==0): Refresh must behave like Load().
	require.Equal(t, 0, store.Count())
	require.NoError(t, store.Refresh())
	assert.GreaterOrEqual(t, store.Count(), 1)
}
