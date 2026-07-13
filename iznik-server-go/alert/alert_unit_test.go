package alert

import "testing"

// The ModTools alert composer has a required plain-text textarea and an OPTIONAL Quill editor.
// An untouched Quill editor serialises to an empty-document sentinel ("<p><br></p>"), not "", so
// the old `req.Html == ""` guard let it through and createAlert stored a visually empty HTML body.
// The alert then mailed out to every mod as just the boilerplate wrapper with no message in it
// (live incident 2026-07-13, Freegle-wide alert 11194). htmlIsBlank is what decides whether to
// fall back to the text body, so the empty-editor sentinels are pinned here.
func TestHtmlIsBlank(t *testing.T) {
	blank := []string{
		"",                       // never filled in
		"<p><br></p>",            // Quill's empty document — the one that caused the incident
		"<p></p>",                // other editors' empty document
		"<br>",                   // bare break
		"   \n\t ",               // whitespace only
		"<p>&nbsp;</p>",          // entity-only
		"<p>\u00a0</p>",          // literal non-breaking space
		"<div><p><br></p></div>", // nested empty
	}
	for _, s := range blank {
		if !htmlIsBlank(s) {
			t.Errorf("htmlIsBlank(%q) = false, want true (should fall back to the text body)", s)
		}
	}

	notBlank := []string{
		"<p>Can you help another Freegle group thrive?</p>",
		"Hello fellow Freegle volunteer",
		"<p><br></p><p>Real content after an empty para</p>",
	}
	for _, s := range notBlank {
		if htmlIsBlank(s) {
			t.Errorf("htmlIsBlank(%q) = true, want false (real content must not be discarded)", s)
		}
	}
}
