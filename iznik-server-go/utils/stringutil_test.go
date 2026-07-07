package utils

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// StripEmailDomain — table-driven comprehensive tests
// ---------------------------------------------------------------------------

func TestStripEmailDomain(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  string
	}{
		{"simple email", "alice@example.com", "alice"},
		{"subdomain email", "bob@mail.example.co.uk", "bob"},
		{"no at sign — returned unchanged", "plainuser", "plainuser"},
		{"empty string", "", ""},
		{"only at sign — local part is empty", "@", ""},
		{"leading at sign", "@domain.com", ""},
		{"trailing at sign", "user@", "user"},
		{"multiple at signs — splits on first", "a@b@c.com", "a"},
		{"unicode local part", "jörn@example.de", "jörn"},
		{"local part with dots", "first.last@example.com", "first.last"},
		{"local part with plus", "user+tag@example.com", "user+tag"},
		{"local part with hyphen", "my-name@example.com", "my-name"},
		{"very long local part", strings.Repeat("x", 200) + "@example.com", strings.Repeat("x", 200)},
		{"whitespace in local part", "user name@example.com", "user name"},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.want, StripEmailDomain(tc.input))
		})
	}
}

// ---------------------------------------------------------------------------
// TruncateStringUtil — table-driven comprehensive tests
// ---------------------------------------------------------------------------

func TestTruncateStringUtil(t *testing.T) {
	tests := []struct {
		name   string
		s      string
		maxLen int
		want   string
	}{
		{"empty string, positive limit", "", 5, ""},
		{"empty string, zero limit", "", 0, ""},
		{"within limit", "Hello", 10, "Hello"},
		{"exactly at limit", "Hello", 5, "Hello"},
		{"one over limit", "Hello!", 5, "Hello..."},
		{"zero limit truncates all", "Hello", 0, "..."},
		{"negative limit treated as zero", "Hello", -1, "..."},
		{"large negative limit treated as zero", "Hello", -100, "..."},
		{"single char, limit 1 (exact)", "A", 1, "A"},
		{"two chars, limit 1 (truncates)", "AB", 1, "A..."},
		{"long string", "The quick brown fox jumps over the lazy dog", 10, "The quick ..."},
		{"unicode string within limit", "héllo", 10, "héllo"},
		{"unicode string truncated (byte boundary)", "café", 3, "caf..."},
		{"only spaces", "   ", 2, "  ..."},
		{"newlines in string", "line1\nline2", 5, "line1..."},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.want, TruncateStringUtil(tc.s, tc.maxLen))
		})
	}
}

// ---------------------------------------------------------------------------
// IsValidEmailAddress — table-driven comprehensive tests
// ---------------------------------------------------------------------------

func TestIsValidEmailAddress(t *testing.T) {
	tests := []struct {
		name  string
		email string
		valid bool
	}{
		// Valid addresses
		{"simple valid", "user@example.com", true},
		{"subdomain", "user@mail.example.com", true},
		{"plus addressing", "user+tag@example.com", true},
		{"dot in local", "first.last@example.com", true},
		{"hyphen in local", "user-name@example.com", true},
		{"underscore in local", "user_name@example.com", true},
		{"percent in local", "user%name@example.com", true},
		{"numbers in local", "user123@example.com", true},
		{"two-part TLD", "user@example.co.uk", true},
		{"country TLD", "user@example.de", true},
		{"short TLD 2 chars", "user@example.io", true},

		// Invalid addresses
		{"empty string", "", false},
		{"no at sign", "notanemail", false},
		{"only at sign", "@", false},
		{"no local part", "@example.com", false},
		{"no domain", "user@", false},
		{"no TLD dot", "user@domain", false},
		{"single-char TLD", "user@example.c", false},
		{"space in email", "user name@example.com", false},
		{"space before at", "user @example.com", false},
		{"space after at", "user@ example.com", false},
		{"double at", "user@@example.com", false},
		{"special chars in domain", "user@ex!mple.com", false},
		{"newline in email", "user\n@example.com", false},
		{"tab in email", "user\t@example.com", false},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.valid, IsValidEmailAddress(tc.email), "email: %q", tc.email)
		})
	}
}
