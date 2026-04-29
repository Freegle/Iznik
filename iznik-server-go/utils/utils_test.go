package utils

import (
	"encoding/json"
	"math"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// FlexUint64
// ---------------------------------------------------------------------------

func TestFlexUint64_NumericJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":42}`), &s))
	assert.Equal(t, FlexUint64(42), s.V)
}

func TestFlexUint64_StringJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"12345"}`), &s))
	assert.Equal(t, FlexUint64(12345), s.V)
}

func TestFlexUint64_NullJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	s.V = 99
	assert.NoError(t, json.Unmarshal([]byte(`{"V":null}`), &s))
	assert.Equal(t, FlexUint64(0), s.V)
}

func TestFlexUint64_EmptyStringJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	s.V = 99
	assert.NoError(t, json.Unmarshal([]byte(`{"V":""}`), &s))
	assert.Equal(t, FlexUint64(0), s.V)
}

func TestFlexUint64_InvalidJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.Error(t, json.Unmarshal([]byte(`{"V":"notanumber"}`), &s))
}

// ---------------------------------------------------------------------------
// FlexInt
// ---------------------------------------------------------------------------

func TestFlexInt_NumericJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":-7}`), &s))
	assert.Equal(t, FlexInt(-7), s.V)
}

func TestFlexInt_StringJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"0"}`), &s))
	assert.Equal(t, FlexInt(0), s.V)
}

func TestFlexInt_NullJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	s.V = 5
	assert.NoError(t, json.Unmarshal([]byte(`{"V":null}`), &s))
	assert.Equal(t, FlexInt(0), s.V)
}

func TestFlexInt_EmptyStringJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	s.V = 7
	assert.NoError(t, json.Unmarshal([]byte(`{"V":""}`), &s))
	assert.Equal(t, FlexInt(0), s.V)
}

func TestFlexInt_InvalidJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.Error(t, json.Unmarshal([]byte(`{"V":"xyz"}`), &s))
}

func TestFlexInt_NegativeStringJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"-123"}`), &s))
	assert.Equal(t, FlexInt(-123), s.V)
}

// Vue's OurToggle emits a boolean on @change; the settings UI then PATCHes
// fields like relevantallowed/newslettersallowed as JSON booleans. PHP V1
// coerced these naturally; V2 must accept them too or the toggle 400s.
func TestFlexInt_BooleanTrueJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":true}`), &s))
	assert.Equal(t, FlexInt(1), s.V)
}

func TestFlexInt_BooleanFalseJSON(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	s.V = 1
	assert.NoError(t, json.Unmarshal([]byte(`{"V":false}`), &s))
	assert.Equal(t, FlexInt(0), s.V)
}

func TestFlexUint64_BooleanTrueJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":true}`), &s))
	assert.Equal(t, FlexUint64(1), s.V)
}

func TestFlexUint64_BooleanFalseJSON(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	s.V = 1
	assert.NoError(t, json.Unmarshal([]byte(`{"V":false}`), &s))
	assert.Equal(t, FlexUint64(0), s.V)
}

// ---------------------------------------------------------------------------
// FlexFloat64
// ---------------------------------------------------------------------------

func TestFlexFloat64_NumericJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":3.14}`), &s))
	assert.InDelta(t, 3.14, float64(s.V), 1e-9)
}

func TestFlexFloat64_StringJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"2.718"}`), &s))
	assert.InDelta(t, 2.718, float64(s.V), 1e-9)
}

func TestFlexFloat64_NullJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	s.V = 1.5
	assert.NoError(t, json.Unmarshal([]byte(`{"V":null}`), &s))
	assert.Equal(t, FlexFloat64(0), s.V)
}

func TestFlexFloat64_EmptyStringJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	s.V = 2.5
	assert.NoError(t, json.Unmarshal([]byte(`{"V":""}`), &s))
	assert.Equal(t, FlexFloat64(0), s.V)
}

func TestFlexFloat64_NegativeStringJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"-3.14159"}`), &s))
	assert.InDelta(t, -3.14159, float64(s.V), 1e-9)
}

