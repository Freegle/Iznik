//go:build embed_native

package embedding

import (
	"os"
	"testing"
)

// BenchmarkEmbedViaSidecar measures the full round-trip: Go HTTP client →
// sidecar (JSON encode + TCP + ONNX inference + JSON decode).
// Run with: go test -bench=BenchmarkEmbedViaSidecar -benchtime=20s ./embedding/
func BenchmarkEmbedViaSidecar(b *testing.B) {
	if os.Getenv("EMBEDDING_SIDECAR_URL") == "" {
		b.Skip("EMBEDDING_SIDECAR_URL not set")
	}
	const text = "sofa in good condition, collection from Hackney"
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		vec, err := embedQueryViaSidecar(text)
		if err != nil {
			b.Fatalf("sidecar embed: %v", err)
		}
		if len(vec) != EmbeddingDim {
			b.Fatalf("unexpected dim: %d", len(vec))
		}
	}
}

// BenchmarkEmbedNative measures the native pool path: tokenise → ONNX inference
// (parallel GEMM via intra_op_num_threads) → mean pool → normalise.
// Run with: go test -bench=BenchmarkEmbedNative -benchtime=20s ./embedding/
func BenchmarkEmbedNative(b *testing.B) {
	modelPath := os.Getenv("EMBEDDING_ONNX_MODEL_PATH")
	tokenizerPath := os.Getenv("EMBEDDING_TOKENIZER_PATH")
	if modelPath == "" || tokenizerPath == "" {
		b.Skip("EMBEDDING_ONNX_MODEL_PATH / EMBEDDING_TOKENIZER_PATH not set")
	}
	const text = "sofa in good condition, collection from Hackney"
	// Ensure pool is initialised before timing.
	nativeOnce.Do(initNativePool)
	if nativePool == nil {
		b.Fatal("native pool failed to initialise")
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		vec, err := nativePool.embed("search_query: " + text)
		if err != nil {
			b.Fatalf("native embed: %v", err)
		}
		if len(vec) != EmbeddingDim {
			b.Fatalf("unexpected dim: %d", len(vec))
		}
	}
}

// BenchmarkEmbedNativeParallel shows throughput when multiple goroutines each
// hold a session from the pool simultaneously.
func BenchmarkEmbedNativeParallel(b *testing.B) {
	modelPath := os.Getenv("EMBEDDING_ONNX_MODEL_PATH")
	tokenizerPath := os.Getenv("EMBEDDING_TOKENIZER_PATH")
	if modelPath == "" || tokenizerPath == "" {
		b.Skip("EMBEDDING_ONNX_MODEL_PATH / EMBEDDING_TOKENIZER_PATH not set")
	}
	nativeOnce.Do(initNativePool)
	if nativePool == nil {
		b.Fatal("native pool failed to initialise")
	}
	const text = "bike for sale, good condition"
	b.ResetTimer()
	b.RunParallel(func(pb *testing.PB) {
		for pb.Next() {
			if _, err := nativePool.embed("search_query: " + text); err != nil {
				b.Error(err)
			}
		}
	})
}
