package utils

// utils_gaps_test.go adds supplementary test cases for paths that the main
// utils_test.go does not explicitly exercise.  Coverage was already at 95.8%
// before this file; the remaining uncovered lines are either dead code or the
// crypto/rand v==0 edge case documented below.

import (
	"math"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// RandomHex — n=1 edge case (not tested in utils_test.go which starts at n=4)
// ---------------------------------------------------------------------------

func TestRandomHex_OneByte(t *testing.T) {
	got := RandomHex(1)
	assert.Equal(t, 2, len(got), "RandomHex(1) should produce exactly 2 hex chars")
	for _, ch := range got {
		assert.Contains(t, "0123456789abcdef", string(ch))
	}
}

// ---------------------------------------------------------------------------
// RandomUint64 — document the v==0 branch
//
// The function masks a 64-bit random value to 53 bits; if the result is zero
// it substitutes 1.  With crypto/rand this event has probability ~1.1e-16 per
// call and cannot be triggered deterministically in a pure unit test without
// modifying the production code to accept an io.Reader.  We document the
// branch here so future readers know it is intentional, not forgotten.
// ---------------------------------------------------------------------------

// TestRandomUint64_ZeroMaskBranchUntestable skips unconditionally so that
// the test suite still passes, but records that the `v = 1` substitution in
// RandomUint64 cannot be reached without injecting a mocked crypto/rand reader.
func TestRandomUint64_ZeroMaskBranchUntestable(t *testing.T) {
	// TODO: latent coverage gap — the `if v == 0 { v = 1 }` branch in
	// RandomUint64 is unreachable without a mock io.Reader replacing
	// crypto/rand.  Refactor RandomUint64 to accept an io.Reader to enable
	// deterministic testing of this path.
	t.Skip("v==0 branch has probability ~1e-16 per call; not testable without mocking crypto/rand")
}

// ---------------------------------------------------------------------------
// NilIfZero — boundary values
// ---------------------------------------------------------------------------

func TestNilIfZero_MaxUint64(t *testing.T) {
	got := NilIfZero(math.MaxUint64)
	assert.Equal(t, uint64(math.MaxUint64), got)
}

func TestNilIfZero_One(t *testing.T) {
	got := NilIfZero(1)
	assert.Equal(t, uint64(1), got)
}

// ---------------------------------------------------------------------------
// Blur — both-negative coordinates (Southern + Western hemisphere)
// ---------------------------------------------------------------------------

func TestBlur_SouthernAndWesternHemisphere(t *testing.T) {
	// Buenos Aires: lat≈-34.6, lng≈-58.4
	lat, lng := Blur(-34.6, -58.4, 400)
	// Should stay in the same vicinity; blurring by 400m moves at most ~0.01°
	assert.InDelta(t, -34.6, lat, 0.05)
	assert.InDelta(t, -58.4, lng, 0.05)
	// Coordinates must still be valid
	assert.GreaterOrEqual(t, lat, -90.0)
	assert.LessOrEqual(t, lat, 0.0)
}

func TestBlur_NorthernAndEasternHemisphere(t *testing.T) {
	// Tokyo: lat≈35.7, lng≈139.7
	lat, lng := Blur(35.7, 139.7, 400)
	assert.InDelta(t, 35.7, lat, 0.05)
	assert.InDelta(t, 139.7, lng, 0.05)
}

// ---------------------------------------------------------------------------
// Haversine — symmetry with Southern-hemisphere pair
// ---------------------------------------------------------------------------

func TestHaversine_SouthernHemispherePair(t *testing.T) {
	// Sydney to Melbourne: ~440 miles
	d := Haversine(-33.87, 151.21, -37.81, 144.96)
	assert.InDelta(t, 440.0, d, 20.0)
}

func TestHaversine_SymmetricSouthernHemisphere(t *testing.T) {
	d1 := Haversine(-33.87, 151.21, -37.81, 144.96)
	d2 := Haversine(-37.81, 144.96, -33.87, 151.21)
	assert.InDelta(t, d1, d2, 1e-6)
}

// ---------------------------------------------------------------------------
// CountryName — additional boundary codes
// ---------------------------------------------------------------------------

func TestCountryName_ThreeCharCodeNotFound(t *testing.T) {
	_, ok := CountryName("GBR")
	assert.False(t, ok, "3-char code should not be found (map uses 2-char alpha-2)")
}

func TestCountryName_NumericCode(t *testing.T) {
	_, ok := CountryName("44")
	assert.False(t, ok, "numeric code should not be found")
}

func TestCountryName_SingleChar(t *testing.T) {
	_, ok := CountryName("G")
	assert.False(t, ok)
}

// ---------------------------------------------------------------------------
// OurDomain — malformed / non-email inputs
// ---------------------------------------------------------------------------

func TestOurDomain_PlainDomainNoBracketing(t *testing.T) {
	// A bare domain string (no @) that happens to contain our domain name
	// still matches because OurDomain uses strings.Contains, not email parsing.
	assert.Equal(t, 1, OurDomain("users.ilovefreegle.org"))
}

func TestOurDomain_NonEmailString(t *testing.T) {
	// Completely unrelated plain string
	assert.Equal(t, 0, OurDomain("hello world"))
}

func TestOurDomain_JustAtSign(t *testing.T) {
	assert.Equal(t, 0, OurDomain("@"))
}

// ---------------------------------------------------------------------------
// TidyName — TN suffix applied to an email-format name
// ---------------------------------------------------------------------------

func TestTidyName_EmailWithDoubleTNSuffix(t *testing.T) {
	// Email strip happens first: "user-g123-g456@example.com" → "user-g123-g456"
	// First tnRegexp pass strips "-g456" → "user-g123"
	// Second tnRegexp pass strips "-g123" → "user"
	result := TidyName("user-g123-g456@example.com")
	assert.Equal(t, "user", result)
}