func TestFlexFloat64_ZeroStringJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	s.V = 5.5
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"0"}`), &s))
	assert.Equal(t, FlexFloat64(0), s.V)
}

func TestFlexFloat64_InvalidJSON(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.Error(t, json.Unmarshal([]byte(`{"V":"notfloat"}`), &s))
}

// ---------------------------------------------------------------------------
// RandomHex
// ---------------------------------------------------------------------------

func TestRandomHex_CorrectLength(t *testing.T) {
	for _, n := range []int{4, 8, 16, 32} {
		got := RandomHex(n)
		assert.Equal(t, n*2, len(got), "expected %d hex chars for %d bytes", n*2, n)
	}
}

func TestRandomHex_HexCharsOnly(t *testing.T) {
	for _, ch := range RandomHex(32) {
		assert.Contains(t, "0123456789abcdef", string(ch))
	}
}

func TestRandomHex_Unique(t *testing.T) {
	// Two calls should not produce the same result (with overwhelming probability).
	assert.NotEqual(t, RandomHex(16), RandomHex(16))
}

// ---------------------------------------------------------------------------
// RandomUint64
// ---------------------------------------------------------------------------

func TestRandomUint64_NonZero(t *testing.T) {
	for i := 0; i < 100; i++ {
		assert.NotZero(t, RandomUint64())
	}
}

func TestRandomUint64_FitsIn53Bits(t *testing.T) {
	maxSafe := uint64(1<<53 - 1)
	for i := 0; i < 200; i++ {
		v := RandomUint64()
		assert.LessOrEqual(t, v, maxSafe, "value %d exceeds 2^53-1", v)
	}
}

// ---------------------------------------------------------------------------
// NilIfEmpty / NilIfZero
// ---------------------------------------------------------------------------

func TestNilIfEmpty_Empty(t *testing.T) {
	assert.Nil(t, NilIfEmpty(""))
}

func TestNilIfEmpty_NonEmpty(t *testing.T) {
	assert.Equal(t, "hello", NilIfEmpty("hello"))
}

func TestNilIfZero_Zero(t *testing.T) {
	assert.Nil(t, NilIfZero(0))
}

func TestNilIfZero_NonZero(t *testing.T) {
	assert.Equal(t, uint64(99), NilIfZero(99))
}

// ---------------------------------------------------------------------------
// Blur
// ---------------------------------------------------------------------------

func TestBlur_ReturnsValidCoords(t *testing.T) {
	// A well-known location (London) blurred by 400m should stay in England.
	lat, lng := Blur(51.5, -0.1, 400)
	assert.InDelta(t, 51.5, lat, 0.05)
	assert.InDelta(t, -0.1, lng, 0.05)
}

func TestBlur_InvalidLatFallsBackToBritain(t *testing.T) {
	// Out-of-range lat/lng should snap to the center-of-Britain fallback.
	lat, lng := Blur(999, 999, 400)
	// Dunsop Bridge is at ~53.945, -2.521; blurred by 400m stays near there.
	assert.InDelta(t, 53.945, lat, 0.1)
	assert.InDelta(t, -2.5209, lng, 0.1)
}

func TestBlur_RoundedTo3DecimalPlaces(t *testing.T) {
	lat, lng := Blur(51.123456, -0.654321, 400)
	// After math.Round(x*1000)/1000 the value has at most 3 dp.
	assert.Equal(t, math.Round(lat*1000)/1000, lat)
	assert.Equal(t, math.Round(lng*1000)/1000, lng)
}

func TestBlur_ZeroDistanceNoChange(t *testing.T) {
	// Blurring by 0 metres should leave the point essentially unchanged.
	lat, lng := Blur(51.5, -0.1, 0)
	assert.InDelta(t, 51.5, lat, 0.001)
	assert.InDelta(t, -0.1, lng, 0.001)
}

// ---------------------------------------------------------------------------
// Haversine
// ---------------------------------------------------------------------------

func TestHaversine_SamePoint(t *testing.T) {
	// Distance from a point to itself is 0.
	d := Haversine(51.5, -0.1, 51.5, -0.1)
	assert.InDelta(t, 0.0, d, 1e-6)
}

func TestHaversine_LondonToManchester(t *testing.T) {
	// London (~51.5, -0.1) to Manchester (~53.48, -2.24) is ~163 miles.
	d := Haversine(51.5, -0.1, 53.48, -2.24)
	assert.InDelta(t, 163.0, d, 5.0)
}

func TestHaversine_Positive(t *testing.T) {
	d := Haversine(0, 0, 1, 1)
	assert.Greater(t, d, 0.0)
}

// ---------------------------------------------------------------------------
// CountryName
// ---------------------------------------------------------------------------

func TestCountryName_KnownCode(t *testing.T) {
	name, ok := CountryName("GB")
	assert.True(t, ok)
	assert.Equal(t, "United Kingdom", name)
}

func TestCountryName_LowercaseCode(t *testing.T) {
	name, ok := CountryName("gb")
	assert.True(t, ok)
	assert.Equal(t, "United Kingdom", name)
}

func TestCountryName_UnknownCode(t *testing.T) {
	_, ok := CountryName("XX")
	assert.False(t, ok)
}

func TestCountryName_EmptyCode(t *testing.T) {
	_, ok := CountryName("")
	assert.False(t, ok)
}

// ---------------------------------------------------------------------------
// OurDomain
// ---------------------------------------------------------------------------

func TestOurDomain_UsersSubdomain(t *testing.T) {
	assert.Equal(t, 1, OurDomain("foo@users.ilovefreegle.org"))
}

func TestOurDomain_GroupsSubdomain(t *testing.T) {
	assert.Equal(t, 1, OurDomain("bar@groups.ilovefreegle.org"))
}

func TestOurDomain_DirectSubdomain(t *testing.T) {
	assert.Equal(t, 1, OurDomain("baz@direct.ilovefreegle.org"))
}

func TestOurDomain_RepublisherDomain(t *testing.T) {
	assert.Equal(t, 1, OurDomain("x@republisher.freegle.in"))
}

func TestOurDomain_ExternalDomain(t *testing.T) {
	assert.Equal(t, 0, OurDomain("user@example.com"))
}

// ---------------------------------------------------------------------------
// TidyName
// ---------------------------------------------------------------------------

func TestTidyName_TrimsSpace(t *testing.T) {
	assert.Equal(t, "Alice", TidyName("  Alice  "))
}

func TestTidyName_StripsDomainSuffix(t *testing.T) {
	// Email-style names: everything from '@' onward is removed.
	assert.Equal(t, "alice", TidyName("alice@example.com"))
}

func TestTidyName_FBUserBecomesAFreegler(t *testing.T) {
	assert.Equal(t, "A freegler", TidyName("FBUser123"))
}

func TestTidyName_EmptyFallback(t *testing.T) {
	assert.Equal(t, "A freegler", TidyName(""))
}

func TestTidyName_32CharYahooIDReplacedWithFreegler(t *testing.T) {
	// 32-char string containing both a letter and a digit is treated as a Yahoo ID.
	yahooID := "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4" // 32 chars, mixed
	assert.Equal(t, "A freegler", TidyName(yahooID))
}

func TestTidyName_LongNameTruncated(t *testing.T) {
	long := "ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJ" // 36 chars
	result := TidyName(long)
	assert.Equal(t, long[0:32]+"...", result)
}

func TestTidyName_NumericNameGetsFullStop(t *testing.T) {
	assert.Equal(t, "12345.", TidyName("12345"))
}

func TestTidyName_StripsTNSuffix(t *testing.T) {
	assert.Equal(t, "John Smith", TidyName("John Smith-g987654"))
}

func TestTidyName_PlainNameUnchanged(t *testing.T) {
	assert.Equal(t, "Bob", TidyName("Bob"))
}

func TestTidyName_32CharLettersOnlyNotTidied(t *testing.T) {
	// 32-char string with only letters should NOT be treated as Yahoo ID.
	letters32 := "AAAABBBBCCCCDDDDEEEEFFFFGGGGHHH" // 32 chars, all letters
	assert.Equal(t, letters32, TidyName(letters32))
}

func TestTidyName_32CharDigitsOnlyNotTidied(t *testing.T) {
	// 32-char string with only digits should NOT be treated as Yahoo ID.
	digits32 := "12345678901234567890123456789012" // 32 chars, all digits
	assert.Equal(t, digits32, TidyName(digits32))
}

func TestTidyName_31CharMixedLettersDigitsNotTidied(t *testing.T) {
	// 31-char string with mixed letters and digits should NOT be treated as Yahoo ID
	// (Yahoo ID check only applies to 32-char strings).
	mixed31 := "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p" // 31 chars, mixed
	result := TidyName(mixed31)
	assert.Equal(t, mixed31, result)
}

func TestTidyName_33CharMixedLettersDigitsTruncated(t *testing.T) {
	// 33-char string should be truncated to 32 chars + "..."
	mixed33 := "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5pqr" // 33 chars
	result := TidyName(mixed33)
	assert.Equal(t, mixed33[:32]+"...", result)
}

func TestTidyName_JustTNSuffixBecomesAFreegler(t *testing.T) {
	// Name that's only a TN suffix should resolve to "A freegler".
	assert.Equal(t, "A freegler", TidyName("-g123456"))
}

func TestTidyName_TNSuffixInMiddle(t *testing.T) {
	// A TN suffix in the middle of a name should be preserved
	// (regex only matches at the end: "-gXXXX$").
	result := TidyName("User-g123-name")
	assert.Equal(t, "User-g123-name", result)
}

func TestTidyName_EmailWithTNSuffix(t *testing.T) {
	// Email stripping happens first, then TN suffix removal.
	result := TidyName("john-g123@example.com")
	// '@' is found, so name becomes "john-g123"
	// Then TN suffix is removed, leaving "john"
	assert.Equal(t, "john", result)
}

func TestTidyName_FBUserWithNumbers(t *testing.T) {
	// "FBUser" anywhere in the name triggers empty -> "A freegler".
	assert.Equal(t, "A freegler", TidyName("MyFBUser123"))
}

func TestTidyName_FBUserAtEnd(t *testing.T) {
	// "FBUser" at end still triggers empty -> "A freegler".
	assert.Equal(t, "A freegler", TidyName("TestFBUser"))
}

func TestTidyName_FBUserAtStart(t *testing.T) {
	// "FBUser" at start still triggers empty -> "A freegler".
	assert.Equal(t, "A freegler", TidyName("FBUser"))
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: FlexUint64
// ---------------------------------------------------------------------------

func TestFlexUint64_LargeNumber(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":18446744073709551615}`), &s))
	assert.Equal(t, FlexUint64(18446744073709551615), s.V)
}

