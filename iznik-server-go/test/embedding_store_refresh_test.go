package test

import (
	"encoding/binary"
	"math"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
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
