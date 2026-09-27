// Package emailhygiene reports email addresses that reach storage despite being
// undeliverable, so the path that admitted them can be found.
//
// It NEVER rejects and NEVER alters an address. It only observes. That is
// deliberate: the addresses this exists to catch are overwhelmingly a member's
// CORRECT address carrying an invisible character their clipboard added, and
// turning this into a validator would start refusing signups on the strength of
// a pattern nobody has measured against the live address book. Tightening
// validation is a separate, evidence-led decision; this is the evidence.
//
// Background. 35 addresses in users_emails cannot be delivered to, and 13 of the
// members holding them have no other address, so they receive nothing at all.
// They arrive at one to four a month and have done since 2019. Nothing records
// how: the write paths log nothing, and Loki's retention starts long after the
// most recent one. Hence this.
package emailhygiene

import (
	"fmt"
	"net/mail"
	"strings"
	"unicode"

	"github.com/getsentry/sentry-go"
)

// Problem describes one reason an address looks undeliverable.
type Problem struct {
	Kind   string // short machine-ish label, used as the Sentry fingerprint
	Detail string
}

// invisible reports whether r is a formatting character that can never legally
// appear in an address but is impossible to see in a form field.
//
// These are the ones actually observed in production: U+200F RIGHT-TO-LEFT MARK
// and U+202C POP DIRECTIONAL FORMATTING, pasted from right-to-left contexts. The
// front-end regex excludes whitespace via \s, and JavaScript's \s happens to
// cover U+FEFF and U+00A0 but NOT U+200B-U+200F or U+202A-U+202E, so those pass.
func invisible(r rune) bool {
	switch {
	case r >= 0x200B && r <= 0x200F: // zero-width space .. right-to-left mark
		return true
	case r >= 0x202A && r <= 0x202E: // bidirectional embedding / override
		return true
	case r >= 0x2060 && r <= 0x206F: // word joiner .. invisible formatting
		return true
	case r == 0xFEFF: // byte order mark
		return true
	}

	return unicode.Is(unicode.Cf, r)
}

// Inspect returns every problem found with an address. An empty slice means it
// looks fine; Inspect makes no judgement beyond that.
func Inspect(email string) []Problem {
	var problems []Problem

	var marks []string
	var nonASCII []string
	for i, r := range email {
		switch {
		case invisible(r):
			marks = append(marks, fmt.Sprintf("U+%04X@%d", r, i))
		case r > unicode.MaxASCII:
			nonASCII = append(nonASCII, fmt.Sprintf("U+%04X@%d", r, i))
		}
	}

	if len(marks) > 0 {
		problems = append(problems, Problem{
			Kind:   "invisible-formatting-character",
			Detail: strings.Join(marks, " "),
		})
	}

	if len(nonASCII) > 0 {
		// Not illegal in principle - SMTPUTF8 exists - but we cannot deliver to
		// them, so a member with one of these gets no mail at all.
		problems = append(problems, Problem{
			Kind:   "non-ascii-character",
			Detail: strings.Join(nonASCII, " "),
		})
	}

	if _, err := mail.ParseAddress(email); err != nil {
		problems = append(problems, Problem{
			Kind:   "unparseable",
			Detail: err.Error(),
		})
	}

	return problems
}

// redact reduces an address to something diagnosable without shipping the whole
// of it to a third-party service: the domain is kept, because that is what tells
// you whether a provider or an import is involved, and the local part is reduced
// to its first and last character and its length.
func redact(email string) string {
	at := strings.LastIndex(email, "@")
	if at < 0 {
		return fmt.Sprintf("(no @, len %d)", len(email))
	}

	local, domain := email[:at], email[at+1:]
	switch r := []rune(local); len(r) {
	case 0:
		return "(empty local)@" + domain
	case 1:
		return fmt.Sprintf("%c@%s", r[0], domain)
	default:
		return fmt.Sprintf("%c...%c(%d)@%s", r[0], r[len(r)-1], len(r), domain)
	}
}

// Report sends a Sentry event when an address looks undeliverable, and does
// nothing otherwise. source names the code path so the event says where to look
// (e.g. "user.handleAddEmail"). It is safe to call on every write.
func Report(email, source string, userid uint64) {
	problems := Inspect(email)
	if len(problems) == 0 {
		return
	}

	kinds := make([]string, 0, len(problems))
	details := make(map[string]interface{}, len(problems)+3)
	for _, p := range problems {
		kinds = append(kinds, p.Kind)
		details[p.Kind] = p.Detail
	}
	details["source"] = source
	details["userid"] = userid
	details["address"] = redact(email)

	sentry.WithScope(func(scope *sentry.Scope) {
		scope.SetLevel(sentry.LevelWarning)
		scope.SetTag("email_hygiene", kinds[0])
		scope.SetTag("email_hygiene_source", source)
		scope.SetContext("undeliverable address", details)
		// Group by the kind and the path, not by the address, so a recurrence
		// lands on the same issue instead of opening one per member.
		scope.SetFingerprint([]string{"email-hygiene", kinds[0], source})
		sentry.CaptureMessage(fmt.Sprintf(
			"Stored an email address that cannot be delivered to (%s) via %s",
			strings.Join(kinds, ", "), source,
		))
	})
}
