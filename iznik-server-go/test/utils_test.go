package test

import (
	"encoding/hex"
	"encoding/json"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"math"
	"regexp"
	"testing"
)

func TestTidyName(t *testing.T) {
	assert.Equal(t, "test", utils.TidyName("test@test.com"))
	assert.Equal(t, "test", utils.TidyName(" test "))
	assert.Equal(t, "1.", utils.TidyName("1"))
	assert.Equal(t, "A freegler", utils.TidyName("01234567890abcdef01234567890abcd"))
	assert.Equal(t, "A freegler", utils.TidyName(" "))
	assert.Equal(t, "A freegler", utils.TidyName(" "))
	assert.Equal(t, "A freegler", utils.TidyName("FBUser123.4"))
	assert.Equal(t, "test", utils.TidyName("test-g123"))
	assert.Equal(t, "01234567890abcdef01234567890abcd...", utils.TidyName("01234567890abcdef01234567890abcd123"))
}

func TestBlurBasic(t *testing.T) {
	// Test that blur returns different coordinates when blurring.
	lat, lng := utils.Blur(51.5074, -0.1278, 1000)

	// Should not be exactly the same.
	assert.NotEqual(t, 51.5074, lat)
	assert.NotEqual(t, -0.1278, lng)
}

func TestBlurDeterministic(t *testing.T) {
	// Same input should produce same output.
	lat1, lng1 := utils.Blur(51.5074, -0.1278, 1000)
	lat2, lng2 := utils.Blur(51.5074, -0.1278, 1000)

	assert.Equal(t, lat1, lat2)
	assert.Equal(t, lng1, lng2)
}

func TestBlurZeroDistance(t *testing.T) {
	// Zero blur distance should return approximately the same coordinates.
	lat, lng := utils.Blur(51.5074, -0.1278, 0)

	assert.InDelta(t, 51.507, lat, 0.001)
	assert.InDelta(t, -0.128, lng, 0.001)
}

func TestBlurInvalidCoordinates(t *testing.T) {
	// Invalid coordinates should return Dunsop Bridge (center of Britain).
	lat, lng := utils.Blur(200, 500, 0)

	assert.InDelta(t, 53.945, lat, 0.001)
	assert.InDelta(t, -2.521, lng, 0.001)
}

func TestBlurPrecision(t *testing.T) {
	// Should return coordinates with limited precision (3 decimal places).
	lat, lng := utils.Blur(51.5074567890, -0.127812345, 100)

	// Check it's rounded.
	latRounded := math.Round(lat*1000) / 1000
	lngRounded := math.Round(lng*1000) / 1000
	assert.Equal(t, lat, latRounded)
	assert.Equal(t, lng, lngRounded)
}

func TestOurDomainTrue(t *testing.T) {
	assert.Equal(t, 1, utils.OurDomain("test@users.ilovefreegle.org"))
	assert.Equal(t, 1, utils.OurDomain("test@groups.ilovefreegle.org"))
	assert.Equal(t, 1, utils.OurDomain("test@direct.ilovefreegle.org"))
	assert.Equal(t, 1, utils.OurDomain("test@republisher.freegle.in"))
}

func TestOurDomainFalse(t *testing.T) {
	assert.Equal(t, 0, utils.OurDomain("test@gmail.com"))
	assert.Equal(t, 0, utils.OurDomain("test@yahoo.com"))
	assert.Equal(t, 0, utils.OurDomain("test@example.org"))
}

func TestOurDomainPartialMatch(t *testing.T) {
	// Should match if domain appears anywhere in email.
	assert.Equal(t, 1, utils.OurDomain("something-users.ilovefreegle.org@proxy.com"))
}

func TestRandomHexLength(t *testing.T) {
	// RandomHex(n) must produce 2n lowercase hex characters.
	for _, n := range []int{0, 1, 4, 8, 16, 32} {
		out := utils.RandomHex(n)
		assert.Len(t, out, n*2)
		// Round-trip: result must decode back to n bytes.
		decoded, err := hex.DecodeString(out)
		assert.NoError(t, err)
		assert.Len(t, decoded, n)
	}
}

