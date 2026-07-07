package utils

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestWeightedRandFromSliceEmpty(t *testing.T) {
	// Empty weights → returns 0 and must not panic on rand.Int63n(0).
	assert.Equal(t, 0, weightedRandFromSlice(nil))
	assert.Equal(t, 0, weightedRandFromSlice([]int64{}))
}

func TestWeightedRandFromSliceAllZero(t *testing.T) {
	// All-zero weights → total is 0 → returns 0 without calling rand.
	assert.Equal(t, 0, weightedRandFromSlice([]int64{0, 0, 0}))
}

func TestWeightedRandFromSliceSingle(t *testing.T) {
	// Only one non-zero weight → must always pick that index.
	for i := 0; i < 50; i++ {
		assert.Equal(t, 2, weightedRandFromSlice([]int64{0, 0, 100, 0}))
	}
}

func TestWeightedRandFromSliceInRange(t *testing.T) {
	// With three non-zero buckets, the result must always be a valid index.
	weights := []int64{1, 2, 3}
	for i := 0; i < 50; i++ {
		got := weightedRandFromSlice(weights)
		assert.GreaterOrEqual(t, got, 0)
		assert.Less(t, got, len(weights))
	}
}

func TestWeightedRandFromMapEmpty(t *testing.T) {
	assert.Equal(t, "", weightedRandFromMap(nil))
	assert.Equal(t, "", weightedRandFromMap(map[string]int64{}))
}

func TestWeightedRandFromMapAllZero(t *testing.T) {
	// All-zero weights → returns "" per the total==0 short-circuit.
	assert.Equal(t, "", weightedRandFromMap(map[string]int64{"a": 0, "b": 0}))
}

func TestWeightedRandFromMapSingle(t *testing.T) {
	// Only one non-zero entry → always picks it.
	m := map[string]int64{"alpha": 0, "beta": 100, "gamma": 0}
	for i := 0; i < 50; i++ {
		assert.Equal(t, "beta", weightedRandFromMap(m))
	}
}

func TestFillWordStopsAtLength(t *testing.T) {
	// Trigram chain extends the seed to at least the target length.
	trigrams := map[string]map[string]int64{
		"ab": {"c": 1},
		"bc": {"d": 1},
		"cd": {"e": 1},
		"de": {"f": 1},
	}
	out := fillWord("ab", 5, trigrams)
	assert.GreaterOrEqual(t, len(out), 5)
	// Must start with the seed.
	assert.True(t, strings.HasPrefix(out, "ab"))
}

func TestFillWordStopsOnMissingTrigram(t *testing.T) {
	// If the trigram map has no continuation for the current tail, fillWord
	// returns whatever it has so far rather than looping forever.
	trigrams := map[string]map[string]int64{
		"ab": {"c": 1},
		// "bc" intentionally missing — the chain dies after one step.
	}
	out := fillWord("ab", 100, trigrams)
	assert.Equal(t, "abc", out)
}

func TestFillWordSeedLongerThanTarget(t *testing.T) {
	// If the seed is already >= target length the loop body never runs.
	out := fillWord("already-long", 3, nil)
	assert.Equal(t, "already-long", out)
}

func TestGenerateNameNonEmpty(t *testing.T) {
	// Uses the embedded English trigram data — must return a non-empty string.
	name := GenerateName()
	assert.NotEmpty(t, name)
	// Output is all lowercase (no capitalisation logic in GenerateName).
	assert.Equal(t, strings.ToLower(name), name)
}

func TestGenerateNameReasonableLength(t *testing.T) {
	// Length must be within [4, 10] per the minLen/maxLen constants in GenerateName,
	// unless the fallback "A freegler" kicks in — in which case length is 10.
	for i := 0; i < 20; i++ {
		name := GenerateName()
		if name == "A freegler" {
			continue
		}
		assert.GreaterOrEqual(t, len(name), 4)
		assert.LessOrEqual(t, len(name), 10)
	}
}

func TestInitNamegenIdempotent(t *testing.T) {
	// sync.Once guards initialisation — calling twice must not panic or
	// re-populate from scratch.
	initNamegen()
	initNamegen()
	assert.NotEmpty(t, namegenBigrams, "bigrams must have loaded")
	assert.NotEmpty(t, namegenTrigrams, "trigrams must have loaded")
	assert.NotEmpty(t, namegenWordLengths, "word lengths must have loaded")
}

// ---------------------------------------------------------------------------
// GenerateName fallback paths — manipulate package vars (same package access)
// ---------------------------------------------------------------------------

func TestGenerateName_WordLengthsTooShort(t *testing.T) {
	// Ensure init has run so sync.Once won't overwrite our changes.
	initNamegen()

	orig := namegenWordLengths
	defer func() { namegenWordLengths = orig }()

	// len(namegenWordLengths) <= maxLen(10) triggers the early "A freegler" return.
	namegenWordLengths = []int64{1, 2, 3}
	assert.Equal(t, "A freegler", GenerateName())
}

func TestGenerateName_NilWordLengths(t *testing.T) {
	initNamegen()

	orig := namegenWordLengths
	defer func() { namegenWordLengths = orig }()

	namegenWordLengths = nil
	assert.Equal(t, "A freegler", GenerateName())
}

func TestGenerateName_EmptyBigramsFallback(t *testing.T) {
	// Ensure init has run.
	initNamegen()

	origLengths := namegenWordLengths
	origBigrams := namegenBigrams
	defer func() {
		namegenWordLengths = origLengths
		namegenBigrams = origBigrams
	}()

	// wordLengths must be long enough (> maxLen=10) to pass the first guard,
	// but bigrams must be empty so weightedRandFromMap returns "" → "A freegler".
	namegenWordLengths = make([]int64, 15)
	for i := range namegenWordLengths {
		namegenWordLengths[i] = 1
	}
	namegenBigrams = map[string]int64{}

	assert.Equal(t, "A freegler", GenerateName())
}

// ---------------------------------------------------------------------------
// Dead-code documentation: branches that are mathematically unreachable
// ---------------------------------------------------------------------------

// TestWeightedRandFromSlice_FallbackIsDeadCode documents that the final
// "return len(weights) - 1" in weightedRandFromSlice is unreachable.
// By construction, n = rand.Int63n(total)+1 ∈ [1, total], and after
// subtracting all weights the cumulative total equals 'total', so the loop
// body always fires n <= 0 before or at the last element.
func TestWeightedRandFromSlice_FallbackIsDeadCode(t *testing.T) {
	// Large runs confirm the loop always selects a valid index.
	weights := []int64{1, 1, 1, 1, 1}
	for i := 0; i < 1000; i++ {
		idx := weightedRandFromSlice(weights)
		assert.GreaterOrEqual(t, idx, 0)
		assert.Less(t, idx, len(weights))
	}
}

// TestWeightedRandFromMap_FallbackIsDeadCode documents that the second
// fallback loop and final "return """ in weightedRandFromMap are unreachable
// for the same reason as weightedRandFromSlice.
func TestWeightedRandFromMap_FallbackIsDeadCode(t *testing.T) {
	m := map[string]int64{"a": 1, "b": 2, "c": 3}
	for i := 0; i < 1000; i++ {
		k := weightedRandFromMap(m)
		_, ok := m[k]
		assert.True(t, ok, "unexpected key %q", k)
	}
}
