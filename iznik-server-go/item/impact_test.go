package item

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// Pure-logic tests for the word-similarity helpers ported from
// ItemService.php. None of these touch the database - they exercise
// canonWord/canonSentence/wordsInCommon/estimateWeightFromStandardWeights
// directly against hand-computed expectations.

func TestCanonWordShortWordUnchanged(t *testing.T) {
	// <=3 chars: no letter-sorting, just lowercase + strip punctuation.
	assert.Equal(t, "bed", canonWord("Bed"))
	assert.Equal(t, "big", canonWord("Big!!"))
	assert.Equal(t, "2", canonWord("2"))
}

func TestCanonWordLongWordSortsLetters(t *testing.T) {
	// >3 chars: lowercase, strip punctuation, then sort letters alphabetically
	// (anagram/typo tolerance). "sofa" -> s,o,f,a -> sorted -> "afos".
	assert.Equal(t, "afos", canonWord("Sofa"))
	assert.Equal(t, "afos", canonWord("sofa"))
	// An anagram of "sofa" canonicalises to the same value.
	assert.Equal(t, "afos", canonWord("Foas"))
}

func TestCanonWordStripsNonAlphanumericButKeepsDigits(t *testing.T) {
	// Digits are kept ([^0-9a-z] is stripped, digits are not in that class),
	// and mixed alnum >3 chars still gets letter-sorted (ASCII order, so
	// digits sort before letters).
	assert.Equal(t, "2achirs", canonWord("chairs2"))

	// Punctuation/apostrophes are stripped entirely, then the remaining
	// letters are sorted: "Sofa-Bed's!" -> "sofabeds" -> sorted -> "abdefoss".
	assert.Equal(t, "abdefoss", canonWord("Sofa-Bed's!"))
}

func TestCanonSentenceSplitsOnVerticalTab(t *testing.T) {
	// PHP's preg_split('/\s+/', ...) uses PCRE's default \s, which includes
	// vertical tab (0x0B) - unlike Go RE2's \s. wordSplitRe must split on it
	// too, so "foo\x0bbar" tokenises the same way as the PHP original does:
	// as two words, not one.
	words := canonSentence("foo\x0bbar")
	assert.Equal(t, []string{"foo", "bar"}, words)
}

func TestWordsInCommonMatchingWord(t *testing.T) {
	// "Big Comfy Sofa" canonicalises to {big, cfmoy, afos}; "sofa" to {afos}.
	// 1 common word out of a longer list of 3 -> 100/3 = 33.33%.
	score := wordsInCommon("Big Comfy Sofa", "sofa")
	assert.InDelta(t, 100.0/3.0, score, 0.001)
}

func TestWordsInCommonNoOverlap(t *testing.T) {
	assert.Equal(t, float64(0), wordsInCommon("Xylophone", "sofa"))
}

func TestWordsInCommonSingleWordListsReturnZeroEvenOnExactMatch(t *testing.T) {
	// Ported edge case: when the LONGER (de-duplicated) word list has exactly
	// one word, the function returns 0 regardless of match - even for an
	// identical single word on both sides. This is a real V1/ItemService
	// quirk, not a bug in the port: a naive implementation would return 100
	// here.
	assert.Equal(t, float64(0), wordsInCommon("Sofa", "sofa"))
	assert.Equal(t, float64(0), wordsInCommon("Sofa", "Settee"))
}

func TestWordsInCommonThresholdBoundary(t *testing.T) {
	// 10 unique words on one side, 1 shared word -> exactly 10.0%, at the
	// boundary. estimateWeightFromStandardWeights requires STRICTLY > 10.
	tenWords := "aa bb cc dd ee ff gg hh ii sofa"
	assert.InDelta(t, 10.0, wordsInCommon(tenWords, "sofa"), 0.001)

	// Drop to 9 unique words -> 100/9 = 11.11%, clears the threshold.
	nineWords := "aa bb cc dd ee ff gg hh sofa"
	assert.InDelta(t, 100.0/9.0, wordsInCommon(nineWords, "sofa"), 0.001)
}

func TestEstimateWeightFromStandardWeightsPicksBestMatch(t *testing.T) {
	weights := []standardWeight{
		{Name: "chair", Weight: 5.0},
		{Name: "sofa", Weight: 40.0},
		{Name: "table", Weight: 15.0},
	}

	weight, matched, ok := estimateWeightFromStandardWeights("Big Comfy Sofa", weights)
	assert.True(t, ok)
	assert.Equal(t, 40.0, weight)
	assert.Equal(t, "sofa", matched)
}

func TestEstimateWeightFromStandardWeightsNoMatchBelowThreshold(t *testing.T) {
	weights := []standardWeight{
		{Name: "sofa", Weight: 40.0},
	}

	_, _, ok := estimateWeightFromStandardWeights("Xylophone", weights)
	assert.False(t, ok)
}

func TestEstimateWeightFromStandardWeightsExactlyTenPercentIsRejected(t *testing.T) {
	weights := []standardWeight{
		{Name: "sofa", Weight: 40.0},
	}

	// "aa bb cc dd ee ff gg hh ii sofa" vs "sofa" is exactly 10% (see
	// TestWordsInCommonThresholdBoundary) - must NOT be accepted since the
	// V1/ItemService rule is "> 10", not ">= 10".
	_, _, ok := estimateWeightFromStandardWeights("aa bb cc dd ee ff gg hh ii sofa", weights)
	assert.False(t, ok)
}

func TestEstimateWeightFromStandardWeightsEmptyList(t *testing.T) {
	_, _, ok := estimateWeightFromStandardWeights("anything", nil)
	assert.False(t, ok)
}

func TestRound2dp(t *testing.T) {
	// Avoid asserting exact behaviour at the x.xx5 boundary - float64 can't
	// represent decimals like 1.235 exactly, so which way it rounds is a
	// binary-representation detail, not something this port needs to pin.
	assert.Equal(t, 1.23, round2dp(1.2313))
	assert.Equal(t, 1.24, round2dp(1.2368))
	assert.Equal(t, 0.0, round2dp(0))
}
