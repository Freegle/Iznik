package utils

import (
	"regexp"
	"strings"
	"unicode"

	"golang.org/x/text/unicode/norm"
)

// ItemKey is the key an item is matched by: lower case, no accents or punctuation, each word
// singular, a hyphen or apostrophe inside a word joining it ("e-bike" -> "ebike"). It must give
// what the batch's App\Support\ItemMatchKey::key gives, because the batch writes
// messages_cleaned_items.itemkey and search compares a member's search term with it.
// docs/developers/reference/message-items.md.
func ItemKey(s string) string {
	s = strings.ToLower(s)
	var b strings.Builder
	for _, r := range norm.NFKD.String(s) {
		if unicode.Is(unicode.Mn, r) {
			continue
		}
		b.WriteRune(r)
	}
	s = strings.ReplaceAll(b.String(), "&", " and ")
	s = itemKeyJoin.ReplaceAllString(s, "$1$2")
	s = strings.TrimSpace(itemKeyNonAlnum.ReplaceAllString(s, " "))
	if s == "" {
		return ""
	}
	words := strings.Split(s, " ")
	for i, w := range words {
		words[i] = itemKeySingular(w)
	}
	return strings.Join(words, " ")
}

var (
	// One non-overlapping pass, as PHP's preg_replace does: "a-b-c" becomes "ab-c" in both.
	itemKeyJoin     = regexp.MustCompile(`([\p{L}\p{N}_])[-'\x{2019}]([\p{L}\p{N}_])`)
	itemKeyNonAlnum = regexp.MustCompile(`[^a-z0-9]+`)
	itemKeyKeepS    = regexp.MustCompile(`(ss|us|is|ous)$`)
	itemKeyEs       = regexp.MustCompile(`(ches|shes|xes|sses|zzes)$`)
)

var itemKeyPluralOnly = map[string]bool{
	"glasses": true, "scissors": true, "trousers": true, "jeans": true, "clothes": true, "pants": true,
	"shorts": true, "tights": true, "leggings": true, "pyjamas": true, "pajamas": true, "binoculars": true,
	"tongs": true, "pliers": true, "tweezers": true, "headphones": true, "earphones": true, "goggles": true,
	"drawers": true, "stairs": true, "news": true, "series": true, "species": true, "chess": true,
	"dregs": true, "overalls": true, "dungarees": true, "knickers": true, "boxers": true, "sunglasses": true,
	"spectacles": true, "secateurs": true, "shears": true, "lees": true,
}

var itemKeyIrregular = map[string]string{
	"knives": "knife", "shelves": "shelf", "leaves": "leaf", "loaves": "loaf", "wolves": "wolf",
	"halves": "half", "children": "child", "mice": "mouse", "geese": "goose", "feet": "foot",
	"teeth": "tooth", "men": "man", "women": "woman", "people": "person", "dice": "die",
	"scarves": "scarf", "hooves": "hoof",
}

func itemKeySingular(w string) string {
	if len(w) <= 3 || itemKeyPluralOnly[w] {
		return w
	}
	if s, ok := itemKeyIrregular[w]; ok {
		return s
	}
	if len(w) > 4 && strings.HasSuffix(w, "ies") {
		return w[:len(w)-3] + "y"
	}
	if itemKeyKeepS.MatchString(w) {
		return w
	}
	if itemKeyEs.MatchString(w) {
		return w[:len(w)-2]
	}
	if strings.HasSuffix(w, "s") {
		return w[:len(w)-1]
	}
	return w
}
