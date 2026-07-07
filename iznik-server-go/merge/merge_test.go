package merge

import (
	"regexp"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestObfuscateEmailEmpty(t *testing.T) {
	// Empty string must not panic (no '@' to split on).
	assert.Equal(t, "", obfuscateEmail(""))
}

func TestObfuscateEmailNoAtSign(t *testing.T) {
	// No '@' means it isn't an email — return it unchanged rather than
	// emitting a broken obfuscation.
	assert.Equal(t, "not-an-email", obfuscateEmail("not-an-email"))
}

func TestObfuscateEmailSingleCharLocal(t *testing.T) {
	// Local-part "a" is one char — the <=1 branch appends "***" after the
	// single character rather than replacing it.
	assert.Equal(t, "a***@example.com", obfuscateEmail("a@example.com"))
}

func TestObfuscateEmailTypicalAddress(t *testing.T) {
	// "test@example.com" → first char kept, remaining chars replaced 1-for-1
	// with stars, domain preserved.
	got := obfuscateEmail("test@example.com")
	assert.Equal(t, "t***@example.com", got)
}

func TestObfuscateEmailPreservesDomain(t *testing.T) {
	// The domain portion is never modified, including subdomains.
	got := obfuscateEmail("alice@users.ilovefreegle.org")
	assert.Equal(t, "a****@users.ilovefreegle.org", got)
}

func TestObfuscateEmailLongLocalPart(t *testing.T) {
	// Star count should exactly equal (len(local) - 1) so the output length
	// matches the input length.
	input := "abcdefghijk@example.com"
	got := obfuscateEmail(input)
	assert.Equal(t, "a**********@example.com", got)
	assert.Equal(t, len(input), len(got), "obfuscation must preserve total length")
}

func TestObfuscateEmailSplitNHandlesMultipleAtSigns(t *testing.T) {
	// SplitN(..., 2) means a stray second '@' stays in the domain part.
	got := obfuscateEmail("first@second@third")
	assert.Equal(t, "f****@second@third", got)
}

func TestObfuscateEmailEmptyLocalPart(t *testing.T) {
	// "@example.com" — local is empty (len <= 1), so we hit the short-local
	// branch and get "***@example.com" (no leading character).
	got := obfuscateEmail("@example.com")
	assert.Equal(t, "***@example.com", got)
}

func TestGenerateUIDFormat(t *testing.T) {
	// 16 random bytes hex-encoded → 32 lowercase hex chars.
	uid := generateUID()
	assert.Len(t, uid, 32)
	assert.Regexp(t, regexp.MustCompile(`^[0-9a-f]{32}$`), uid)
}

func TestGenerateUIDIsRandom(t *testing.T) {
	// Two successive calls must (with overwhelming probability) differ.
	// If they don't, crypto/rand is broken or we accidentally seeded a
	// fixed source.
	seen := make(map[string]bool)
	for i := 0; i < 20; i++ {
		uid := generateUID()
		assert.False(t, seen[uid], "generateUID produced duplicate: %s", uid)
		seen[uid] = true
	}
}
