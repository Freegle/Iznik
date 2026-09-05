package maildeferral

import (
	"testing"
	"time"
)

func TestDomainOf(t *testing.T) {
	cases := []struct {
		name  string
		email string
		want  string
	}{
		{"plain", "someone@yahoo.co.uk", "yahoo.co.uk"},
		{"uppercase is normalised", "Someone@YAHOO.co.uk", "yahoo.co.uk"},
		{"surrounding space is trimmed", "someone@ yahoo.co.uk ", "yahoo.co.uk"},
		// A quoted local part may legally contain @, so the split must be on
		// the LAST one or we would read "a" as the domain here.
		{"quoted local part containing @", `"a@b"@yahoo.com`, "yahoo.com"},
		{"no at sign", "notanaddress", ""},
		{"trailing at sign", "someone@", ""},
		{"empty", "", ""},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := DomainOf(c.email); got != c.want {
				t.Errorf("DomainOf(%q) = %q, want %q", c.email, got, c.want)
			}
		})
	}
}

// seed installs a cache snapshot so ForEmail can be exercised without a DB.
func seed(t *testing.T, domains map[string]Deferral) {
	t.Helper()

	mu.Lock()
	defer mu.Unlock()

	byDomain = domains
	loadedAt = time.Now()

	t.Cleanup(func() {
		mu.Lock()
		defer mu.Unlock()
		byDomain = nil
		loadedAt = time.Time{}
	})
}

func TestForEmailMatchesDomainCaseInsensitively(t *testing.T) {
	since := time.Date(2026, 8, 15, 9, 0, 0, 0, time.UTC)
	seed(t, map[string]Deferral{
		"yahoo.co.uk": {Domain: "yahoo.co.uk", Since: &since},
	})

	got := ForEmail("Someone@Yahoo.CO.UK")
	if got == nil {
		t.Fatal("expected a deferral for a suppressed domain")
	}
	if got.Domain != "yahoo.co.uk" {
		t.Errorf("Domain = %q, want yahoo.co.uk", got.Domain)
	}
	if got.Since == nil || !got.Since.Equal(since) {
		t.Errorf("Since = %v, want %v", got.Since, since)
	}
}

func TestForEmailIgnoresUnaffectedAndInvalidAddresses(t *testing.T) {
	seed(t, map[string]Deferral{"yahoo.co.uk": {Domain: "yahoo.co.uk"}})

	// A domain that merely CONTAINS a suppressed one must not match, or
	// notyahoo.co.uk and yahoo.co.uk.example.com would both warn.
	for _, email := range []string{
		"someone@gmail.com",
		"someone@notyahoo.co.uk",
		"someone@yahoo.co.uk.example.com",
		"someone@sub.yahoo.co.uk",
		"notanaddress",
		"",
	} {
		if got := ForEmail(email); got != nil {
			t.Errorf("ForEmail(%q) = %+v, want nil", email, got)
		}
	}
}

// The caller mutating what it gets back must not corrupt the shared cache.
func TestForEmailReturnsACopy(t *testing.T) {
	seed(t, map[string]Deferral{"yahoo.com": {Domain: "yahoo.com"}})

	first := ForEmail("a@yahoo.com")
	if first == nil {
		t.Fatal("expected a deferral")
	}
	first.Domain = "mutated"

	second := ForEmail("b@yahoo.com")
	if second == nil || second.Domain != "yahoo.com" {
		t.Errorf("cache was corrupted by caller mutation: %+v", second)
	}
}
