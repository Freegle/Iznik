package emailhygiene

import (
	"strings"
	"testing"
)

// The characters under test are invisible, and writing them as literals in the
// source is how this file failed to compile the first time: the escape became a
// real byte and Go rejected the file with "illegal byte order mark". Build them
// from their code points instead, so the source stays pure ASCII and says which
// character it means.
const (
	zeroWidthSpace      = rune(0x200B)
	rightToLeftMark     = rune(0x200F) // E2808F - the one actually seen in production
	popDirectionalFmt   = rune(0x202C) // E280AC - on the most recent bad address
	byteOrderMark       = rune(0xFEFF)
	latinSmallRWithAcue = rune(0x0155) // C595 - an accented local part
)

// The addresses below are the shapes actually found in users_emails on
// 2026-09-17, reproduced from their stored bytes.
func TestInspectFindsTheShapesSeenInProduction(t *testing.T) {
	cases := []struct {
		name  string
		email string
		kind  string
	}{
		// Five of the five most recent bad addresses in a 200,000-row sample were
		// this: all gmail.com, all pasted from right-to-left contexts.
		{"right-to-left mark", string(rightToLeftMark) + "kehdjdndb98@gmail.com", "invisible-formatting-character"},
		{"pop directional formatting", string(popDirectionalFmt) + string(rightToLeftMark) + "qeddgdchjds@gmail.com", "invisible-formatting-character"},
		{"byte order mark", string(byteOrderMark) + "sunycam14@gmail.com", "invisible-formatting-character"},
		{"zero width space", "some" + string(zeroWidthSpace) + "one@example.org", "invisible-formatting-character"},
		// Deliverable nowhere without SMTPUTF8, so the member gets no mail at all.
		{"accented local part", "mai" + string(latinSmallRWithAcue) + "tink@yahoo.com", "non-ascii-character"},
		// A comma where a dot belongs: a plain typo, and the one shape the
		// front-end regex already rejects.
		{"comma for dot", "millwrights@wickenmill.org,uk", "unparseable"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			problems := Inspect(c.email)
			if len(problems) == 0 {
				t.Fatalf("Inspect(%q) found nothing; expected %s", c.email, c.kind)
			}

			found := false
			kinds := make([]string, 0, len(problems))
			for _, p := range problems {
				kinds = append(kinds, p.Kind)
				if p.Kind == c.kind {
					found = true
				}
			}
			if !found {
				t.Errorf("Inspect(%q) = %v, want it to include %s", c.email, kinds, c.kind)
			}
		})
	}
}

// The point of the package is that it must not cry wolf. An address that is fine
// has to stay silent, or the Sentry issue becomes noise and gets ignored - which
// is exactly how the digest collapse went unreported for three days.
func TestInspectIsSilentOnOrdinaryAddresses(t *testing.T) {
	fine := []string{
		"someone@example.org",
		"first.last@sub.domain.co.uk",
		"user+tag@gmail.com",
		"o'brien@example.com",            // an apostrophe is legal and common
		"a_b-c.d%e@example-domain.co.uk", // every punctuation the local part allows
		"x@y.io",
		"-pootle-@live.co.uk", // a leading hyphen is RFC-legal, however odd it looks
	}

	for _, e := range fine {
		if problems := Inspect(e); len(problems) != 0 {
			t.Errorf("Inspect(%q) = %v, want no problems - a false positive here is worse than silence", e, problems)
		}
	}
}

// Sentry is a third party. The address must be diagnosable without being handed
// over whole.
func TestRedactKeepsTheDomainAndDropsTheLocalPart(t *testing.T) {
	got := redact("hanansattouf@gmail.com")

	if !strings.HasSuffix(got, "@gmail.com") {
		t.Errorf("redact() = %q, want the domain kept - it is what identifies a provider or an import", got)
	}
	if strings.Contains(got, "hanansattouf") {
		t.Errorf("redact() = %q, want the local part reduced, not passed through", got)
	}
	if !strings.Contains(got, "12") {
		t.Errorf("redact() = %q, want the local-part length kept so the row can be matched up", got)
	}
}

func TestRedactHandlesAddressesWithoutAnAt(t *testing.T) {
	// Inspect calls redact on whatever it was given, including junk.
	if got := redact("no-at-sign-at-all"); !strings.Contains(got, "no @") {
		t.Errorf("redact() = %q, want it to say there is no @ rather than panic or mislead", got)
	}
}

// Report must be safe to call on every write, including the overwhelming
// majority that are fine, and must not panic when Sentry is not configured (as
// it is not under test).
func TestReportIsSafeOnGoodAndBadAddresses(t *testing.T) {
	Report("someone@example.org", "test.good", 1)
	Report(string(rightToLeftMark)+"someone@example.org", "test.bad", 2)
	Report("", "test.empty", 3)
}
