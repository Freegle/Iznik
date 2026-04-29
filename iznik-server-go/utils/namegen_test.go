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
