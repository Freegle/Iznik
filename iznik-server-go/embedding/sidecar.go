package embedding

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

var sidecarURL string

func init() {
	sidecarURL = os.Getenv("EMBEDDING_SIDECAR_URL")
	if sidecarURL == "" {
		sidecarURL = "http://embedding-sidecar:3200"
	}
}

type embedRequest struct {
	Texts []string `json:"texts"`
}

type embedResponse struct {
	Embeddings [][]float32 `json:"embeddings"`
	Error      string      `json:"error,omitempty"`
}

var client = &http.Client{Timeout: 10 * time.Second}

// SetSidecarURL overrides the sidecar URL (for testing).
func SetSidecarURL(url string) {
	if url == "" {
		url = "http://embedding-sidecar:3200"
	}
	sidecarURL = url
}

// EmbedBatch embeds multiple texts. Uses the native ONNX pool when available
// (EMBEDDING_ONNX_MODEL_PATH + EMBEDDING_TOKENIZER_PATH are set), otherwise
// calls the HTTP sidecar.
func EmbedBatch(texts []string) ([][]float32, error) {
	if len(texts) == 0 {
		return nil, nil
	}

	if vecs, err := embedBatchNative(texts); vecs != nil || err != nil {
		return vecs, err
	}

	return embedBatchViaSidecar(texts)
}

// EmbedQuery embeds a single search query. Uses the native ONNX pool when
// available, otherwise calls the HTTP sidecar.
func EmbedQuery(text string) ([]float32, error) {
	if vec, err := embedQueryNative(text); vec != nil || err != nil {
		return vec, err
	}

	return embedQueryViaSidecar(text)
}

func embedQueryViaSidecar(text string) ([]float32, error) {
	vecs, err := embedBatchViaSidecar([]string{text})
	if err != nil {
		return nil, err
	}
	if len(vecs) == 0 {
		return nil, fmt.Errorf("no embeddings returned")
	}
	return vecs[0], nil
}

func embedBatchViaSidecar(texts []string) ([][]float32, error) {
	body, err := json.Marshal(embedRequest{Texts: texts})
	if err != nil {
		return nil, fmt.Errorf("marshal: %w", err)
	}

	resp, err := client.Post(sidecarURL+"/embed", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("sidecar request: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}

	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("sidecar returned %d: %s", resp.StatusCode, string(respBody))
	}

	var result embedResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, fmt.Errorf("unmarshal: %w", err)
	}

	return result.Embeddings, nil
}