func TestRandomHexDistinct(t *testing.T) {
	// Two calls should almost never return the same 16-byte value.
	a := utils.RandomHex(16)
	b := utils.RandomHex(16)
	assert.NotEqual(t, a, b)
}

func TestRandomUint64NonZeroAndDistinct(t *testing.T) {
	// Must never return 0 (0 is a sentinel for "no persistent token" in
	// auth.go:46) and must be well-distributed across the uint64 range so
	// collisions per user are cryptographically improbable.
	seen := map[uint64]bool{}
	for i := 0; i < 64; i++ {
		v := utils.RandomUint64()
		assert.NotEqual(t, uint64(0), v)
		seen[v] = true
	}
	assert.Equal(t, 64, len(seen), "RandomUint64 must produce distinct values across calls")
}

func TestNilIfEmpty(t *testing.T) {
	assert.Nil(t, utils.NilIfEmpty(""))
	assert.Equal(t, "hello", utils.NilIfEmpty("hello"))
	// A single space is not empty — must pass through.
	assert.Equal(t, " ", utils.NilIfEmpty(" "))
}

func TestNilIfZero(t *testing.T) {
	assert.Nil(t, utils.NilIfZero(0))
	assert.Equal(t, uint64(1), utils.NilIfZero(1))
	assert.Equal(t, uint64(999999), utils.NilIfZero(999999))
}

func TestHaversineSamePoint(t *testing.T) {
	// Distance from a point to itself is zero.
	d := utils.Haversine(51.5074, -0.1278, 51.5074, -0.1278)
	assert.InDelta(t, 0, d, 0.001)
}

func TestHaversineKnownDistance(t *testing.T) {
	// London (51.5074, -0.1278) to Paris (48.8566, 2.3522) is ~213 miles.
	d := utils.Haversine(51.5074, -0.1278, 48.8566, 2.3522)
	assert.InDelta(t, 213, d, 5)
}

func TestHaversineSymmetric(t *testing.T) {
	// Haversine must be symmetric: d(A,B) == d(B,A).
	a := utils.Haversine(51.5074, -0.1278, 55.9533, -3.1883) // London → Edinburgh
	b := utils.Haversine(55.9533, -3.1883, 51.5074, -0.1278)
	assert.InDelta(t, a, b, 0.001)
}

func TestCountryNameKnown(t *testing.T) {
	name, ok := utils.CountryName("GB")
	assert.True(t, ok)
	assert.Equal(t, "United Kingdom", name)

	name, ok = utils.CountryName("FR")
	assert.True(t, ok)
	assert.Equal(t, "France", name)
}

func TestCountryNameLowercase(t *testing.T) {
	// Lookup must be case-insensitive.
	name, ok := utils.CountryName("gb")
	assert.True(t, ok)
	assert.Equal(t, "United Kingdom", name)
}

func TestCountryNameUnknown(t *testing.T) {
	name, ok := utils.CountryName("ZZ")
	assert.False(t, ok)
	assert.Equal(t, "", name)

	// Empty string is not a valid code.
	name, ok = utils.CountryName("")
	assert.False(t, ok)
	assert.Equal(t, "", name)
}

func TestFlexUint64FromNumber(t *testing.T) {
	var v utils.FlexUint64
	assert.NoError(t, json.Unmarshal([]byte("42"), &v))
	assert.Equal(t, utils.FlexUint64(42), v)
}

func TestFlexUint64FromString(t *testing.T) {
	// Clients that serialise IDs as strings must still be accepted.
	var v utils.FlexUint64
	assert.NoError(t, json.Unmarshal([]byte(`"42"`), &v))
	assert.Equal(t, utils.FlexUint64(42), v)
}

