package emailtracking

// Pure-logic coverage for two helpers behind the compact-link redirect's
// safety checks: repairing a known email-corruption pattern in stored
// destination URLs, and validating the HMAC signature that lets a tracked
// link redirect to an off-domain destination. Neither needs a DB.

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// RepairDoubledSiteURL
// ---------------------------------------------------------------------------

func TestRepairDoubledSiteURL(t *testing.T) {
	tests := []struct {
		name string
		in   string
		want string
	}{
		{
			name: "no scheme separator at all is returned unchanged",
			in:   "not-a-url",
			want: "not-a-url",
		},
		{
			name: "single well-formed URL is untouched",
			in:   "https://www.ilovefreegle.org/stories",
			want: "https://www.ilovefreegle.org/stories",
		},
		{
			name: "doubled https prefix is stripped to the inner URL",
			in:   "https://www.ilovefreegle.orghttps://www.ilovefreegle.org/stories",
			want: "https://www.ilovefreegle.org/stories",
		},
		{
			name: "doubled prefix with inner http scheme is stripped",
			in:   "https://www.ilovefreegle.orghttp://example.com/x",
			want: "http://example.com/x",
		},
		{
			name: "embedded scheme preceded by a path separator is left alone",
			// The inner "https://" here is a legitimate query-string value, not
			// a doubled prefix, because a '/' sits between the host and it.
			in:   "https://example.com/redirect?url=https://other.com",
			want: "https://example.com/redirect?url=https://other.com",
		},
		{
			name: "embedded scheme preceded by a query separator is left alone",
			in:   "https://example.com?next=https://other.com",
			want: "https://example.com?next=https://other.com",
		},
		{
			name: "embedded scheme preceded by a fragment separator is left alone",
			in:   "https://example.com#https://other.com",
			want: "https://example.com#https://other.com",
		},
		{
			name: "inner scheme found at offset zero is not treated as doubled",
			// rest starts immediately with "https://" (i==0), which the
			// implementation deliberately excludes via the i>0 guard.
			in:   "https://https://example.com/x",
			want: "https://https://example.com/x",
		},
		{
			name: "empty string has no scheme separator",
			in:   "",
			want: "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.want, RepairDoubledSiteURL(tt.in))
		})
	}
}

// ---------------------------------------------------------------------------
// hasValidLinkSignature
// ---------------------------------------------------------------------------

// signFor mirrors the production HMAC computation so tests can mint a valid
// signature without hardcoding a secret-specific expected value.
func signFor(secret, rawURL string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("redirect:" + rawURL))
	return hex.EncodeToString(mac.Sum(nil))
}

func TestHasValidLinkSignature(t *testing.T) {
	const url = "https://community-news.example.org/item/42"

	t.Run("empty raw URL is always invalid", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "some-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		assert.False(t, hasValidLinkSignature("", signFor("some-secret", "")))
	})

	t.Run("empty signature is always invalid", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "some-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		assert.False(t, hasValidLinkSignature(url, ""))
	})

	t.Run("no secret configured anywhere returns false", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		sig := signFor("whatever", url)
		assert.False(t, hasValidLinkSignature(url, sig))
	})

	t.Run("valid signature under AMP_SECRET returns true", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "primary-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		sig := signFor("primary-secret", url)
		assert.True(t, hasValidLinkSignature(url, sig))
	})

	t.Run("wrong signature under AMP_SECRET returns false", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "primary-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		sig := signFor("a-different-secret", url)
		assert.False(t, hasValidLinkSignature(url, sig))
	})

	t.Run("falls back to FREEGLE_AMP_SECRET when AMP_SECRET is unset", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "")
		t.Setenv("FREEGLE_AMP_SECRET", "fallback-secret")
		sig := signFor("fallback-secret", url)
		assert.True(t, hasValidLinkSignature(url, sig))
	})

	t.Run("signature minted for a different URL does not validate", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "primary-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		sig := signFor("primary-secret", "https://other.example.org/different")
		assert.False(t, hasValidLinkSignature(url, sig))
	})

	t.Run("malformed non-hex signature is rejected, not a panic", func(t *testing.T) {
		t.Setenv("AMP_SECRET", "primary-secret")
		t.Setenv("FREEGLE_AMP_SECRET", "")
		assert.False(t, hasValidLinkSignature(url, "not-a-hex-signature"))
	})
}
