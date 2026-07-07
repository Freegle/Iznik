package test

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestSidecarEmbedQuerySuccess(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	vec := make([]float32, embedding.EmbeddingDim)
	for i := range vec {
		vec[i] = float32(i) * 0.001
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/embed", r.URL.Path)
		assert.Equal(t, "POST", r.Method)

		type req struct {
			Texts []string `json:"texts"`
		}
		var body req
		err := json.NewDecoder(r.Body).Decode(&body)
		require.NoError(t, err)
		assert.Equal(t, []string{"sofa"}, body.Texts)

		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{vec}})
	}))
	defer server.Close()

	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	result, err := embedding.EmbedQuery("sofa")
	require.NoError(t, err)
	assert.Len(t, result, embedding.EmbeddingDim)
	assert.InDelta(t, 0.001, result[1], 1e-6)
}

func TestSidecarEmbedQueryServerError(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`{"error":"model not loaded"}`))
	}))
	defer server.Close()

	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	_, err := embedding.EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar returned 500")
}

func TestSidecarEmbedQueryEmptyEmbeddings(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{}})
	}))
	defer server.Close()

	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	_, err := embedding.EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "no embeddings returned")
}

func TestSidecarEmbedQueryConnectionError(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	embedding.SetSidecarURL(url)
	defer embedding.SetSidecarURL("")

	_, err := embedding.EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar request")
}

func TestSidecarEmbedQueryBadJSON(t *testing.T) {
	embedding.ResetQueryCache()
	t.Cleanup(embedding.ResetQueryCache)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`not json`))
	}))
	defer server.Close()

	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	_, err := embedding.EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "unmarshal")
}
