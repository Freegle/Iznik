package message

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// ── ExpandQuery — phraseMap ───────────────────────────────────────────────────

func TestExpandQuery_PhraseMap_WhiteGoods(t *testing.T) {
	result := ExpandQuery("white goods")
	assert.Equal(t, []string{"fridge", "freezer", "washing", "dishwasher", "tumble", "cooker", "oven"}, result)
}

func TestExpandQuery_PhraseMap_FlatScreen(t *testing.T) {
	result := ExpandQuery("flat screen")
	assert.Equal(t, []string{"tv", "television", "flatscreen", "monitor"}, result)
}

func TestExpandQuery_PhraseMap_FlatScreens(t *testing.T) {
	result := ExpandQuery("flat screens")
	assert.Equal(t, []string{"tv", "television", "flatscreen", "monitor"}, result)
}

func TestExpandQuery_PhraseMap_BigScreen(t *testing.T) {
	result := ExpandQuery("big screen")
	assert.Equal(t, []string{"tv", "television", "flatscreen"}, result)
}

func TestExpandQuery_PhraseMap_SmartTV(t *testing.T) {
	result := ExpandQuery("smart tv")
	assert.Equal(t, []string{"tv", "television", "flatscreen"}, result)
}

func TestExpandQuery_PhraseMap_CaseInsensitive(t *testing.T) {
	// Phrase lookup is lowercased before matching.
	result := ExpandQuery("White Goods")
	assert.Equal(t, []string{"fridge", "freezer", "washing", "dishwasher", "tumble", "cooker", "oven"}, result)
}

func TestExpandQuery_PhraseMap_LeadingTrailingSpaces(t *testing.T) {
	result := ExpandQuery("  white goods  ")
	assert.Equal(t, []string{"fridge", "freezer", "washing", "dishwasher", "tumble", "cooker", "oven"}, result)
}

func TestExpandQuery_PhraseMap_SecondHand_FallsThrough(t *testing.T) {
	// "second hand" is in phraseMap with an empty slice — falls through to GetWords,
	// then synonym expansion. Since "second" and "hand" have no synonyms, the result
	// must equal what GetWords would return directly (possibly nil if both are stop words).
	result := ExpandQuery("second hand")
	assert.Equal(t, GetWords("second hand"), result)
}

// ── ExpandQuery — synonymMap ──────────────────────────────────────────────────

func TestExpandQuery_SynonymMap_Sofa(t *testing.T) {
	result := ExpandQuery("sofa")
	assert.Equal(t, "sofa", result[0], "original word must come first")
	assert.Contains(t, result, "couch")
	assert.Contains(t, result, "settee")
}

func TestExpandQuery_SynonymMap_Couch(t *testing.T) {
	result := ExpandQuery("couch")
	assert.Equal(t, "couch", result[0])
	assert.Contains(t, result, "sofa")
	assert.Contains(t, result, "settee")
}

func TestExpandQuery_SynonymMap_TV(t *testing.T) {
	result := ExpandQuery("tv")
	assert.Equal(t, "tv", result[0])
	assert.Contains(t, result, "television")
	assert.Contains(t, result, "telly")
	assert.Contains(t, result, "flatscreen")
}

func TestExpandQuery_SynonymMap_Bike(t *testing.T) {
	result := ExpandQuery("bike")
	assert.Equal(t, "bike", result[0])
	assert.Contains(t, result, "bicycle")
	assert.Contains(t, result, "cycle")
}

func TestExpandQuery_SynonymMap_Pram(t *testing.T) {
	result := ExpandQuery("pram")
	assert.Equal(t, "pram", result[0])
	assert.Contains(t, result, "pushchair")
	assert.Contains(t, result, "buggy")
	assert.Contains(t, result, "stroller")
}

func TestExpandQuery_SynonymMap_Fridge(t *testing.T) {
	// "fridge" has an empty synonym entry — no expansion (fridge≠freezer,
	// "refrigerator" is not used in UK listings). Falls back to ["fridge"].
	result := ExpandQuery("fridge")
	assert.Equal(t, []string{"fridge"}, result)
	assert.NotContains(t, result, "refrigerator")
	assert.NotContains(t, result, "freezer")
}

func TestExpandQuery_SynonymMap_Freezer(t *testing.T) {
	// freezer has no entry — it is a distinct appliance, not a synonym of fridge.
	result := ExpandQuery("freezer")
	assert.Equal(t, []string{"freezer"}, result)
	assert.NotContains(t, result, "fridge")
}

func TestExpandQuery_SynonymMap_Hoover(t *testing.T) {
	result := ExpandQuery("hoover")
	assert.Equal(t, "hoover", result[0])
	assert.Contains(t, result, "vacuum")
}

func TestExpandQuery_SynonymMap_Vacuum(t *testing.T) {
	result := ExpandQuery("vacuum")
	assert.Equal(t, "vacuum", result[0])
	assert.Contains(t, result, "hoover")
}

func TestExpandQuery_SynonymMap_Lawnmower(t *testing.T) {
	// "lawnmower" and "mower" are separate tokens in the index with zero overlap.
	result := ExpandQuery("lawnmower")
	assert.Equal(t, "lawnmower", result[0])
	assert.Contains(t, result, "mower")
}

