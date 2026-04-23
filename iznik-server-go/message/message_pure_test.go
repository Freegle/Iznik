package message

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// ── sanitiseForEmail ──────────────────────────────────────────────────────────

func TestSanitiseForEmailAlphanumeric(t *testing.T) {
	assert.Equal(t, "alice", sanitiseForEmail("Alice"))
}

func TestSanitiseForEmailStripsSpecialChars(t *testing.T) {
	assert.Equal(t, "alicebob", sanitiseForEmail("alice.bob"))
}

func TestSanitiseForEmailStripsDashes(t *testing.T) {
	assert.Equal(t, "alicebob", sanitiseForEmail("alice-bob"))
}

func TestSanitiseForEmailTruncatesAt16(t *testing.T) {
	result := sanitiseForEmail("averylongnamethatexceedssixteencharacters")
	assert.Equal(t, 16, len(result))
	assert.Equal(t, "averylongnameth", result[:15])
}

func TestSanitiseForEmailEmpty(t *testing.T) {
	assert.Equal(t, "", sanitiseForEmail(""))
}

func TestSanitiseForEmailOnlySpecialChars(t *testing.T) {
	assert.Equal(t, "", sanitiseForEmail("!@#$%^"))
}

func TestSanitiseForEmailLowercases(t *testing.T) {
	assert.Equal(t, "testname", sanitiseForEmail("TestName"))
}

func TestSanitiseForEmailPreservesDigits(t *testing.T) {
	assert.Equal(t, "user123", sanitiseForEmail("user123"))
}

func TestSanitiseForEmailExactly16Chars(t *testing.T) {
	// Exactly 16 alphanumeric chars must not be truncated.
	result := sanitiseForEmail("abcdefghijklmnop")
	assert.Equal(t, "abcdefghijklmnop", result)
}

// ── splitOnWordBoundary ───────────────────────────────────────────────────────

func TestSplitOnWordBoundarySimple(t *testing.T) {
	tokens := splitOnWordBoundary("hello world")
	assert.Contains(t, tokens, "hello")
	assert.Contains(t, tokens, "world")
}

func TestSplitOnWordBoundaryPunctuation(t *testing.T) {
	tokens := splitOnWordBoundary("hello, world!")
	assert.Contains(t, tokens, "hello")
	assert.Contains(t, tokens, "world")
}

func TestSplitOnWordBoundaryEmpty(t *testing.T) {
	tokens := splitOnWordBoundary("")
	// Split of empty string returns [""].
	assert.NotNil(t, tokens)
}

func TestSplitOnWordBoundarySingleWord(t *testing.T) {
	tokens := splitOnWordBoundary("freegle")
	assert.Contains(t, tokens, "freegle")
}

func TestSplitOnWordBoundaryHyphenSeparates(t *testing.T) {
	tokens := splitOnWordBoundary("well-known")
	assert.Contains(t, tokens, "well")
	assert.Contains(t, tokens, "known")
}

func TestSplitOnWordBoundaryNumbers(t *testing.T) {
	tokens := splitOnWordBoundary("item42 test")
	assert.Contains(t, tokens, "item42")
	assert.Contains(t, tokens, "test")
}

// ── removeWordBoundary ────────────────────────────────────────────────────────

func TestRemoveWordBoundaryBasic(t *testing.T) {
	result := removeWordBoundary("I have a gun", "gun")
	assert.NotContains(t, result, "gun")
}

func TestRemoveWordBoundaryCaseInsensitive(t *testing.T) {
	result := removeWordBoundary("I have a Gun", "gun")
	assert.NotContains(t, result, "Gun")
}

func TestRemoveWordBoundaryNoMatchLeft(t *testing.T) {
	// "gunpowder" must NOT be removed by the "gun" boundary rule.
	result := removeWordBoundary("gunpowder in the barrel", "gun")
	assert.Contains(t, result, "gunpowder")
}

func TestRemoveWordBoundaryNoMatchRight(t *testing.T) {
	// "beginning" must NOT be removed by the "gun" rule.
	result := removeWordBoundary("beginning of the end", "gun")
	assert.Contains(t, result, "beginning")
}

