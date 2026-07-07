//go:build embed_native

package embedding

// Native Go embedding using ONNX Runtime directly.
//
// Parallel algorithm: the ONNX C runtime tiles each matrix-multiply (GEMM)
// across N OS threads (SetIntraOpNumThreads) and runs independent graph nodes
// — the Q, K, V projections in every transformer layer — concurrently
// (SetInterOpNumThreads). This reduces single-inference latency proportional
// to available cores and eliminates the HTTP round-trip to the Node sidecar.
//
// Model discovery: auto-discovers nomic-embed-text-v1.5 in the HuggingFace
// cache at EMBEDDING_MODEL_CACHE_DIR (default /app/model-cache, shared volume
// with embedding-sidecar). Override with EMBEDDING_ONNX_MODEL_PATH and
// EMBEDDING_TOKENIZER_PATH for a non-standard layout.

import (
	"fmt"
	"math"
	"os"
	"path/filepath"
	"runtime"
	"sync"
	"sync/atomic"

	tk "github.com/daulet/tokenizers"
	ort "github.com/yalue/onnxruntime_go"
)

const defaultModelCacheDir = "/app/model-cache"

// _nativePool is set once when the model files are first found on disk.
// Reads use Load() with no lock; writes go through nativePoolMu.
var _nativePool atomic.Pointer[NativePool]
var nativePoolMu sync.Mutex
var ortInitOnce sync.Once // ort.InitializeEnvironment must be called exactly once

// NativePool holds N independent ONNX sessions plus one tokenizer.
// Sessions sit in a buffered channel: each concurrent EmbedQuery call grabs
// its own session, runs inference (parallelised internally by the C runtime),
// then returns the session to the pool. The pool size equals NumCPU so N
// simultaneous searches each get a session without queuing.
type NativePool struct {
	sessions  chan *ort.DynamicAdvancedSession
	tokenizer *tk.Tokenizer
}

// discoverModelPaths finds nomic-embed-text-v1.5 files in the HuggingFace
// transformers cache layout: {cacheDir}/models--nomic-ai--nomic-embed-text-v1.5/snapshots/{hash}/...
func discoverModelPaths(cacheDir string) (modelPath, tokenizerPath string) {
	base := filepath.Join(cacheDir, "models--nomic-ai--nomic-embed-text-v1.5", "snapshots")
	if models, _ := filepath.Glob(filepath.Join(base, "*", "onnx", "model_quantized.onnx")); len(models) > 0 {
		modelPath = models[0]
	}
	if tokenizers, _ := filepath.Glob(filepath.Join(base, "*", "tokenizer.json")); len(tokenizers) > 0 {
		tokenizerPath = tokenizers[0]
	}
	return
}

// resolveModelPaths returns the model and tokenizer paths, from explicit env
// vars or by discovering them in the HuggingFace cache dir.
func resolveModelPaths() (modelPath, tokenizerPath string) {
	modelPath = os.Getenv("EMBEDDING_ONNX_MODEL_PATH")
	tokenizerPath = os.Getenv("EMBEDDING_TOKENIZER_PATH")
	if modelPath != "" && tokenizerPath != "" {
		return
	}
	cacheDir := os.Getenv("EMBEDDING_MODEL_CACHE_DIR")
	if cacheDir == "" {
		cacheDir = defaultModelCacheDir
	}
	return discoverModelPaths(cacheDir)
}

// getNativePool returns the initialised pool, or nil if the model files are
// not yet present. It retries discovery on every call until the pool is ready,
// so it self-activates once the embedding-sidecar has downloaded the model.
func getNativePool() *NativePool {
	// Fast path: pool already initialised.
	if p := _nativePool.Load(); p != nil {
		return p
	}
	// Slow path: attempt initialisation under lock.
	nativePoolMu.Lock()
	defer nativePoolMu.Unlock()
	if p := _nativePool.Load(); p != nil {
		return p // another goroutine beat us
	}
	modelPath, tokenizerPath := resolveModelPaths()
	if modelPath == "" || tokenizerPath == "" {
		return nil // files not downloaded yet; caller falls back to HTTP sidecar
	}
	var ortErr error
	ortInitOnce.Do(func() { ortErr = ort.InitializeEnvironment() })
	if ortErr != nil {
		fmt.Printf("WARNING: ORT init failed: %v — using HTTP sidecar\n", ortErr)
		return nil
	}
	pool, err := newNativePool(modelPath, tokenizerPath)
	if err != nil {
		fmt.Printf("WARNING: native embedding pool init failed: %v — using HTTP sidecar\n", err)
		return nil
	}
	_nativePool.Store(pool)
	fmt.Printf("Native embedding pool ready (sessions=%d, threads=%d, model=%s)\n",
		cap(pool.sessions), runtime.NumCPU(), modelPath)
	return pool
}

