package embedding

import (
	"math"
	"testing"
)

// DecodeVector reads an embedding BLOB straight from the DB, so it is the guard
// between a malformed/truncated column and the similarity maths. The size check
// is the whole safety property: without it a short BLOB would be read past its
// end.

func TestDecodeVector_RoundTripsAnEncodedVector(t *testing.T) {
	var src [EmbeddingDim]float32
	for i := range src {
		src[i] = float32(i) * 0.25
	}

	got, err := DecodeVector(vecToBytes(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(got) != EmbeddingDim {
		t.Fatalf("got %d values, want %d", len(got), EmbeddingDim)
	}
	for i := range src {
		if got[i] != src[i] {
			t.Fatalf("value %d: got %v, want %v", i, got[i], src[i])
		}
	}
}

func TestDecodeVector_RejectsWrongSizes(t *testing.T) {
	for _, size := range []int{
		0,
		1,
		EmbeddingDim*4 - 1, // one byte short
		EmbeddingDim*4 + 1, // one byte long
		EmbeddingDim * 2,   // float16-sized
		EmbeddingDim,       // dimension count mistaken for byte count
	} {
		if _, err := DecodeVector(make([]byte, size)); err == nil {
			t.Errorf("expected an error for a %d-byte blob, got none", size)
		}
	}
}

func TestDecodeVector_RejectsNil(t *testing.T) {
	if _, err := DecodeVector(nil); err == nil {
		t.Error("expected an error for a nil blob, got none")
	}
}

func TestDecodeVector_PreservesNegativeAndFractionalValues(t *testing.T) {
	// Embeddings are signed and mostly fractional, so a decoder that mangled
	// sign or precision would still pass a zeros-only test.
	var src [EmbeddingDim]float32
	for i := range src {
		src[i] = float32(math.Sin(float64(i)))
	}

	got, err := DecodeVector(vecToBytes(src))
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	for i := range src {
		if got[i] != src[i] {
			t.Fatalf("value %d: got %v, want %v", i, got[i], src[i])
		}
	}
}
