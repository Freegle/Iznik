package embedding

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestQueryCacheHitAvoidsSidecarCall(t *testing.T) {
	ResetQueryCache()
	t.Cleanup(ResetQueryCache)

	callCount := 0
	vec := make([]float32, EmbeddingDim)
	for i := range vec {
		vec[i] = float32(i) * 0.001
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		resp := embedResponse{Embeddings: [][]float32{vec}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	// First call: cache miss — sidecar is hit.
	r1, err := EmbedQuery("sofa")
	require.NoError(t, err)
	assert.Equal(t, 1, callCount)

	// Second call: cache hit — sidecar must NOT be called again.
	r2, err := EmbedQuery("sofa")
	require.NoError(t, err)
	assert.Equal(t, 1, callCount, "sidecar should not be called on cache hit")
	assert.Equal(t, r1, r2)
}

func TestQueryCacheDifferentTexts(t *testing.T) {
	ResetQueryCache()
	t.Cleanup(ResetQueryCache)

	callCount := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		var req embedRequest
		json.NewDecoder(r.Body).Decode(&req)
		// Return a unique vector per text so we can distinguish them.
		v := make([]float32, EmbeddingDim)
		v[0] = float32(callCount)
		resp := embedResponse{Embeddings: [][]float32{v}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	r1, err := EmbedQuery("sofa")
	require.NoError(t, err)
	r2, err := EmbedQuery("bike")
	require.NoError(t, err)

	assert.Equal(t, 2, callCount)
	assert.NotEqual(t, r1[0], r2[0], "different texts must return different vectors")

	// Both should now be cached — no further sidecar calls.
	_, err = EmbedQuery("sofa")
	require.NoError(t, err)
	_, err = EmbedQuery("bike")
	require.NoError(t, err)
	assert.Equal(t, 2, callCount)
}

func TestQueryCacheLRUEviction(t *testing.T) {
	// Build a size-2 cache directly to test eviction without 10k entries.
	c := newLRUCache(2)

	v1 := []float32{1}
	v2 := []float32{2}
	v3 := []float32{3}

	c.set("a", v1)
	c.set("b", v2)

	// Access "a" to make it MRU; "b" becomes LRU.
	_, ok := c.get("a")
	assert.True(t, ok)

	// Adding "c" should evict "b" (LRU).
	c.set("c", v3)

	_, okB := c.get("b")
	assert.False(t, okB, "b should have been evicted")

	gotA, okA := c.get("a")
	assert.True(t, okA)
	assert.Equal(t, v1, gotA)

	gotC, okC := c.get("c")
	assert.True(t, okC)
	assert.Equal(t, v3, gotC)
}

func TestQueryCacheZeroSizeDisablesCache(t *testing.T) {
	c := newLRUCache(0)
	c.set("key", []float32{1, 2, 3})
	_, ok := c.get("key")
	assert.False(t, ok, "size-0 cache should never store entries")
}

func TestQueryCacheReset(t *testing.T) {
	c := newLRUCache(10)
	c.set("x", []float32{1})
	c.reset()
	_, ok := c.get("x")
	assert.False(t, ok, "reset cache must be empty")
}

func TestQueryCacheConcurrentSafe(t *testing.T) {
	c := newLRUCache(100)
	const goroutines = 20
	const ops = 500

	var wg sync.WaitGroup
	wg.Add(goroutines)
	for i := 0; i < goroutines; i++ {
		go func(id int) {
			defer wg.Done()
			for j := 0; j < ops; j++ {
				key := "key"
				c.set(key, []float32{float32(id)})
				c.get(key)
			}
		}(i)
	}
	wg.Wait()
	// If we reach here without a data race (run with -race), the test passes.
}