func TestRemoveWordBoundaryWordNotPresent(t *testing.T) {
	// Input unchanged when keyword is absent.
	result := removeWordBoundary("harmless text", "gun")
	assert.Equal(t, "harmless text", result)
}

func TestRemoveWordBoundaryMultipleOccurrences(t *testing.T) {
	result := removeWordBoundary("gun or Gun or gun", "gun")
	assert.NotContains(t, result, "gun")
	assert.NotContains(t, result, "Gun")
}

func TestRemoveWordBoundaryReturnsUnchangedOnInvalidWord(t *testing.T) {
	// Empty word — regex still compiles and matches word boundaries around empty string, which is fine.
	result := removeWordBoundary("hello", "")
	assert.NotEmpty(t, result) // Should not panic.
}

// ── locationIDsEqual ──────────────────────────────────────────────────────────

func TestLocationIDsEqualBothNil(t *testing.T) {
	assert.True(t, locationIDsEqual(nil, nil))
}

func TestLocationIDsEqualFirstNil(t *testing.T) {
	v := uint64(1)
	assert.False(t, locationIDsEqual(nil, &v))
}

func TestLocationIDsEqualSecondNil(t *testing.T) {
	v := uint64(1)
	assert.False(t, locationIDsEqual(&v, nil))
}

func TestLocationIDsEqualSameValue(t *testing.T) {
	a, b := uint64(42), uint64(42)
	assert.True(t, locationIDsEqual(&a, &b))
}

func TestLocationIDsEqualDifferentValues(t *testing.T) {
	a, b := uint64(1), uint64(2)
	assert.False(t, locationIDsEqual(&a, &b))
}

func TestLocationIDsEqualZeroValues(t *testing.T) {
	a, b := uint64(0), uint64(0)
	assert.True(t, locationIDsEqual(&a, &b))
}

// ── stringPtrEqual ────────────────────────────────────────────────────────────

func TestStringPtrEqualBothNil(t *testing.T) {
	assert.True(t, stringPtrEqual(nil, nil))
}

func TestStringPtrEqualFirstNil(t *testing.T) {
	s := "hello"
	assert.False(t, stringPtrEqual(nil, &s))
}

func TestStringPtrEqualSecondNil(t *testing.T) {
	s := "hello"
	assert.False(t, stringPtrEqual(&s, nil))
}

func TestStringPtrEqualSameValue(t *testing.T) {
	a, b := "hello", "hello"
	assert.True(t, stringPtrEqual(&a, &b))
}

func TestStringPtrEqualDifferentValues(t *testing.T) {
	a, b := "hello", "world"
	assert.False(t, stringPtrEqual(&a, &b))
}

func TestStringPtrEqualEmptyStrings(t *testing.T) {
	a, b := "", ""
	assert.True(t, stringPtrEqual(&a, &b))
}

func TestStringPtrEqualEmptyVsNonEmpty(t *testing.T) {
	a, b := "", "x"
	assert.False(t, stringPtrEqual(&a, &b))
}

// ── matchWorryWords ───────────────────────────────────────────────────────────

func TestMatchWorryWordsNoWords(t *testing.T) {
	matches := matchWorryWords("buy a gun", "free gun", []WorryWord{})
	// Pound sign check only; no worry words.
	assert.Empty(t, matches)
}

func TestMatchWorryWordsPoundSign(t *testing.T) {
	matches := matchWorryWords("sell for £20", "asking £20", []WorryWord{})
	assert.Len(t, matches, 1)
	assert.Equal(t, "£", matches[0].Word)
	assert.Equal(t, "Review", matches[0].Worryword.Type)
}

func TestMatchWorryWordsPoundSignDeduped(t *testing.T) {
	// Pound sign in both subject and body should only produce one match.
	matches := matchWorryWords("£10 item", "price £10", []WorryWord{})
	poundCount := 0
	for _, m := range matches {
		if m.Word == "£" {
			poundCount++
		}
	}
	assert.Equal(t, 1, poundCount)
}

