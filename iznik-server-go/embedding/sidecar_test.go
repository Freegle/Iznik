package embedding

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestEmbedQuerySuccess(t *testing.T) {
	// Create a mock sidecar that returns a valid embedding
	vec := make([]float32, EmbeddingDim)
	for i := range vec {
		vec[i] = float32(i) * 0.001
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/embed", r.URL.Path)
		assert.Equal(t, "POST", r.Method)
		assert.Equal(t, "application/json", r.Header.Get("Content-Type"))

		var req embedRequest
		err := json.NewDecoder(r.Body).Decode(&req)
		require.NoError(t, err)
		assert.Equal(t, []string{"sofa"}, req.Texts)

		resp := embedResponse{Embeddings: [][]float32{vec}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	result, err := EmbedQuery("sofa")
	require.NoError(t, err)
	assert.Len(t, result, EmbeddingDim)
	assert.InDelta(t, 0.001, result[1], 1e-6)
}

func TestEmbedQueryServerError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`{"error":"model not loaded"}`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar returned 500")
}

func TestEmbedQueryEmptyEmbeddings(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := embedResponse{Embeddings: [][]float32{}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "no embeddings returned")
}

func TestEmbedQueryConnectionError(t *testing.T) {
	// Start and immediately close to get a guaranteed-refused port
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	origURL := sidecarURL
	sidecarURL = url
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar request")
}

func TestEmbedQueryBadJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`not json`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "unmarshal")
}

func TestSetSidecarURL(t *testing.T) {
	origURL := sidecarURL
	defer func() { sidecarURL = origURL }()

	// Explicit URL is used as-is.
	SetSidecarURL("http://custom-sidecar:1234")
	assert.Equal(t, "http://custom-sidecar:1234", sidecarURL)

	// Empty string falls back to the default.
	SetSidecarURL("")
	assert.Equal(t, "http://embedding-sidecar:3200", sidecarURL)
}

func TestEmbedBatchEmptyInput(t *testing.T) {
	// Zero texts must short-circuit — no HTTP call, nil result, no error.
	// Using an invalid URL proves no request was made.
	origURL := sidecarURL
	sidecarURL = "http://127.0.0.1:1"
	defer func() { sidecarURL = origURL }()

	result, err := EmbedBatch(nil)
	assert.NoError(t, err)
	assert.Nil(t, result)

	result, err = EmbedBatch([]string{})
	assert.NoError(t, err)
	assert.Nil(t, result)
}

func TestEmbedBatchSuccess(t *testing.T) {
	vec1 := make([]float32, EmbeddingDim)
	vec2 := make([]float32, EmbeddingDim)
	for i := range vec1 {
		vec1[i] = float32(i) * 0.001
		vec2[i] = float32(i) * 0.002
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/embed", r.URL.Path)
		assert.Equal(t, "POST", r.Method)

		var req embedRequest
		err := json.NewDecoder(r.Body).Decode(&req)
		require.NoError(t, err)
		assert.Equal(t, []string{"sofa", "chair"}, req.Texts)

		resp := embedResponse{Embeddings: [][]float32{vec1, vec2}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	result, err := EmbedBatch([]string{"sofa", "chair"})
	require.NoError(t, err)
	assert.Len(t, result, 2)
	assert.Len(t, result[0], EmbeddingDim)
	assert.Len(t, result[1], EmbeddingDim)
	assert.InDelta(t, 0.002, result[1][1], 1e-6)
}

func TestEmbedBatchServerError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(503)
		w.Write([]byte(`{"error":"overloaded"}`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedBatch([]string{"sofa"})
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar returned 503")
}

func TestEmbedBatchConnectionError(t *testing.T) {
	// Start and immediately close to get a guaranteed-refused port.
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	origURL := sidecarURL
	sidecarURL = url
	defer func() { sidecarURL = origURL }()

	_, err := EmbedBatch([]string{"sofa"})
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar request")
}

func TestEmbedBatchBadJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`<html>oops</html>`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedBatch([]string{"sofa"})
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "unmarshal")
}
