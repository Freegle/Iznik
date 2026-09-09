package assistant

import (
	"regexp"
	"strconv"
	"strings"
)

// Deterministic understanding. Mirrors iznik-nuxt3/composables/useItemValidation.js
// for the hard block, plus the small amount of parsing the flow needs without a model.

var unpostable = []*regexp.Regexp{
	regexp.MustCompile(`^any$`), regexp.MustCompile(`^anything$`), regexp.MustCompile(`^anything else$`),
	regexp.MustCompile(`^everything$`), regexp.MustCompile(`^stuff$`), regexp.MustCompile(`^things$`),
	regexp.MustCompile(`^items$`), regexp.MustCompile(`^goods$`), regexp.MustCompile(`^misc$`),
	regexp.MustCompile(`^miscellaneous$`), regexp.MustCompile(`^sundries$`), regexp.MustCompile(`^various$`),
	regexp.MustCompile(`^freebies$`), regexp.MustCompile(`^something$`), regexp.MustCompile(`^owt$`),
	regexp.MustCompile(`^whatever$`), regexp.MustCompile(`^unwanted$`), regexp.MustCompile(`^no idea$`),
	regexp.MustCompile(`^not sure$`), regexp.MustCompile(`^nothing specific$`),
	regexp.MustCompile(`^(anything|everything|something) (at all|really|useful|nice|going|you have|you've got|free)$`),
}

var descriptiveText = regexp.MustCompile(`\pL{2,}`)

// HasNoDescriptiveText is true for values with no words at all: numbers, prices, symbols.
func HasNoDescriptiveText(s string) bool {
	return !descriptiveText.MatchString(s)
}

// IsVagueItem is true for content-free catch-all words.
func IsVagueItem(item string) bool {
	v := strings.ToLower(strings.TrimSpace(item))
	if v == "" {
		return false
	}
	for _, re := range unpostable {
		if re.MatchString(v) {
			return true
		}
	}
	return false
}

// IsUnpostableItem is the single gate compose flows use.
func IsUnpostableItem(item string) bool {
	return HasNoDescriptiveText(item) || IsVagueItem(item)
}

var pluralHints = regexp.MustCompile(`(?i)\b(\d+\s*x|x\s*\d+|set of|pair of|couple of|\d+\s+(of|off)\b|several|some|lots of|loads? of|a few|few|multiple|various|assorted|bundle of|pile of|stack of|selection of|collection of|bags? of|boxes? of|\d+\s*(pcs|pieces|items))\b`)

// pluralWord is the last word ending in s, less the singulars that happen to (glass, bus, tennis).
var pluralWord = regexp.MustCompile(`(?i)\b[a-z]{3,}[^sui\s]s\s*$`)
var pluralNouns = regexp.MustCompile(`(?i)\b(chairs|books|toys|clothes|plates|cups|mugs|glasses|tiles|bricks|plants|pots|jars|bottles|records|cds|dvds|games|shoes|boots|towels|sheets|pillows|cushions|curtains|frames|bags|boxes)\b`)

// LooksPlural says whether the text suggests more than one of something.
func LooksPlural(text string) bool {
	return pluralHints.MatchString(text) || pluralNouns.MatchString(text) || pluralWord.MatchString(strings.TrimSpace(text))
}

var wordNumbers = map[string]int{"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10, "couple": 2, "pair": 2}
var digitRe = regexp.MustCompile(`\b(\d{1,2})\b`)

// ParseQuantity reads "2", "two", "a couple". 0 means none found.
func ParseQuantity(text string) int {
	t := strings.ToLower(strings.TrimSpace(text))
	if m := digitRe.FindStringSubmatch(t); m != nil {
		n, _ := strconv.Atoi(m[1])
		if n < 1 {
			n = 1
		}
		if n > 99 {
			n = 99
		}
		return n
	}
	for w, n := range wordNumbers {
		if regexp.MustCompile(`\b` + w + `\b`).MatchString(t) {
			return n
		}
	}
	return 0
}

var postcodeRe = regexp.MustCompile(`(?i)\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b`)

// ExtractPostcode finds a UK postcode in the text, normalised with one space.
func ExtractPostcode(text string) string {
	m := postcodeRe.FindStringSubmatch(text)
	if m == nil {
		return ""
	}
	return strings.ToUpper(m[1]) + " " + strings.ToUpper(m[2])
}

var emailRe = regexp.MustCompile(`(?i)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b`)

// ExtractEmail finds an email address in the text, lower-cased.
func ExtractEmail(text string) string {
	return strings.ToLower(emailRe.FindString(text))
}

var (
	cmdCancel     = regexp.MustCompile(`^(cancel|stop|quit|forget it|never mind|nevermind|leave it|start again|start over|restart)$`)
	cmdBack       = regexp.MustCompile(`^(back|go back|previous)$`)
	cmdHelp       = regexp.MustCompile(`^(help|\?)$`)
	cmdVolunteers = regexp.MustCompile(`\b(human|real person|volunteer|volunteers|speak to someone|talk to someone)\b`)
	trailingPunct = regexp.MustCompile(`[.!?]+$`)
)

// DetectCommand recognises the typed commands that must work however the model is feeling.
func DetectCommand(text string) string {
	t := strings.ToLower(strings.TrimSpace(text))
	t = trailingPunct.ReplaceAllString(t, "")
	switch {
	case cmdCancel.MatchString(t):
		return "cancel"
	case cmdBack.MatchString(t):
		return "back"
	case cmdHelp.MatchString(t):
		return "help"
	case cmdVolunteers.MatchString(t) && len(strings.Fields(t)) <= 8:
		return "volunteers"
	}
	return ""
}

var (
	intentGive      = regexp.MustCompile(`\b(give away|giving away|get rid of|getting rid of|offer|offering|clear out|clearing out|donate|free to a good home|anyone want)\b`)
	intentAsk       = regexp.MustCompile(`\b(looking for|need a|need an|need some|want a|want an|want some|wanted|does anyone have|has anyone got|after a|after an|in search of|searching for)\b`)
	intentNearby    = regexp.MustCompile(`\b(what's nearby|whats nearby|what is nearby|browse|have a look|see what|anything nearby|what's on offer|whats on offer|what's available)\b`)
	intentCommunity = regexp.MustCompile(`\b(community|communities|group|groups|join)\b`)
	intentCommQual  = regexp.MustCompile(`\b(join|find|my|local|nearest)\b`)
	intentHelp      = regexp.MustCompile(`\b(how does|how do i|is it free|safe|safety|help)\b`)
)

// DetectIntent reads plain wording for the hub. Empty means unsure: the model decides.
func DetectIntent(text string) string {
	t := strings.ToLower(text)
	switch {
	case intentGive.MatchString(t):
		return "give"
	case intentAsk.MatchString(t):
		return "ask"
	case intentNearby.MatchString(t):
		return "nearby"
	case intentCommunity.MatchString(t) && intentCommQual.MatchString(t):
		return "community"
	case intentHelp.MatchString(t):
		return "help"
	}
	return ""
}

// alwaysAllowed are words a reply may use even though they are not in the facts.
var alwaysAllowed = map[string]bool{}

func init() {
	for _, w := range strings.Fields("freegle freeglers freegler monday tuesday wednesday thursday friday saturday sunday today tomorrow weekend morning afternoon evening i you we uk britain england scotland wales northern ireland chitchat trash nothing") {
		alwaysAllowed[w] = true
	}
}
