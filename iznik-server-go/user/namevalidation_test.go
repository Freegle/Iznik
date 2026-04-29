package user

import "testing"

// TestSanitizeDisplayName_Suspicious covers the rules proposed in Discourse
// #9587: non-mod users who trade on the Freegle brand or use generic
// authority-persona names should have their display name rewritten on output.
func TestSanitizeDisplayName_Suspicious(t *testing.T) {
	cases := []struct {
		name  string
		input string
	}{
		// Exact brand impersonation (original attack vectors).
		{"exact freegle support", "iLovefreegle Support"},
		{"freegle support team", "Freegle Support Team"},
		{"lowercase variant", "ilovefreegle support team"},
		{"freegle admin", "Freegle Admin"},
		{"trashnothing help", "TrashNothing Help"},
		{"freegle security", "Freegle Security"},

		// Leet-speak normalisation.
		{"leet digits", "Fr33gle Supp0rt"},
		{"mixed leet dashes", "Fr33gle-Supp0rt"},

		// Spacing / punctuation obfuscation (caught via concat-match).
		{"extra space in brand", "i Love freegle Team"},
		{"dotted freecycle", "free.cycle"},
		{"underscored trashnothing", "trash_nothing"},

		// Typo / Damerau-Levenshtein on the long brand words.
		{"one edit ilovefreegle", "Ilovefreegl Support"},
		{"transposition trashnothing", "Trashntohing Help"},

		// Brand word alone is enough (non-mods shouldn't trade on the name).
		{"bare freegle", "Freegle"},
		{"freegle postcode", "Freegle SK9"},
		{"owner-style brand name", "East-Staffordshire-Freegle-owner"},
		{"ilovefreegle notification", "groups.ilovefreegle.org notification"},

		// Pure authority persona (rule: all tokens are authority words).
		{"bare admin", "Admin"},
		{"support team solo", "Support Team"},
		{"verification team", "Security Verification"},
		{"prize winner", "Prize Winner"},
		{"lottery claims", "Lottery Claims"},

		// Bare brand surname — "Freegle" isn't a real surname.
		{"surname freegle", "Susan Freegle"},

		// weak-brand + authority via concat match ("thefreegler" ≈ "thefreegle").
		{"the freegler", "The Freegler"},

		// Freegler explicitly paired with authority word — weak brand rule.
		{"freegler plus support", "Freegler Support"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			out := SanitizeDisplayName(c.input, false)
			if out == c.input {
				t.Errorf("expected %q to be rewritten, got same string back", c.input)
			}
			if out == "" {
				t.Errorf("expected %q to be rewritten to a non-empty fallback", c.input)
			}
		})
	}
}

// TestSanitizeDisplayName_Clean covers names that must NOT be rewritten.
func TestSanitizeDisplayName_Clean(t *testing.T) {
	cases := []struct {
		name  string
		input string
	}{
		// Plain first/last names must pass through.
		{"plain name", "Emma Brown"},

		// Ambiguous "Emma Support" has authority word but no brand — passes
		// because the sanitiser only flags all-authority names.
		{"person with authority-sounding surname", "Emma Support"},

		// "Freegler" (what we call our users) is safe on its own — only
		// flagged when paired with an authority word (tested below).
		{"freegler no authority", "Adam Freegler"},

		// English words one edit from brand but unrelated.
		{"eagle", "Eagle"},
		{"greg", "Greg"},

		// Blanks and short names.
		{"empty", ""},
		{"single letter", "J"},

		// TN-format email leak — handled but not rewritten to empty.
		{"trashnothing email leak", "alice-g3486@user.trashnothing.com"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			out := SanitizeDisplayName(c.input, false)
			if out != c.input {
				t.Errorf("expected %q to pass through unchanged, got %q", c.input, out)
			}
		})
	}
}

// TestSanitizeDisplayName_ExemptUsersPassThrough verifies mods, support and
// admins keep whatever name they set — the sanitiser is only for non-mods.
func TestSanitizeDisplayName_ExemptUsersPassThrough(t *testing.T) {
	suspicious := []string{
		"iLovefreegle Support",
		"Freegle Admin",
		"Admin",
	}
	for _, s := range suspicious {
		out := SanitizeDisplayName(s, true)
		if out != s {
			t.Errorf("exempt user: expected %q unchanged, got %q", s, out)
		}
	}
}

// TestDamerauLevenshtein covers the edit-distance primitive directly.
func TestDamerauLevenshtein(t *testing.T) {
	cases := []struct {
		a, b string
		max  int
		want int
	}{
		{"", "", 2, 0},
		{"abc", "abc", 2, 0},
		{"abc", "abd", 2, 1},      // substitution
		{"abc", "ab", 2, 1},       // deletion
		{"abc", "abcd", 2, 1},     // insertion
		{"abc", "acb", 2, 1},      // transposition
		{"freegle", "freegel", 2, 1},   // transposition: gl ↔ lg
		{"freegle", "freeggle", 2, 1},  // insertion
		{"freegle", "supp", 2, 3},      // capped at max+1
	}
	for _, c := range cases {
		got := damerauLevenshtein(c.a, c.b, c.max)
		if got != c.want && !(got >= c.max+1 && c.want >= c.max+1) {
			t.Errorf("damerauLevenshtein(%q,%q,%d)=%d want %d", c.a, c.b, c.max, got, c.want)
		}
	}
}
