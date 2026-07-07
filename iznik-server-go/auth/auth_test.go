package auth_test

import (
	"crypto/sha1"
	"encoding/hex"
	"os"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/auth"
)

// sha1Hex is a reference implementation so tests can derive expected values
// without calling the function under test.
func sha1Hex(s string) string {
	h := sha1.New()
	h.Write([]byte(s))
	return hex.EncodeToString(h.Sum(nil))
}

// ---------------------------------------------------------------------------
// HashPassword
// ---------------------------------------------------------------------------

func TestHashPassword_KnownVector(t *testing.T) {
	// sha1("password" + "salt") should match the reference implementation.
	want := sha1Hex("password" + "salt")
	got := auth.HashPassword("password", "salt")
	if got != want {
		t.Errorf("HashPassword(%q,%q) = %q, want %q", "password", "salt", got, want)
	}
}

func TestHashPassword_LowercaseHexOutput(t *testing.T) {
	got := auth.HashPassword("abc", "xyz")
	if got != strings.ToLower(got) {
		t.Errorf("HashPassword output %q is not lowercase hex", got)
	}
}

func TestHashPassword_OutputLength(t *testing.T) {
	// SHA-1 produces 20 bytes → 40 hex characters.
	got := auth.HashPassword("anypassword", "anysalt")
	if len(got) != 40 {
		t.Errorf("HashPassword output length = %d, want 40", len(got))
	}
}

func TestHashPassword_HexCharsOnly(t *testing.T) {
	got := auth.HashPassword("p@ssw0rd!", "salty")
	const validHex = "0123456789abcdef"
	for _, ch := range got {
		if !strings.ContainsRune(validHex, ch) {
			t.Errorf("HashPassword output %q contains non-hex character %q", got, ch)
		}
	}
}

func TestHashPassword_Deterministic(t *testing.T) {
	a := auth.HashPassword("hello", "world")
	b := auth.HashPassword("hello", "world")
	if a != b {
		t.Errorf("HashPassword not deterministic: first=%q second=%q", a, b)
	}
}

func TestHashPassword_DifferentPasswordsDifferentHashes(t *testing.T) {
	h1 := auth.HashPassword("password1", "salt")
	h2 := auth.HashPassword("password2", "salt")
	if h1 == h2 {
		t.Errorf("different passwords produced same hash %q", h1)
	}
}

func TestHashPassword_DifferentSaltsDifferentHashes(t *testing.T) {
	h1 := auth.HashPassword("password", "salt1")
	h2 := auth.HashPassword("password", "salt2")
	if h1 == h2 {
		t.Errorf("different salts produced same hash %q", h1)
	}
}

func TestHashPassword_EmptyPassword(t *testing.T) {
	want := sha1Hex("" + "salt")
	got := auth.HashPassword("", "salt")
	if got != want {
		t.Errorf("HashPassword(%q,%q) = %q, want %q", "", "salt", got, want)
	}
}

func TestHashPassword_EmptySalt(t *testing.T) {
	want := sha1Hex("password" + "")
	got := auth.HashPassword("password", "")
	if got != want {
		t.Errorf("HashPassword(%q,%q) = %q, want %q", "password", "", got, want)
	}
}

func TestHashPassword_BothEmpty(t *testing.T) {
	want := sha1Hex("")
	got := auth.HashPassword("", "")
	if got != want {
		t.Errorf("HashPassword(%q,%q) = %q, want %q", "", "", got, want)
	}
}

func TestHashPassword_SpecialChars(t *testing.T) {
	// Special characters and unicode should not cause panics.
	got := auth.HashPassword("p@$$w0rd!£€", "säÿ")
	if len(got) != 40 {
		t.Errorf("HashPassword with special chars output length = %d, want 40", len(got))
	}
}

func TestHashPassword_ConcatIsAmbiguous(t *testing.T) {
	// HashPassword concatenates password+salt with no separator, so
	// HashPassword("pass","word") == HashPassword("password","") because
	// "pass"+"word" == "password"+"". This is expected behaviour — callers
	// must ensure salt is non-empty for security.
	h1 := auth.HashPassword("pass", "word")
	h2 := auth.HashPassword("password", "")
	if h1 != h2 {
		t.Errorf("expected HashPassword(pass,word)==HashPassword(password,) due to concat, got %q vs %q", h1, h2)
	}
}

// ---------------------------------------------------------------------------
// GetPasswordSalt
// ---------------------------------------------------------------------------

func TestGetPasswordSalt_DefaultWhenEnvNotSet(t *testing.T) {
	os.Unsetenv("PASSWORD_SALT")
	got := auth.GetPasswordSalt()
	if got != "zzzz" {
		t.Errorf("GetPasswordSalt() with no env var = %q, want %q", got, "zzzz")
	}
}

func TestGetPasswordSalt_ReturnsEnvVar(t *testing.T) {
	const want = "mysupersecuresalt"
	t.Setenv("PASSWORD_SALT", want)
	got := auth.GetPasswordSalt()
	if got != want {
		t.Errorf("GetPasswordSalt() = %q, want %q", got, want)
	}
}

func TestGetPasswordSalt_EmptyEnvFallsBackToDefault(t *testing.T) {
	t.Setenv("PASSWORD_SALT", "")
	got := auth.GetPasswordSalt()
	if got != "zzzz" {
		t.Errorf("GetPasswordSalt() with empty env = %q, want %q", got, "zzzz")
	}
}

func TestGetPasswordSalt_NonEmptyEnvTakesPrecedence(t *testing.T) {
	t.Setenv("PASSWORD_SALT", "prod_salt_value")
	got := auth.GetPasswordSalt()
	if got == "zzzz" {
		t.Errorf("GetPasswordSalt() returned default %q even though env var was set", got)
	}
}