func newNativePool(modelPath, tokenizerPath string) (*NativePool, error) {
	opts, err := ort.NewSessionOptions()
	if err != nil {
		return nil, fmt.Errorf("session options: %w", err)
	}
	n := runtime.NumCPU()
	// Constant folding, node fusion (LayerNorm, attention), dead node elimination.
	if err := opts.SetSessionGraphOptimizationLevel(ort.OrtEnableAll); err != nil {
		return nil, fmt.Errorf("SetSessionGraphOptimizationLevel: %w", err)
	}
	// THE PARALLEL ALGORITHM: tile each GEMM matmul across N threads,
	// reducing per-inference latency by ~N× on CPU.
	if err := opts.SetIntraOpNumThreads(n); err != nil {
		return nil, fmt.Errorf("SetIntraOpNumThreads: %w", err)
	}
	// Run Q, K, V projections (independent graph nodes) simultaneously.
	if err := opts.SetInterOpNumThreads(n); err != nil {
		return nil, fmt.Errorf("SetInterOpNumThreads: %w", err)
	}

	poolSize := n
	sessions := make(chan *ort.DynamicAdvancedSession, poolSize)
	for i := 0; i < poolSize; i++ {
		sess, err := ort.NewDynamicAdvancedSession(
			modelPath,
			[]string{"input_ids", "attention_mask", "token_type_ids"},
			[]string{"last_hidden_state"},
			opts,
		)
		if err != nil {
			return nil, fmt.Errorf("create session %d: %w", i, err)
		}
		sessions <- sess
	}

	tokenizer, err := tk.FromFile(tokenizerPath)
	if err != nil {
		return nil, fmt.Errorf("tokenizer: %w", err)
	}

	return &NativePool{sessions: sessions, tokenizer: tokenizer}, nil
}

// embedQueryNative returns nil, nil when the pool is not yet available.
func embedQueryNative(text string) ([]float32, error) {
	p := getNativePool()
	if p == nil {
		return nil, nil
	}
	return p.embed("search_query: " + text)
}

// embedBatchNative returns nil, nil when the pool is not yet available.
func embedBatchNative(texts []string) ([][]float32, error) {
	p := getNativePool()
	if p == nil {
		return nil, nil
	}
	results := make([][]float32, len(texts))
	for i, t := range texts {
		v, err := p.embed("search_document: " + t)
		if err != nil {
			return nil, err
		}
		results[i] = v
	}
	return results, nil
}

// embed tokenises text, runs ONNX inference (using a pooled session with
// parallel GEMM threads), mean-pools the hidden states, and returns a
// 256-dim L2-normalised float32 vector matching the sidecar's output.
func (p *NativePool) embed(text string) ([]float32, error) {
	// Use the Rust HuggingFace tokenizers library (same code the Node sidecar
	// uses) so token IDs are byte-for-byte identical to the document embeddings.
	enc, err := p.tokenizer.EncodeWithOptionsErr(text, true,
		tk.WithReturnAttentionMask(),
		tk.WithReturnTypeIDs(),
	)
	if err != nil {
		return nil, fmt.Errorf("tokenize: %w", err)
	}

	seqLen := len(enc.IDs)
	if seqLen == 0 {
		return nil, fmt.Errorf("empty token sequence")
	}

	// Convert uint32 token fields to int64 as the ONNX model expects.
	idsI64 := make([]int64, seqLen)
	maskI64 := make([]int64, seqLen)
	typeI64 := make([]int64, seqLen)
	for i := range enc.IDs {
		idsI64[i] = int64(enc.IDs[i])
		maskI64[i] = int64(enc.AttentionMask[i])
		typeI64[i] = int64(enc.TypeIDs[i])
	}

	shape := ort.NewShape(1, int64(seqLen))

	idsTensor, err := ort.NewTensor(shape, idsI64)
	if err != nil {
		return nil, fmt.Errorf("ids tensor: %w", err)
	}
	defer idsTensor.Destroy()

	maskTensor, err := ort.NewTensor(shape, maskI64)
	if err != nil {
		return nil, fmt.Errorf("mask tensor: %w", err)
	}
	defer maskTensor.Destroy()

	typeTensor, err := ort.NewTensor(shape, typeI64)
	if err != nil {
		return nil, fmt.Errorf("type tensor: %w", err)
	}
	defer typeTensor.Destroy()

	// Output tensor: last_hidden_state shape [1, seqLen, 768].
	outData := make([]float32, seqLen*768)
	outTensor, err := ort.NewTensor(ort.NewShape(1, int64(seqLen), 768), outData)
	if err != nil {
		return nil, fmt.Errorf("output tensor: %w", err)
	}
	defer outTensor.Destroy()

	// Grab a session from the pool.
	sess := <-p.sessions
	defer func() { p.sessions <- sess }()

	// Run fills outTensor in place; no return value for the tensor data.
	if err := sess.Run(
		[]ort.Value{idsTensor, maskTensor, typeTensor},
		[]ort.Value{outTensor},
	); err != nil {
		return nil, fmt.Errorf("ort run: %w", err)
	}

	hidden := outTensor.GetData() // []float32, len = seqLen*768

	// Mean-pool over non-padding token positions (attention_mask == 1).
	pooled := make([]float32, 768)
	var count float32
	for pos := 0; pos < seqLen; pos++ {
		if enc.AttentionMask[pos] == 0 {
			continue
		}
		count++
		base := pos * 768
		for d := 0; d < 768; d++ {
			pooled[d] += hidden[base+d]
		}
	}
	if count > 0 {
		for d := range pooled {
			pooled[d] /= count
		}
	}

	// Matryoshka truncation: first 256 dims retain most semantic content.
	truncated := pooled[:EmbeddingDim]

	// Re-normalise after truncation (mirrors the step in server.mjs).
	var norm float64
	for _, v := range truncated {
		norm += float64(v) * float64(v)
	}
	norm = math.Sqrt(norm)
	if norm == 0 {
		return nil, fmt.Errorf("zero-norm embedding")
	}
	result := make([]float32, EmbeddingDim)
	for i, v := range truncated {
		result[i] = float32(float64(v) / norm)
	}
	return result, nil
}