func TestFlexUint64_StringMaxUint64(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"18446744073709551615"}`), &s))
	assert.Equal(t, FlexUint64(18446744073709551615), s.V)
}

func TestFlexUint64_LeadingZeros(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"00042"}`), &s))
	assert.Equal(t, FlexUint64(42), s.V)
}

func TestFlexUint64_NegativeStringError(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.Error(t, json.Unmarshal([]byte(`{"V":"-123"}`), &s))
}

func TestFlexUint64_TrueBoolean(t *testing.T) {
	type S struct{ V FlexUint64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":true}`), &s))
	assert.Equal(t, FlexUint64(1), s.V)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: FlexInt
// ---------------------------------------------------------------------------

func TestFlexInt_MaxInt(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":2147483647}`), &s))
	assert.Equal(t, FlexInt(2147483647), s.V)
}

func TestFlexInt_MinInt(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":-2147483648}`), &s))
	assert.Equal(t, FlexInt(-2147483648), s.V)
}

func TestFlexInt_ZeroString(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"0"}`), &s))
	assert.Equal(t, FlexInt(0), s.V)
}

func TestFlexInt_PositiveString(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"999"}`), &s))
	assert.Equal(t, FlexInt(999), s.V)
}

func TestFlexInt_LeadingZeroString(t *testing.T) {
	type S struct{ V FlexInt }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"0007"}`), &s))
	assert.Equal(t, FlexInt(7), s.V)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: FlexFloat64
// ---------------------------------------------------------------------------

func TestFlexFloat64_Zero(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":0}`), &s))
	assert.Equal(t, FlexFloat64(0), s.V)
}

