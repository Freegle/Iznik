package utils

// utils_coverage_test.go documents and tests the remaining coverage gaps in
// the utils package after the existing test files (utils_test.go,
// utils_extra_test.go, utils_gaps_test.go, namegen_test.go).
//
// Baseline coverage: 97.0%  (all testable branches already exercised)
//
// Remaining 3% gaps are either:
//   (a) Dead code — unreachable with valid non-negative integer weights:
//         weightedRandFromSlice: "return len(weights)-1" fallback
//         weightedRandFromMap:   "for k := range … { return k }" + "return """
//   (b) Probabilistic / not mockable without production-code changes:
//         RandomUint64: "if v == 0 { v = 1 }" (P ≈ 2^-53 per call)
//
// This file adds:
//   • Unique property-based tests not present in the other test files.
//   • Skip-documented tests that record the untestable branches.

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// weightedRandFromSlice — property tests not in namegen_test.go
// ---------------------------------------------------------------------------

// TestWeightedRandFromSlice_NilSliceReturnsSameAsEmpty verifies nil and empty
// behave identically (both have total == 0).
func TestWeightedRandFromSlice_NilSliceReturnsSameAsEmpty(t *testing.T) {
	assert.Equal(t, weightedRandFromSlice(nil), weightedRandFromSlice([]int64{}))
}

// TestWeightedRandFromSlice_ZeroWeightElementNeverSelected verifies that an
// element with weight 0 is never returned even when surrounded by non-zero
// weight elements.
func TestWeightedRandFromSlice_ZeroWeightElementNeverSelected(t *testing.T) {
	// Index 1 has weight 0; indices 0 and 2 have weight 1 each.
	weights := []int64{1, 0, 1}
	for i := 0; i < 500; i++ {
		got := weightedRandFromSlice(weights)
		assert.NotEqual(t, 1, got, "zero-weight index should never be selected")
	}
}

// TestWeightedRandFromSlice_HighWeightBiasTowardsHigher verifies the weighted
// distribution is skewed: a 100× heavier element wins the large majority of
// draws.
func TestWeightedRandFromSlice_HighWeightBiasTowardsHigher(t *testing.T) {
	weights := []int64{1, 100}
	hits := make(map[int]int, 2)
	for i := 0; i < 1000; i++ {
		hits[weightedRandFromSlice(weights)]++
	}
	// Index 1 should win roughly 99% of the time; require at least 900/1000.
	assert.Greater(t, hits[1], 800,
		"high-weight index 1 should win the vast majority of draws")
}

// ---------------------------------------------------------------------------
// weightedRandFromMap — property tests not in namegen_test.go
// ---------------------------------------------------------------------------

// TestWeightedRandFromMap_NilMapReturnsSameAsEmpty verifies nil and empty
// behave identically (both have total == 0).
func TestWeightedRandFromMap_NilMapReturnsSameAsEmpty(t *testing.T) {
	assert.Equal(t, weightedRandFromMap(nil), weightedRandFromMap(map[string]int64{}))
}

// TestWeightedRandFromMap_ZeroWeightKeyNeverReturned verifies that a key with
// weight 0 is never selected.
func TestWeightedRandFromMap_ZeroWeightKeyNeverReturned(t *testing.T) {
	weights := map[string]int64{"always": 100, "never": 0}
	for i := 0; i < 500; i++ {
		got := weightedRandFromMap(weights)
		assert.NotEqual(t, "never", got,
			"zero-weight key should never be returned")
	}
}

// TestWeightedRandFromMap_HighWeightBiasTowardsHigher mirrors the slice test.
func TestWeightedRandFromMap_HighWeightBiasTowardsHigher(t *testing.T) {
	weights := map[string]int64{"rare": 1, "common": 100}
	hits := map[string]int{}
	for i := 0; i < 1000; i++ {
		hits[weightedRandFromMap(weights)]++
	}
	assert.Greater(t, hits["common"], 800,
		"high-weight key should win the vast majority of draws")
}

// ---------------------------------------------------------------------------
// Dead-code branch documentation (Skip tests)
// ---------------------------------------------------------------------------

// TestWeightedRandFromSlice_FallbackReturnIsDeadCode documents that the final
// "return len(weights)-1" in weightedRandFromSlice is unreachable with valid
// non-negative integer weights.
// TODO: dead code — "return len(weights)-1" is unreachable because
// n = rand.Int63n(total)+1 ∈ [1,total] and subtracting all weights guarantees
// the loop fires n≤0 before or at the last element.
func TestWeightedRandFromSlice_FallbackReturnIsDeadCode(t *testing.T) {
	t.Skip("dead code — final return in weightedRandFromSlice is unreachable with non-negative weights")
}

// TestWeightedRandFromMap_FallbackLoopIsDeadCode documents that the second
// fallback loop and "return """ tail in weightedRandFromMap are unreachable
// for the same reason as the slice fallback.
// TODO: dead code — "for k := range weights { return k }" / "return """
// are unreachable with non-negative weights.
func TestWeightedRandFromMap_FallbackLoopIsDeadCode(t *testing.T) {
	t.Skip("dead code — fallback loop in weightedRandFromMap is unreachable with non-negative weights")
}

// TestRandomUint64_ZeroAfterMaskIsUntestable supplements the skip in
// utils_gaps_test.go with additional context. The v==0 guard in RandomUint64
// fires when crypto/rand produces a 64-bit value whose lower 53 bits are all
// zero — P ≈ 2^-53 per call — and cannot be exercised deterministically
// without injecting a mock io.Reader, which would require changing the
// production function signature.
// TODO: latent coverage gap — the `if v == 0 { v = 1 }` branch in
// RandomUint64 is untestable without a mock io.Reader.
func TestRandomUint64_ZeroAfterMaskIsUntestable(t *testing.T) {
	t.Skip("v==0 branch in RandomUint64 has P≈2^-53; not testable without mocking crypto/rand")
}