func TestFlexUint64FromEmptyAndNull(t *testing.T) {
	var v utils.FlexUint64 = 99
	assert.NoError(t, json.Unmarshal([]byte(`""`), &v))
	assert.Equal(t, utils.FlexUint64(0), v)

	v = 99
	assert.NoError(t, json.Unmarshal([]byte("null"), &v))
	assert.Equal(t, utils.FlexUint64(0), v)
}

func TestFlexUint64FromInvalid(t *testing.T) {
	var v utils.FlexUint64
	assert.Error(t, json.Unmarshal([]byte(`"not-a-number"`), &v))
}

func TestFlexIntFromNumberAndString(t *testing.T) {
	var v utils.FlexInt
	assert.NoError(t, json.Unmarshal([]byte("-7"), &v))
	assert.Equal(t, utils.FlexInt(-7), v)

	assert.NoError(t, json.Unmarshal([]byte(`"24"`), &v))
	assert.Equal(t, utils.FlexInt(24), v)
}

func TestFlexIntFromEmptyAndNull(t *testing.T) {
	var v utils.FlexInt = 99
	assert.NoError(t, json.Unmarshal([]byte(`""`), &v))
	assert.Equal(t, utils.FlexInt(0), v)

	v = 99
	assert.NoError(t, json.Unmarshal([]byte("null"), &v))
	assert.Equal(t, utils.FlexInt(0), v)
}

func TestFlexIntFromInvalid(t *testing.T) {
	var v utils.FlexInt
	assert.Error(t, json.Unmarshal([]byte(`"abc"`), &v))
}

func TestFlexFloat64FromNumberAndString(t *testing.T) {
	var v utils.FlexFloat64
	assert.NoError(t, json.Unmarshal([]byte("3.14"), &v))
	assert.InDelta(t, 3.14, float64(v), 0.0001)

	assert.NoError(t, json.Unmarshal([]byte(`"2.5"`), &v))
	assert.InDelta(t, 2.5, float64(v), 0.0001)
}

func TestFlexFloat64FromEmptyAndNull(t *testing.T) {
	var v utils.FlexFloat64 = 9.9
	assert.NoError(t, json.Unmarshal([]byte(`""`), &v))
	assert.Equal(t, utils.FlexFloat64(0), v)

	v = 9.9
	assert.NoError(t, json.Unmarshal([]byte("null"), &v))
	assert.Equal(t, utils.FlexFloat64(0), v)
}

func TestFlexFloat64FromInvalid(t *testing.T) {
	var v utils.FlexFloat64
	assert.Error(t, json.Unmarshal([]byte(`"not-a-float"`), &v))
}

func TestTidyNameYahooHexID(t *testing.T) {
	// 32-char hex-like Yahoo IDs are rewritten to the "A freegler" fallback
	// because the regex matches strings with both letters and digits.
	assert.Equal(t, "A freegler", utils.TidyName("abcdef0123456789abcdef0123456789"))
}

func TestTidyNamePureAlphaNotYahoo(t *testing.T) {
	// 32 chars all-letters doesn't match the Yahoo-ID regex, so TidyName
	// keeps it (and does not append "...").
	allAlpha := "abcdefghijklmnopqrstuvwxyzabcdef"
	assert.Len(t, allAlpha, 32)
	assert.Equal(t, allAlpha, utils.TidyName(allAlpha))
}

func TestTidyNameTNSuffixStripped(t *testing.T) {
	// -gNN TrashNothing suffix is hidden.
	assert.Equal(t, "alice", utils.TidyName("alice-g12345"))
}

func TestGenerateNameValid(t *testing.T) {
	// GenerateName must return a non-empty lowercase alphabetic string.
	alpha := regexp.MustCompile(`^[a-z]+$`)
	for i := 0; i < 50; i++ {
		name := utils.GenerateName()
		if !assert.NotEmpty(t, name) {
			return
		}
		// Either a generated word or the "A freegler" fallback.
		if name == "A freegler" {
			continue
		}
		assert.True(t, alpha.MatchString(name), "expected lowercase alpha, got %q", name)
		assert.LessOrEqual(t, len(name), 10)
	}
}