func TestExpandQuery_SynonymMap_Mower(t *testing.T) {
	// Searching "lawn mower" → GetWords → ["lawn","mower"]; "mower" expands to
	// include "lawnmower" so one-word-titled offers are also found.
	result := ExpandQuery("mower")
	assert.Equal(t, "mower", result[0])
	assert.Contains(t, result, "lawnmower")
}

func TestExpandQuery_SynonymMap_Carpet(t *testing.T) {
	result := ExpandQuery("carpet")
	assert.Equal(t, "carpet", result[0])
	assert.Contains(t, result, "rug")
}

func TestExpandQuery_SynonymMap_Tumble_ExpandsToDryer(t *testing.T) {
	// "tumble" is a common abbreviation for "tumble dryer" in UK listings;
	// it must expand to include "dryer" to find offers like "Tumble Dryer".
	result := ExpandQuery("tumble")
	assert.Equal(t, "tumble", result[0])
	assert.Contains(t, result, "dryer")
}

func TestExpandQuery_SynonymMap_Dryer_NoTumbleExpansion(t *testing.T) {
	// "dryer" must NOT expand to "tumble". The starts-with match on "tumble"
	// pulls in drinking-glass "tumbler" offers (confirmed: 8 false positives
	// per 200 results in production). One-directional: tumble→dryer only.
	result := ExpandQuery("dryer")
	assert.Equal(t, []string{"dryer"}, result)
	assert.NotContains(t, result, "tumble")
}

func TestExpandQuery_SynonymMap_Motorbike(t *testing.T) {
	result := ExpandQuery("motorbike")
	assert.Equal(t, "motorbike", result[0])
	assert.Contains(t, result, "motorcycle")
	assert.Contains(t, result, "moped")
	// "scooter" is intentionally NOT a synonym — UK scooter searches are
	// predominantly mobility/electric scooters, not mopeds.
	assert.NotContains(t, result, "scooter")
}

func TestExpandQuery_SynonymMap_Scooter_StandsAlone(t *testing.T) {
	// "scooter" has no synonym entry to avoid conflating mobility/electric
	// scooters with petrol motorbikes.
	result := ExpandQuery("scooter")
	assert.Equal(t, []string{"scooter"}, result)
	assert.NotContains(t, result, "motorbike")
	assert.NotContains(t, result, "moped")
}

// ── ExpandQuery — no synonym ──────────────────────────────────────────────────

func TestExpandQuery_NoSynonym_Table(t *testing.T) {
	result := ExpandQuery("table")
	assert.Equal(t, []string{"table"}, result)
}

func TestExpandQuery_NoSynonym_Desk(t *testing.T) {
	result := ExpandQuery("desk")
	assert.Equal(t, []string{"desk"}, result)
}

// ── ExpandQuery — multi-word with synonyms ────────────────────────────────────

func TestExpandQuery_MultiWord_BlueSofa(t *testing.T) {
	result := ExpandQuery("blue sofa")
	// "blue" has no synonym; "sofa" expands to couch/settee
	assert.Contains(t, result, "sofa")
	assert.Contains(t, result, "couch")
	assert.Contains(t, result, "settee")
	// "blue" is a stop word (common word list), so it may be absent — do not assert it
}

func TestExpandQuery_MultiWord_TwoSynonymWords(t *testing.T) {
	result := ExpandQuery("sofa bike")
	assert.Contains(t, result, "sofa")
	assert.Contains(t, result, "couch")
	assert.Contains(t, result, "settee")
	assert.Contains(t, result, "bike")
	assert.Contains(t, result, "bicycle")
	assert.Contains(t, result, "cycle")
}

// ── ExpandQuery — deduplication ───────────────────────────────────────────────

func TestExpandQuery_NoDuplicates(t *testing.T) {
	result := ExpandQuery("sofa")
	seen := make(map[string]bool)
	for _, w := range result {
		assert.False(t, seen[w], "duplicate word in result: %q", w)
		seen[w] = true
	}
}

func TestExpandQuery_NoDuplicates_TVTelevision(t *testing.T) {
	// "tv" expands to ["television", "telly", "flatscreen"];
	// "television" expands to ["tv", "telly"]. Input "tv television" must not
	// produce "tv" or "television" twice.
	result := ExpandQuery("tv television")
	seen := make(map[string]bool)
	for _, w := range result {
		assert.False(t, seen[w], "duplicate word in result: %q", w)
		seen[w] = true
	}
}

// ── ExpandQuery — edge cases ──────────────────────────────────────────────────

func TestExpandQuery_EmptyString(t *testing.T) {
	result := ExpandQuery("")
	// GetWords("") returns nil; ExpandQuery must return that nil, not panic.
	assert.Nil(t, result)
}

func TestExpandQuery_OnlyStopWords(t *testing.T) {
	// "the old" — both stop words; GetWords returns nil.
	result := ExpandQuery("the old")
	assert.Nil(t, result)
}

func TestExpandQuery_OriginalWordFirst(t *testing.T) {
	// For every single-word query that has synonyms, the original must lead.
	cases := []string{"sofa", "couch", "tv", "bike", "pram", "hoover"}
	for _, word := range cases {
		result := ExpandQuery(word)
		assert.NotEmpty(t, result, "word=%q produced empty result", word)
		assert.Equal(t, word, result[0], "word=%q: original must be first element", word)
	}
}