func TestMatchWorryWordsSingleWordExact(t *testing.T) {
	words := []WorryWord{{Keyword: "gun", Type: "Review"}}
	matches := matchWorryWords("OFFER: free gun", "", words)
	found := false
	for _, m := range matches {
		if m.Worryword.Keyword == "gun" {
			found = true
		}
	}
	assert.True(t, found, "Expected 'gun' to be matched")
}

func TestMatchWorryWordsSingleWordInBody(t *testing.T) {
	words := []WorryWord{{Keyword: "weapon", Type: "Review"}}
	matches := matchWorryWords("old item", "selling weapon here", words)
	found := false
	for _, m := range matches {
		if m.Worryword.Keyword == "weapon" {
			found = true
		}
	}
	assert.True(t, found, "Expected 'weapon' to be found in body")
}

func TestMatchWorryWordsPhrase(t *testing.T) {
	words := []WorryWord{{Keyword: "air gun", Type: "Review"}}
	matches := matchWorryWords("OFFER: air gun for free", "", words)
	found := false
	for _, m := range matches {
		if m.Worryword.Keyword == "air gun" {
			found = true
		}
	}
	assert.True(t, found, "Expected phrase 'air gun' to be matched")
}

func TestMatchWorryWordsCaseInsensitiveSubject(t *testing.T) {
	words := []WorryWord{{Keyword: "Knife", Type: "Review"}}
	matches := matchWorryWords("Offering KNIFE set", "", words)
	found := false
	for _, m := range matches {
		if m.Worryword.Keyword == "Knife" {
			found = true
		}
	}
	assert.True(t, found, "Expected case-insensitive keyword match")
}

func TestMatchWorryWordsAllowedWordExcluded(t *testing.T) {
	words := []WorryWord{
		{Keyword: "gun", Type: "Review"},
		{Keyword: "shotgun", Type: "Allowed"},
	}
	// "shotgun" is allowed so it should remove "gun" context — but "gun" standalone still matches.
	matches := matchWorryWords("OFFER: shotgun shell", "", words)
	// The allowed word "shotgun" removes the token "shotgun", which contains "gun".
	// After removal, "gun" alone should no longer match.
	for _, m := range matches {
		assert.NotEqual(t, "gun", m.Worryword.Keyword,
			"'gun' should not match when 'shotgun' is allowed and the only occurrence")
	}
}

func TestMatchWorryWordsDeduplication(t *testing.T) {
	words := []WorryWord{{Keyword: "gun", Type: "Review"}}
	// "gun" appears in both subject and body — should only appear once.
	matches := matchWorryWords("gun", "gun again", words)
	count := 0
	for _, m := range matches {
		if m.Worryword.Keyword == "gun" {
			count++
		}
	}
	assert.Equal(t, 1, count, "Duplicate worry word matches must be deduplicated")
}

func TestMatchWorryWordsNoFalsePositives(t *testing.T) {
	words := []WorryWord{{Keyword: "gun", Type: "Review"}}
	// "gunpowder" is a different word and must not trigger the "gun" rule.
	matches := matchWorryWords("gunpowder residue", "barrel and gunpowder", words)
	for _, m := range matches {
		assert.NotEqual(t, "gun", m.Worryword.Keyword,
			"'gun' must not match inside 'gunpowder'")
	}
}

func TestMatchWorryWordsEmptyInputNoWords(t *testing.T) {
	matches := matchWorryWords("", "", []WorryWord{})
	assert.Empty(t, matches)
}

func TestMatchWorryWordsMultipleMatches(t *testing.T) {
	words := []WorryWord{
		{Keyword: "gun", Type: "Review"},
		{Keyword: "knife", Type: "Review"},
	}
	matches := matchWorryWords("gun and knife for sale", "", words)
	keywords := map[string]bool{}
	for _, m := range matches {
		keywords[m.Worryword.Keyword] = true
	}
	assert.True(t, keywords["gun"], "Expected 'gun' in matches")
	assert.True(t, keywords["knife"], "Expected 'knife' in matches")
}
