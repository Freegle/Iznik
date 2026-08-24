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

// StripCourtesy removes courtesy words from an item name. It is applied both when looking an
// illustration up in the cache and when building the prompt to generate one, because the two
// have to agree: otherwise every "please" post misses the cache and generates its own copy of
// an image we already have. A name that is nothing BUT courtesy is returned unchanged - there
// is no item to draw either way, and callers read an empty name as "no item at all".
func StripCourtesy(name string) string {
	cleaned := trailingThanksPattern.ReplaceAllString(name, "")
	cleaned = courtesyPattern.ReplaceAllString(cleaned, " ")
	cleaned = strings.TrimSpace(whitespacePattern.ReplaceAllString(cleaned, " "))
	cleaned = strings.TrimRight(cleaned, " ,;:")

	if cleaned == "" {
		return name
	}

	return cleaned
}
