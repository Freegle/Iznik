package misc

import (
	"regexp"
	"strings"
)

// courtesyPattern matches the standalone courtesy words members leave in the item box -
// "iron please", "garden parasol pls". They are not part of the item, but the image
// generator has no way to know that and dutifully tries to draw them: a WANTED post for
// "iron please" came out as a smooth white blob (Discourse topic 9209/98). Word boundaries
// keep real words that merely begin the same way ("Pleaser" boots). Trailing punctuation
// goes with the word so "Wooden Pallets Please!!" leaves no stray "!!".
//
// "please" is stripped wherever it appears, because it is never part of an item name: all
// five names on production beginning with it read "PLEASE white masonry paint".
var courtesyPattern = regexp.MustCompile(`(?i)\b(?:please+|pls|plz)\b[!?.,]*`)

// trailingThanksPattern matches a sign-off at the END of a name: "thanks", "thank you",
// "thank you in advance". Unlike "please", these are anchored to the end, because they DO
// occur inside real item names - production has 113 posts for a "thank you card" or "thank
// you gift", and stripping mid-name would offer those as plain "cards". Every one of the 14
// production names carrying a thanks trailer has it at the end, which is what this matches.
// The repeat group eats a run of them ("please, thank you").
//
// Deliberately absent: "ta" and "tia". Both look like courtesy words and neither is safe -
// all 20 production names containing "ta" are job titles ("SEN TA", "Teaching Assistant
// (TA)"), and half the "tia" ones are the Siemens "TIA Portal".
var trailingThanksPattern = regexp.MustCompile(`(?i)(?:[\s,;:.!?]*\b(?:thanks?(?:\s+you)?(?:\s+very\s+much)?(?:\s+in\s+advance)?|thankyou|thx)\b)+[\s,;:.!?]*$`)

var whitespacePattern = regexp.MustCompile(`\s+`)

// "adult" pulls the image generator toward pharmacy/supplement imagery (Discourse topic
// 9630/60: a WANTED post for "Adult bike" got a medicine bottle), so it is stripped anywhere
// in the name the way please/pls/plz are. Word boundaries keep "adulting" intact.
//
// biasQualifierPattern runs first and takes the noun the word governs with it. Removing only
// the word leaves the qualifier stranded - "mountain bike, adult size" would become "mountain
// bike, size" and "adults only jigsaw" would become "only jigsaw", both of which read worse to
// the generator than the original. Mirrors ItemName::BIAS_QUALIFIER in iznik-batch.
var biasQualifierPattern = regexp.MustCompile(`(?i)\badults?\s+(?:sized?|only)\b[!?.,]*`)

var biasWordPattern = regexp.MustCompile(`(?i)\badults?\b[!?.,]*`)

// Debris left behind once a bias word is lifted out of the middle of a name: a conjunction or
// preposition with nothing left on one side of it ("Adult and kids" -> "and kids", "A bike for
// adult" -> "A bike for"), and the empty separator left by "hangers - adult size - will split".
// Articles are not stripped, only corrected for agreement, so "An adult cycle" gives "A cycle".
var strandedLeadPattern = regexp.MustCompile(`(?i)^[\s,;:.\-]*\b(?:and|or|for|with)\b[\s,;:.\-]*`)

var strandedTrailPattern = regexp.MustCompile(`(?i)[\s,;:.\-]*\b(?:and|or|for|with|of)\b[\s,;:.\-]*$`)

var emptySeparatorPattern = regexp.MustCompile(`\s*-\s*-\s*`)

var leadingArticlePattern = regexp.MustCompile(`(?i)^(an?)(\s+)([a-z])`)

// fixArticle restores a/an agreement after a bias word is removed from between the article
// and the noun: "An adult cycle" would otherwise leave "An cycle".
func fixArticle(name string) string {
	return leadingArticlePattern.ReplaceAllStringFunc(name, func(m string) string {
		parts := leadingArticlePattern.FindStringSubmatch(m)
		if parts == nil {
			return m
		}

		article := "a"
		if strings.ContainsAny(strings.ToLower(parts[3]), "aeiou") {
			article = "an"
		}
		if parts[1][0] >= 'A' && parts[1][0] <= 'Z' {
			article = strings.ToUpper(article[:1]) + article[1:]
		}

		return article + parts[2] + parts[3]
	})
}

// StripCourtesy removes courtesy words and other bias words from an item name. It is applied
// both when looking an illustration up in the cache and when building the prompt to generate
// one, because the two have to agree: otherwise every "please" (or "adult") post misses the
// cache and generates its own copy of an image we already have. A name that is nothing BUT
// courtesy/bias words is returned unchanged - there is no item to draw either way, and callers
// read an empty name as "no item at all".
func StripCourtesy(name string) string {
	cleaned := trailingThanksPattern.ReplaceAllString(name, "")
	cleaned = courtesyPattern.ReplaceAllString(cleaned, " ")
	biasBefore := cleaned
	cleaned = biasQualifierPattern.ReplaceAllString(cleaned, " ")
	cleaned = biasWordPattern.ReplaceAllString(cleaned, " ")

	// Only tidy when a bias word actually came out, so names that never contained one keep
	// going through exactly the path they did before.
	if cleaned != biasBefore {
		cleaned = emptySeparatorPattern.ReplaceAllString(cleaned, " - ")
		cleaned = strandedLeadPattern.ReplaceAllString(cleaned, "")
		cleaned = strandedTrailPattern.ReplaceAllString(cleaned, "")
		cleaned = fixArticle(cleaned)
	}

	cleaned = strings.TrimSpace(whitespacePattern.ReplaceAllString(cleaned, " "))
	cleaned = strings.TrimRight(cleaned, " ,;:-")

	if cleaned == "" {
		return name
	}

	return cleaned
}
