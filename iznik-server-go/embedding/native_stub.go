//go:build !embed_native

package embedding

// Stub implementations used when the embed_native build tag is absent.
// EmbedQuery and EmbedBatch fall through to the HTTP sidecar in sidecar.go.

func embedQueryNative(_ string) ([]float32, error)       { return nil, nil }
func embedBatchNative(_ []string) ([][]float32, error)   { return nil, nil }
