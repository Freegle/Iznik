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
var courtesyPattern = regexp.MustCompile(`(?i)\b(?:please+|pls|plz)\b[!?.]*`)

var whitespacePattern = regexp.MustCompile(`\s+`)

// StripCourtesy removes courtesy words from an item name. It is applied both when looking an
// illustration up in the cache and when building the prompt to generate one, because the two
// have to agree: otherwise every "please" post misses the cache and generates its own copy of
// an image we already have. A name that is nothing BUT a courtesy word is returned unchanged -
// there is no item to draw either way, and callers read an empty name as "no item at all".
func StripCourtesy(name string) string {
	cleaned := courtesyPattern.ReplaceAllString(name, " ")
	cleaned = strings.TrimSpace(whitespacePattern.ReplaceAllString(cleaned, " "))
	cleaned = strings.TrimRight(cleaned, " ,;:")

	if cleaned == "" {
		return name
	}

	return cleaned
}
