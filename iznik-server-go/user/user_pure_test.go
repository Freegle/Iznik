package user

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// CanonicalizeEmail
// ---------------------------------------------------------------------------

func TestCanonicalizeEmail(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  string
	}{
		// Basic normalisation
		{"lowercase", "User@Example.COM", "user@example.com"},
		{"trims spaces", "  user@example.com  ", "user@example.com"},
		{"already canonical", "user@example.com", "user@example.com"},

		// Dot removal from local part
		{"dots in local", "u.s.e.r@example.com", "user@example.com"},
		{"single dot", "first.last@example.com", "firstlast@example.com"},
		{"multiple dots", "a.b.c.d@example.com", "abcd@example.com"},
		{"no dots", "user@example.com", "user@example.com"},
		{"dots and uppercase", "First.Last@Gmail.COM", "firstlast@gmail.com"},

		// Plus-addressing stripped
		{"plus tag", "user+tag@example.com", "user@example.com"},
		{"plus with dots", "first.last+tag@example.com", "firstlast@example.com"},
		{"plus only", "user+@example.com", "user@example.com"},
		{"multiple plusses strips at first", "user+a+b@example.com", "user@example.com"},
		{"plus at start of local", "+tag@example.com", "@example.com"},

		// No @ — returned unchanged after trim+lower
		{"no at sign", "notanemail", "notanemail"},
		{"empty string", "", ""},
		{"spaces only", "   ", ""},

		// Domain part preserved as-is after lowercasing
		{"subdomain preserved", "user@mail.example.co.uk", "user@mail.example.co.uk"},
		{"domain case", "user@Gmail.Com", "user@gmail.com"},

		// Edge: dots in domain are NOT removed (only local part is de-dotted)
		{"dots in domain stay", "user@exa.mple.com", "user@exa.mple.com"},

		// Multiple @ — SplitN(…,2) keeps only first split; second @ goes to domain
		{"two at signs", "user@extra@example.com", "user@extra@example.com"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.want, CanonicalizeEmail(tt.input))
		})
	}
}

func TestCanonicalizeEmail_Idempotent(t *testing.T) {
	// Applying canonicalization twice should yield the same result.
	addrs := []string{
		"First.Last+tag@Gmail.COM",
		"user@example.com",
		"a.b.c+d@sub.domain.org",
	}
	for _, addr := range addrs {
		once := CanonicalizeEmail(addr)
		twice := CanonicalizeEmail(once)
		assert.Equal(t, once, twice, "not idempotent for %q", addr)
	}
}

// ---------------------------------------------------------------------------
// ReverseString / reverseString
// ---------------------------------------------------------------------------

func TestReverseString(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  string
	}{
		{"empty", "", ""},
		{"single char", "a", "a"},
		{"two chars", "ab", "ba"},
		{"palindrome", "racecar", "racecar"},
		{"simple word", "hello", "olleh"},
		{"phrase", "hello world", "dlrow olleh"},
		{"digits", "12345", "54321"},
		{"mixed", "abc123", "321cba"},
		{"spaces only", "   ", "   "},
		// Unicode — reversal must operate on runes, not bytes
		{"unicode accented", "café", "éfac"},
		{"emoji", "ab😀cd", "dc😀ba"},
		{"cyrillic", "привет", "тевирп"},
		{"CJK", "日本語", "語本日"},
		{"multi-byte single rune", "你好", "好你"},
		// Long string
		{"long ascii", strings.Repeat("abcd", 25), strings.Repeat("dcba", 25)},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			// Test exported wrapper
			assert.Equal(t, tt.want, ReverseString(tt.input), "ReverseString(%q)", tt.input)
			// Test internal function directly (same package)
			assert.Equal(t, tt.want, reverseString(tt.input), "reverseString(%q)", tt.input)
		})
	}
}

func TestReverseString_DoubleReverseIsIdentity(t *testing.T) {
	inputs := []string{
		"",
		"x",
		"hello",
		"café latte",
		"日本語テスト",
		"emoji 😀 test",
		strings.Repeat("abcde", 10),
	}
	for _, s := range inputs {
		assert.Equal(t, s, ReverseString(ReverseString(s)), "double-reverse of %q", s)
	}
}

func TestReverseString_PreservesLength(t *testing.T) {
	// Rune count should be preserved; byte count may differ for multi-byte runes
	// but rune count must be identical.
	inputs := []string{"hello", "café", "日本語", "ab😀cd"}
	for _, s := range inputs {
		r := []rune(s)
		rev := []rune(ReverseString(s))
		assert.Equal(t, len(r), len(rev), "rune length changed for %q", s)
	}
}

// ---------------------------------------------------------------------------
// SanitiseEmailLocal
// ---------------------------------------------------------------------------

func TestSanitiseEmailLocal(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  string
	}{
		// Stripping non-alphanumeric
		{"strips spaces", "hello world", "helloworld"},
		{"strips hyphens", "first-last", "firstlast"},
		{"strips dots", "first.last", "firstlast"},
		{"strips underscores", "user_name", "username"},
		{"strips plus", "user+tag", "usertag"},
		{"strips all special", "!@#$%^&*()", ""},
		{"mixed alnum and special", "abc!def@ghi", "abcdefghi"},

		// Lowercasing
		{"uppercase", "ALICE", "alice"},
		{"mixed case", "AlIcE", "alice"},
		{"digits unchanged", "abc123", "abc123"},
		{"digits only", "1234567890", "1234567890"},

		// Truncation to 16 chars
		{"exactly 16 chars", "abcdefghijklmnop", "abcdefghijklmnop"},
		{"17 chars truncated", "abcdefghijklmnopq", "abcdefghijklmnop"},
		{"long string truncated", strings.Repeat("a", 100), strings.Repeat("a", 16)},
		{"16 alnum after stripping", "abc!def!ghi!jklm", "abcdefghijklm"},

		// Edge cases
		{"empty string", "", ""},
		{"only non-alnum", "---...", ""},
		{"single char", "A", "a"},
		{"spaces only", "   ", ""},

		// After stripping, result may be under 16
		{"short alnum stays", "hi", "hi"},
		{"numbers at boundary", "1234567890123456", "1234567890123456"},
		{"one over boundary with special", "123456789012345!", "123456789012345"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.want, SanitiseEmailLocal(tt.input))
		})
	}
}

func TestSanitiseEmailLocal_MaxLen(t *testing.T) {
	// Result must never exceed 16 characters.
	inputs := []string{
		strings.Repeat("a", 200),
		strings.Repeat("Z", 17),
		"this-is-a-name-with-hyphens-and-spaces everywhere",
		"!!!!!" + strings.Repeat("b", 20),
	}
	for _, s := range inputs {
		result := SanitiseEmailLocal(s)
		assert.LessOrEqual(t, len(result), 16, "result too long for input %q: got %q", s, result)
	}
}

func TestSanitiseEmailLocal_OnlyAlphanumInOutput(t *testing.T) {
	// Output must only contain [a-z0-9].
	inputs := []string{
		"Hello, World!",
		"user@example.com",
		"First Last",
		"abc-def_ghi.jkl+mno",
		"日本語",
		"café",
	}
	validChars := "abcdefghijklmnopqrstuvwxyz0123456789"
	for _, s := range inputs {
		result := SanitiseEmailLocal(s)
		for _, ch := range result {
			assert.Contains(t, validChars, string(ch), "non-alphanumeric char %q in output for input %q", string(ch), s)
		}
	}
}
