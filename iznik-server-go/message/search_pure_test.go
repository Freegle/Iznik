package message

import (
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// ── GetWords ──────────────────────────────────────────────────────────────────

func TestGetWords_SimpleWords(t *testing.T) {
	result := GetWords("table chair sofa")
	assert.Contains(t, result, "table")
	assert.Contains(t, result, "chair")
	assert.Contains(t, result, "sofa")
}

func TestGetWords_CommonWordsFiltered(t *testing.T) {
	// All words in this string are in the common word list.
	result := GetWords("the old new please thanks with offer taken")
	assert.Empty(t, result)
}

func TestGetWords_MixedCommonAndUncommon(t *testing.T) {
	result := GetWords("the bicycle")
	assert.Contains(t, result, "bicycle")
	assert.NotContains(t, result, "the")
}

func TestGetWords_PunctuationStripped(t *testing.T) {
	result := GetWords("sofa, chair!")
	assert.Contains(t, result, "sofa")
	assert.Contains(t, result, "chair")
}

func TestGetWords_EmptyString(t *testing.T) {
	result := GetWords("")
	assert.Nil(t, result)
}

func TestGetWords_SingleCharWords(t *testing.T) {
	// Single-character words are under the 2-char minimum and must be filtered.
	result := GetWords("a b c d")
	assert.Nil(t, result)
}

func TestGetWords_TwoCharWords(t *testing.T) {
	// 2-char words are on the boundary; common list has many but not all 2-char.
	// "tv" is not in the common word list.
	result := GetWords("tv")
	assert.Contains(t, result, "tv")
}

func TestGetWords_Lowercase(t *testing.T) {
	result := GetWords("Bicycle TABLE")
	assert.Contains(t, result, "bicycle")
	assert.Contains(t, result, "table")
}

func TestGetWords_NumbersInWords(t *testing.T) {
	result := GetWords("iphone13")
	assert.Contains(t, result, "iphone13")
}

func TestGetWords_OnlySpecialChars(t *testing.T) {
	result := GetWords("!@#$%^&*()")
	assert.Nil(t, result)
}

func TestGetWords_Hyphen(t *testing.T) {
	// Hyphens split words; both halves must pass the filters.
	result := GetWords("bookcase-shelf")
	assert.Contains(t, result, "bookcase")
	assert.Contains(t, result, "shelf")
}

func TestGetWords_AllCommonWordList(t *testing.T) {
	// "freegle" and "freecycle" are both in the common list.
	result := GetWords("freegle freecycle")
	assert.Nil(t, result)
}

func TestGetWords_DuplicatesPreserved(t *testing.T) {
	// GetWords does not de-duplicate; both occurrences should appear.
	result := GetWords("chair chair")
	assert.Len(t, result, 2)
}

func TestGetWords_OFFERtype(t *testing.T) {
	// "offer" is a common word; "bicycle" is not.
	result := GetWords("OFFER: bicycle")
	assert.Contains(t, result, "bicycle")
	assert.NotContains(t, result, "offer")
}

func TestGetWords_SingleRetained(t *testing.T) {
	// "single" must NOT be filtered — "single bed" and "double bed" are different
	// items; dropping size qualifiers makes searches ambiguous.
	result := GetWords("single bed")
	assert.Contains(t, result, "single")
	assert.Contains(t, result, "bed")
}

func TestGetWords_DoubleRetained(t *testing.T) {
	// "double" must NOT be filtered — same reason as "single".
	result := GetWords("double mattress")
	assert.Contains(t, result, "double")
	assert.Contains(t, result, "mattress")
}

func TestGetWords_DoubleBed_BothWords(t *testing.T) {
	// "double bed" → both words retained so the index can rank double beds
	// above single beds when wordmatch=2 vs wordmatch=1.
	result := GetWords("double bed")
	assert.Equal(t, 2, len(result), "expected exactly [double bed], got %v", result)
	assert.Contains(t, result, "double")
	assert.Contains(t, result, "bed")
}

func TestGetWords_Tabs_And_Newlines(t *testing.T) {
	result := GetWords("sofa\tchair\ndesk")
	assert.Contains(t, result, "sofa")
	assert.Contains(t, result, "chair")
	assert.Contains(t, result, "desk")
}

// ── processResults ────────────────────────────────────────────────────────────

func TestProcessResults_SetsTagAndWord(t *testing.T) {
	input := []SearchResult{
		{Msgid: 1, Word: "bicycle"},
		{Msgid: 2, Word: "chair"},
	}
	result := processResults("Exact", input)
	assert.Len(t, result, 2)
	assert.Equal(t, "Exact", result[0].Matchedon.Type)
	assert.Equal(t, "bicycle", result[0].Matchedon.Word)
	assert.Equal(t, "Exact", result[1].Matchedon.Type)
	assert.Equal(t, "chair", result[1].Matchedon.Word)
}

func TestProcessResults_EmptySlice(t *testing.T) {
	result := processResults("Typo", []SearchResult{})
	assert.Empty(t, result)
}

func TestProcessResults_NilSlice(t *testing.T) {
	result := processResults("Typo", nil)
	assert.Nil(t, result)
}

func TestProcessResults_PreservesOtherFields(t *testing.T) {
	now := time.Now().Truncate(time.Second)
	input := []SearchResult{
		{Msgid: 42, Groupid: 7, Arrival: now, Lat: 51.5, Lng: -0.1, Word: "sofa"},
	}
	result := processResults("StartsWith", input)
	assert.Equal(t, uint64(42), result[0].Msgid)
	assert.Equal(t, uint64(7), result[0].Groupid)
	assert.Equal(t, now, result[0].Arrival)
	assert.Equal(t, 51.5, result[0].Lat)
	assert.Equal(t, -0.1, result[0].Lng)
}

func TestProcessResults_DifferentTags(t *testing.T) {
	input := []SearchResult{{Msgid: 1, Word: "table"}}

	tables := []struct {
		tag string
	}{
		{"Exact"},
		{"Typo"},
		{"StartsWith"},
		{"SoundsLike"},
	}
	for _, tt := range tables {
		result := processResults(tt.tag, input)
		assert.Equal(t, tt.tag, result[0].Matchedon.Type, "tag=%s", tt.tag)
	}
}

func TestProcessResults_MutatesInPlace(t *testing.T) {
	// processResults modifies the slice in-place; the returned slice is the same.
	input := []SearchResult{{Msgid: 1, Word: "bed"}}
	result := processResults("Exact", input)
	// Both slices share the same backing array.
	assert.Equal(t, input[0].Matchedon.Type, result[0].Matchedon.Type)
}

// ── groupFilter ───────────────────────────────────────────────────────────────

func TestGroupFilter_EmptySlice(t *testing.T) {
	assert.Equal(t, "", groupFilter([]uint64{}))
}

func TestGroupFilter_NilSlice(t *testing.T) {
	assert.Equal(t, "", groupFilter(nil))
}

func TestGroupFilter_SingleID(t *testing.T) {
	result := groupFilter([]uint64{123})
	assert.Contains(t, result, "123")
	assert.Contains(t, result, "messages_spatial.groupid IN (")
}

func TestGroupFilter_MultipleIDs(t *testing.T) {
	result := groupFilter([]uint64{1, 2, 3})
	assert.Contains(t, result, "1,2,3")
}

func TestGroupFilter_ClosingParen(t *testing.T) {
	result := groupFilter([]uint64{5, 10})
	assert.True(t, strings.HasSuffix(strings.TrimRight(result, " "), ")"),
		"result should end with closing paren: %q", result)
}

func TestGroupFilter_LargeIDs(t *testing.T) {
	result := groupFilter([]uint64{^uint64(0)})
	assert.Contains(t, result, "18446744073709551615")
}

// ── typeFilter ────────────────────────────────────────────────────────────────

var typeFilterTests = []struct {
	msgtype   string
	wantOp    string // substring expected in non-empty result
	wantEmpty bool
}{
	{utils.OFFER, utils.OFFER, false},
	{utils.WANTED, utils.WANTED, false},
	{"", "", true},
	{"Unknown", "", true},
	{"offer", "", true},  // case-sensitive
	{"OFFER", "", true},  // case-sensitive
}

func TestTypeFilter_Table(t *testing.T) {
	for _, tt := range typeFilterTests {
		result := typeFilter(tt.msgtype)
		if tt.wantEmpty {
			assert.Empty(t, result, "msgtype=%q should produce empty string", tt.msgtype)
		} else {
			assert.NotEmpty(t, result, "msgtype=%q should produce a non-empty SQL fragment", tt.msgtype)
			assert.Contains(t, result, tt.wantOp, "msgtype=%q", tt.msgtype)
		}
	}
}

// ── boxFilter ─────────────────────────────────────────────────────────────────

var boxFilterTests = []struct {
	name      string
	nelat     float32
	nelng     float32
	swlat     float32
	swlng     float32
	wantEmpty bool
}{
	{"all nonzero", 52.0, 1.0, 51.0, -1.0, false},
	{"nelat zero", 0, 1.0, 51.0, -1.0, true},
	{"nelng zero", 52.0, 0, 51.0, -1.0, true},
	{"swlat zero", 52.0, 1.0, 0, -1.0, true},
	{"swlng zero", 52.0, 1.0, 51.0, 0, true},
	{"all zero", 0, 0, 0, 0, true},
	{"negative coords all nonzero", -10.0, -20.0, -11.0, -21.0, false},
}

func TestBoxFilter_Table(t *testing.T) {
	for _, tt := range boxFilterTests {
		t.Run(tt.name, func(t *testing.T) {
			result := boxFilter(tt.nelat, tt.nelng, tt.swlat, tt.swlng)
			if tt.wantEmpty {
				assert.Empty(t, result, "expected empty SQL fragment for %s", tt.name)
			} else {
				assert.NotEmpty(t, result, "expected SQL fragment for %s", tt.name)
				assert.Contains(t, result, "ST_Contains")
				assert.Contains(t, result, "POLYGON")
			}
		})
	}
}

func TestBoxFilter_ContainsPadding(t *testing.T) {
	// boxFilter adds 0.02 padding on each side.
	result := boxFilter(52.0, 1.0, 51.0, -1.0)
	// nelat=52.0+0.02=52.02, nelng=1.0+0.02=1.02, swlat=51.0-0.02=50.98, swlng=-1.0-0.02=-1.02
	assert.Contains(t, result, "52.02")
	assert.Contains(t, result, "1.02")
	assert.Contains(t, result, "50.98")
	assert.Contains(t, result, "-1.02")
}

func TestBoxFilter_ContainsSRID(t *testing.T) {
	result := boxFilter(52.0, 1.0, 51.0, -1.0)
	srid := "3857"
	assert.Contains(t, result, srid, "SRID must be embedded in the SQL fragment")
}

func TestBoxFilter_SQLStructure(t *testing.T) {
	result := boxFilter(52.0, 1.0, 51.0, -1.0)
	assert.Contains(t, result, "LINESTRING")
	assert.Contains(t, result, "POINT(")
	assert.Contains(t, result, "point)")
}

// ── fingerprintVec ────────────────────────────────────────────────────────────

func TestFingerprintVec_EmptySlice(t *testing.T) {
	assert.Equal(t, "", fingerprintVec([]float32{}))
}

func TestFingerprintVec_NilSlice(t *testing.T) {
	assert.Equal(t, "", fingerprintVec(nil))
}

func TestFingerprintVec_LessThan4Elements(t *testing.T) {
	assert.Equal(t, "", fingerprintVec([]float32{1.0}))
	assert.Equal(t, "", fingerprintVec([]float32{1.0, 2.0}))
	assert.Equal(t, "", fingerprintVec([]float32{1.0, 2.0, 3.0}))
}

func TestFingerprintVec_Exactly4Elements(t *testing.T) {
	result := fingerprintVec([]float32{1.0, 2.0, 3.0, 4.0})
	assert.NotEmpty(t, result)
	assert.Contains(t, result, "1.0000")
	assert.Contains(t, result, "2.0000")
	assert.Contains(t, result, "3.0000")
	assert.Contains(t, result, "4.0000")
}

func TestFingerprintVec_MoreThan4Elements(t *testing.T) {
	// Only first 4 are used.
	result := fingerprintVec([]float32{1.0, 2.0, 3.0, 4.0, 99.0, 100.0})
	assert.Contains(t, result, "1.0000")
	assert.NotContains(t, result, "99")
}

func TestFingerprintVec_NegativeValues(t *testing.T) {
	result := fingerprintVec([]float32{-1.0, -2.0, -3.0, -4.0})
	assert.Contains(t, result, "-1.0000")
	assert.Contains(t, result, "-2.0000")
}

func TestFingerprintVec_ZeroValues(t *testing.T) {
	result := fingerprintVec([]float32{0.0, 0.0, 0.0, 0.0})
	assert.Equal(t, "0.0000,0.0000,0.0000,0.0000", result)
}

func TestFingerprintVec_Format(t *testing.T) {
	// Result must be comma-separated, 4 decimals.
	result := fingerprintVec([]float32{0.12345, 0.6789, 1.0001, -0.5})
	parts := strings.Split(result, ",")
	assert.Len(t, parts, 4)
}

func TestFingerprintVec_Deterministic(t *testing.T) {
	v := []float32{0.1, 0.2, 0.3, 0.4}
	a := fingerprintVec(v)
	b := fingerprintVec(v)
	assert.Equal(t, a, b)
}

// ── sortByScoreDesc ───────────────────────────────────────────────────────────

func makeScoredResults(scores []float32) []scoredResult {
	out := make([]scoredResult, len(scores))
	for i, s := range scores {
		out[i] = scoredResult{
			result: SearchResult{Msgid: uint64(i + 1)},
			score:  s,
		}
	}
	return out
}

func TestSortByScoreDesc_Empty(t *testing.T) {
	s := []scoredResult{}
	sortByScoreDesc(s) // must not panic
	assert.Empty(t, s)
}

func TestSortByScoreDesc_Single(t *testing.T) {
	s := makeScoredResults([]float32{0.8})
	sortByScoreDesc(s)
	assert.Len(t, s, 1)
	assert.Equal(t, float32(0.8), s[0].score)
}

func TestSortByScoreDesc_AlreadySorted(t *testing.T) {
	s := makeScoredResults([]float32{0.9, 0.8, 0.7})
	sortByScoreDesc(s)
	assert.Equal(t, float32(0.9), s[0].score)
	assert.Equal(t, float32(0.8), s[1].score)
	assert.Equal(t, float32(0.7), s[2].score)
}

func TestSortByScoreDesc_ReverseSorted(t *testing.T) {
	s := makeScoredResults([]float32{0.7, 0.8, 0.9})
	sortByScoreDesc(s)
	assert.Equal(t, float32(0.9), s[0].score)
	assert.Equal(t, float32(0.8), s[1].score)
	assert.Equal(t, float32(0.7), s[2].score)
}

func TestSortByScoreDesc_AllEqual(t *testing.T) {
	s := makeScoredResults([]float32{0.75, 0.75, 0.75})
	sortByScoreDesc(s)
	for _, r := range s {
		assert.Equal(t, float32(0.75), r.score)
	}
}

func TestSortByScoreDesc_NegativeScores(t *testing.T) {
	s := makeScoredResults([]float32{-0.1, -0.5, 0.0, 0.9})
	sortByScoreDesc(s)
	assert.Equal(t, float32(0.9), s[0].score)
	assert.Equal(t, float32(0.0), s[1].score)
	assert.Equal(t, float32(-0.1), s[2].score)
	assert.Equal(t, float32(-0.5), s[3].score)
}

func TestSortByScoreDesc_PreservesResult(t *testing.T) {
	// Make sure the associated SearchResult stays bound to its score.
	s := []scoredResult{
		{result: SearchResult{Msgid: 10}, score: 0.5},
		{result: SearchResult{Msgid: 20}, score: 0.9},
		{result: SearchResult{Msgid: 30}, score: 0.7},
	}
	sortByScoreDesc(s)
	assert.Equal(t, uint64(20), s[0].result.Msgid)
	assert.Equal(t, uint64(30), s[1].result.Msgid)
	assert.Equal(t, uint64(10), s[2].result.Msgid)
}

func TestSortByScoreDesc_LargeSlice(t *testing.T) {
	n := 100
	scores := make([]float32, n)
	for i := range scores {
		scores[i] = float32(i) / float32(n)
	}
	s := makeScoredResults(scores)
	sortByScoreDesc(s)
	for i := 0; i < len(s)-1; i++ {
		assert.GreaterOrEqual(t, s[i].score, s[i+1].score,
			"index %d: score %v < score %v at index %d", i, s[i].score, s[i+1].score, i+1)
	}
}