func TestFlexFloat64_NegativeNumber(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":-3.14159}`), &s))
	assert.InDelta(t, -3.14159, float64(s.V), 1e-9)
}

func TestFlexFloat64_VerySmallNumber(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":0.0000001}`), &s))
	assert.InDelta(t, 0.0000001, float64(s.V), 1e-9)
}

func TestFlexFloat64_VeryLargeNumber(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":123456789.123}`), &s))
	assert.InDelta(t, 123456789.123, float64(s.V), 0.001)
}

func TestFlexFloat64_ScientificNotation(t *testing.T) {
	type S struct{ V FlexFloat64 }
	var s S
	assert.NoError(t, json.Unmarshal([]byte(`{"V":"1.23e-4"}`), &s))
	assert.InDelta(t, 0.000123, float64(s.V), 1e-9)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: RandomHex
// ---------------------------------------------------------------------------

func TestRandomHex_ZeroBytesValid(t *testing.T) {
	result := RandomHex(0)
	assert.Equal(t, "", result)
}

func TestRandomHex_LargeSize(t *testing.T) {
	result := RandomHex(1024)
	assert.Equal(t, 2048, len(result))
	for _, ch := range result {
		assert.Contains(t, "0123456789abcdef", string(ch))
	}
}

func TestRandomHex_Consistency(t *testing.T) {
	// Multiple calls with same size should produce different results
	results := make(map[string]bool)
	for i := 0; i < 10; i++ {
		hex := RandomHex(16)
		assert.False(t, results[hex], "Duplicate RandomHex result")
		results[hex] = true
	}
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: RandomUint64
// ---------------------------------------------------------------------------

func TestRandomUint64_DistributionRange(t *testing.T) {
	// Collect statistics on value distribution
	maxSafe := uint64(1 << 53)
	count := 0
	for i := 0; i < 1000; i++ {
		v := RandomUint64()
		assert.Less(t, v, maxSafe)
		assert.Greater(t, v, uint64(0))
		count++
	}
	assert.Equal(t, 1000, count)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: Blur
// ---------------------------------------------------------------------------

func TestBlur_EdgeLatitude(t *testing.T) {
	// Test with boundary latitude values
	lat, _ := Blur(90, 0, 400)
	assert.Greater(t, lat, 0.0)
	assert.Less(t, lat, 91.0)
}

func TestBlur_NegativeLatitude(t *testing.T) {
	lat, _ := Blur(-45.5, 120.5, 400)
	assert.Less(t, lat, 0.0)
	assert.InDelta(t, -45.5, lat, 0.1)
}

func TestBlur_EdgitudeLongitude(t *testing.T) {
	_, lng := Blur(0, 180, 400)
	assert.GreaterOrEqual(t, lng, -180.0)
	assert.LessOrEqual(t, lng, 180.0)
}

func TestBlur_NegativeLongitude(t *testing.T) {
	_, lng := Blur(0, -180, 400)
	assert.GreaterOrEqual(t, lng, -180.0)
	assert.LessOrEqual(t, lng, 180.0)
}

func TestBlur_LargeDistance(t *testing.T) {
	lat, lng := Blur(51.5, -0.1, 50000)
	// Blurring by 50km should still produce valid coordinates
	assert.GreaterOrEqual(t, lat, -90.0)
	assert.LessOrEqual(t, lat, 90.0)
	assert.GreaterOrEqual(t, lng, -180.0)
	assert.LessOrEqual(t, lng, 180.0)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: Haversine
// ---------------------------------------------------------------------------

func TestHaversine_LargeDistance(t *testing.T) {
	// London (51.5, -0.1) to Sydney (-33.87, 151.21) is ~10,500 miles
	d := Haversine(51.5, -0.1, -33.87, 151.21)
	assert.Greater(t, d, 10000.0)
	assert.Less(t, d, 11000.0)
}

func TestHaversine_Antipodal(t *testing.T) {
	// Haversine from North to South pole should be ~12,430 miles
	d := Haversine(90, 0, -90, 0)
	assert.Greater(t, d, 12000.0)
	assert.Less(t, d, 13000.0)
}

func TestHaversine_Symmetric(t *testing.T) {
	// Distance from A to B should equal distance from B to A
	d1 := Haversine(51.5, -0.1, 53.48, -2.24)
	d2 := Haversine(53.48, -2.24, 51.5, -0.1)
	assert.InDelta(t, d1, d2, 1e-6)
}

func TestHaversine_SmallDistance(t *testing.T) {
	// Two nearby points should have small distance
	d := Haversine(51.5, -0.1, 51.5001, -0.1001)
	assert.Greater(t, d, 0.0)
	assert.Less(t, d, 0.1)
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: CountryName
// ---------------------------------------------------------------------------

func TestCountryName_MixedCase(t *testing.T) {
	name, ok := CountryName("gB")
	assert.True(t, ok)
	assert.Equal(t, "United Kingdom", name)
}

func TestCountryName_VariousCodes(t *testing.T) {
	testCases := []struct {
		code     string
		expected string
		found    bool
	}{
		{"US", "United States", true},
		{"FR", "France", true},
		{"DE", "Germany", true},
		{"JP", "Japan", true},
		{"ZZ", "", false},
		{"", "", false},
	}

	for _, tc := range testCases {
		name, ok := CountryName(tc.code)
		assert.Equal(t, tc.found, ok, "for code %s", tc.code)
		if tc.found {
			assert.Equal(t, tc.expected, name)
		}
	}
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: OurDomain
// ---------------------------------------------------------------------------

func TestOurDomain_MultipleMatches(t *testing.T) {
	// Should return 1 even if multiple patterns would match
	assert.Equal(t, 1, OurDomain("test@users.ilovefreegle.org"))
}

func TestOurDomain_CaseSensitivity(t *testing.T) {
	// OurDomain uses strings.Contains which is case-sensitive; uppercase domain does not match.
	assert.Equal(t, 0, OurDomain("test@USERS.ILOVEFREEGLE.ORG"))
	assert.Equal(t, 0, OurDomain("test@EXTERNAL.com"))
}

func TestOurDomain_PartialMatch(t *testing.T) {
	// Should match substrings
	assert.Equal(t, 1, OurDomain("prefix_users.ilovefreegle.org_suffix"))
}

func TestOurDomain_EmptyEmail(t *testing.T) {
	assert.Equal(t, 0, OurDomain(""))
}

// ---------------------------------------------------------------------------
// Additional Comprehensive Tests: TidyName
// ---------------------------------------------------------------------------

func TestTidyName_MultipleSpaces(t *testing.T) {
	assert.Equal(t, "John", TidyName("   John   "))
}

func TestTidyName_TabsAndNewlines(t *testing.T) {
	assert.Equal(t, "Jane", TidyName("\t\nJane\n\t"))
}

func TestTidyName_OnlyWhitespace(t *testing.T) {
	assert.Equal(t, "A freegler", TidyName("   "))
}

func TestTidyName_EmailDomainOnly(t *testing.T) {
	assert.Equal(t, "A freegler", TidyName("@example.com"))
}

func TestTidyName_MultipleAtSigns(t *testing.T) {
	// Should stop at first @ sign
	assert.Equal(t, "user", TidyName("user@first.com@second.com"))
}

func TestTidyName_SpecialCharacters(t *testing.T) {
	assert.Equal(t, "John-Smith", TidyName("John-Smith"))
}

func TestTidyName_UnicodeCharacters(t *testing.T) {
	result := TidyName("Jörn")
	assert.NotEmpty(t, result)
}

func TestTidyName_VeryLongNameTruncatedCorrectly(t *testing.T) {
	// 100-character name should be truncated to 32 + "..."
	longName := strings.Repeat("a", 100)
	result := TidyName(longName)
	assert.Equal(t, strings.Repeat("a", 32)+"...", result)
}

func TestTidyName_NegativeNumbers(t *testing.T) {
	// Negative number as name should get full stop
	assert.Equal(t, "-123.", TidyName("-123"))
}

func TestTidyName_AllNumbers(t *testing.T) {
	assert.Equal(t, "0.", TidyName("0"))
	assert.Equal(t, "12345.", TidyName("12345"))
}

func TestTidyName_TNSuffixMultiple(t *testing.T) {
	// Multiple TN suffixes should all be stripped
	assert.Equal(t, "Name", TidyName("Name-g123-g456"))
}

func TestTidyName_ComplexChain(t *testing.T) {
	// Email with TN suffix and long name.
	// Strip email suffix → "verylongnamethatexceedsthirtytwocharacters-g123" (47 chars)
	// Truncation (len>32) fires BEFORE TN strip → first 32 chars + "..."
	// TN regex won't match "-g123" inside "..."-terminated string, so result stays truncated.
	result := TidyName("verylongnamethatexceedsthirtytwocharacters-g123@test.com")
	assert.Equal(t, "verylongnamethatexceedsthirtytwo"+"...", result)
}

func TestTidyName_FBUserCaseSensitivity(t *testing.T) {
	// The code does `strings.Contains` which is case-sensitive
	assert.NotEqual(t, "A freegler", TidyName("fbuser"))
	assert.NotEqual(t, "A freegler", TidyName("FBUSER"))
}

func TestTidyName_NumericAfterTrim(t *testing.T) {
	// Name that becomes numeric after trimming spaces
	assert.Equal(t, "123.", TidyName("  123  "))
}
