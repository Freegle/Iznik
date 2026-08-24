package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/utils"
)

// TestCountryCodeForIP exercises the embedded GeoLite2 lookup that populates
// messages.fromcountry on web submit (SubmitMessage). The ISO code it returns
// is what drives the ModTools "different country" warning once expanded to a
// full name on read.
func TestCountryCodeForIP(t *testing.T) {
	// A well-known public IP resolves to its country. 8.8.8.8 (Google DNS) is
	// allocated to the US, so this exercises the non-UK path that the warning
	// exists to surface.
	if got := utils.CountryCodeForIP("8.8.8.8"); got != "US" {
		t.Errorf("CountryCodeForIP(8.8.8.8) = %q, want US", got)
	}

	// Fail-soft cases return "" so the caller stores NULL rather than breaking
	// the post - matching V1's skip-on-error behaviour.
	for _, ip := range []string{
		"",            // no IP captured
		"not-an-ip",   // unparseable
		"192.168.1.1", // private address, absent from the database
	} {
		if got := utils.CountryCodeForIP(ip); got != "" {
			t.Errorf("CountryCodeForIP(%q) = %q, want empty", ip, got)
		}
	}
}
