package assistant

import (
	"encoding/json"
	"regexp"
	"strconv"
	"strings"
	"unicode"
)

// The fabrication and style check that stands between the model and the member. A
// reply passes only if every specific thing it says is in the facts it was given, it
// makes no promise Freegle cannot keep, and it sounds like Freegle.

var bannedPhrases = []*regexp.Regexp{
	regexp.MustCompile(`(?i)\bi am unable\b`), regexp.MustCompile(`(?i)\bsuccessfully\b`),
	regexp.MustCompile(`(?i)\bplease note\b`), regexp.MustCompile(`(?i)\bas an ai\b`),
	regexp.MustCompile(`(?i)\bunfortunately\b`), regexp.MustCompile(`(?i)\bgreat news\b`),
	regexp.MustCompile(`(?i)\bawesome\b`), regexp.MustCompile(`(?i)\bamazing\b`),
}

var promisePhrases = []*regexp.Regexp{
	regexp.MustCompile(`(?i)\bwe('ll| will) deliver\b`), regexp.MustCompile(`(?i)\bwill be delivered\b`),
	regexp.MustCompile(`(?i)\bdelivery is\b`), regexp.MustCompile(`(?i)\bwe can deliver\b`),
	regexp.MustCompile(`(?i)\b(they|someone|he|she) will (collect|come|turn up|reply|be in touch|get back)\b`),
	regexp.MustCompile(`(?i)\bguarantee`), regexp.MustCompile(`(?i)\bwithin \d+ (minutes|hours|days)\b`),
	regexp.MustCompile(`(?i)\bby (tonight|tomorrow|the weekend)\b`),
}

var wordSplit = regexp.MustCompile(`[^a-z0-9£@.]+`)
var possessive = regexp.MustCompile(`['’]s\b`)
var numberRe = regexp.MustCompile(`\d[\d,.:]*`)
var trailingNumPunct = regexp.MustCompile(`[,.:]+$`)
var nonLetterEdges = regexp.MustCompile(`^[^A-Za-z]+|[^A-Za-z]+$`)
var properRe = regexp.MustCompile(`^[A-Z][a-z]+$`)

func wordsOf(s string) []string {
	s = strings.ToLower(possessive.ReplaceAllString(s, ""))
	var out []string
	for _, w := range wordSplit.Split(s, -1) {
		if w != "" {
			out = append(out, w)
		}
	}
	return out
}

// Vocabulary is everything a reply may legitimately name.
type Vocabulary map[string]bool

// AllowedVocabulary builds the bag from the facts (any JSON-able value), what the
// member said recently, and the fact sheet.
func AllowedVocabulary(facts interface{}, recentUserText, factSheet string) Vocabulary {
	bag := Vocabulary{}
	for w := range alwaysAllowed {
		bag[w] = true
	}
	var walk func(v interface{})
	walk = func(v interface{}) {
		switch t := v.(type) {
		case nil:
		case map[string]interface{}:
			for _, x := range t {
				walk(x)
			}
		case []interface{}:
			for _, x := range t {
				walk(x)
			}
		case string:
			for _, w := range wordsOf(t) {
				bag[w] = true
			}
		default:
			b, _ := json.Marshal(t)
			for _, w := range wordsOf(string(b)) {
				bag[w] = true
			}
		}
	}
	// Normalise through JSON so structs and maps are treated alike.
	b, _ := json.Marshal(facts)
	var generic interface{}
	_ = json.Unmarshal(b, &generic)
	walk(generic)
	for _, w := range wordsOf(recentUserText) {
		bag[w] = true
	}
	for _, w := range wordsOf(factSheet) {
		bag[w] = true
	}
	for _, w := range wordsOf(Character) {
		bag[w] = true
	}
	return bag
}

func hasEmoji(s string) bool {
	for _, r := range s {
		if r >= 0x1F000 || (r >= 0x2600 && r <= 0x27BF) || unicode.Is(unicode.So, r) {
			return true
		}
	}
	return false
}

// Ordinary words that often start a sentence and are not names.
var sentenceStarters = map[string]bool{}

func init() {
	for _, w := range strings.Fields("that have when what where which who how why here there this these those it its you your we our i if so once and but or no yes not just only all any some one two three a an the lovely brilliant great nice good sorted done right okay ok roughly anything something nothing everything posted thanks thank sorry sounds looks seems want need give ask see find pop add tell let do does did is are was were will would could should can may might keep try go come back still then now first next last also even well very quite really sure freegle freeglers happy hope glad fine perfect got no problem whenever whatever please condition size collection anything anyone people someone nobody everyone lots plenty maybe perhaps nearly almost welcome pop tap type either both most more less another other every each none offers wanted posts photos photo replies reply offer ask nearby chat chats last thing things comfy words which") {
		sentenceStarters[w] = true
	}
}

// Capitalised words: names, places, communities. A word that starts a sentence is
// only counted when it is not an ordinary word, since English capitalises those too.
func properNounsIn(s string, vocab Vocabulary) []string {
	var out []string
	first := true
	for _, tok := range strings.Fields(s) {
		clean := nonLetterEdges.ReplaceAllString(tok, "")
		if properRe.MatchString(clean) {
			lower := strings.ToLower(clean)
			if !first || (!sentenceStarters[lower] && !vocab[lower] && !alwaysAllowed[lower]) {
				out = append(out, lower)
			}
		}
		first = strings.HasSuffix(tok, ".") || strings.HasSuffix(tok, "?") || strings.HasSuffix(tok, "!")
	}
	return out
}

// CheckReply returns ok and the reasons it failed.
func CheckReply(say string, vocab Vocabulary, state string) (bool, []string) {
	var reasons []string
	text := strings.TrimSpace(say)
	if text == "" {
		return false, []string{"empty"}
	}
	if strings.Contains(text, "!") {
		reasons = append(reasons, "exclamation")
	}
	if hasEmoji(text) {
		reasons = append(reasons, "emoji")
	}
	for _, re := range bannedPhrases {
		if re.MatchString(text) {
			reasons = append(reasons, "phrase:"+re.String())
		}
	}
	for _, re := range promisePhrases {
		if re.MatchString(text) {
			reasons = append(reasons, "promise:"+re.String())
		}
	}
	limit := 360
	if state == "HELP" {
		limit = 700
	}
	if len([]rune(text)) > limit {
		reasons = append(reasons, "too_long")
	}
	for _, n := range numberRe.FindAllString(text, -1) {
		n = trailingNumPunct.ReplaceAllString(n, "")
		if n == "1" || n == "2" || n == "3" {
			continue
		}
		if !vocab[strings.ToLower(n)] {
			reasons = append(reasons, "number:"+n)
		}
	}
	// Numbers written as words count too: "four people" is as much a claim as "4 people".
	for _, w := range wordSplit.Split(strings.ToLower(text), -1) {
		n, ok := wordNumbers[w]
		if !ok || n <= 3 {
			continue
		}
		if !vocab[strconv.Itoa(n)] && !vocab[w] {
			reasons = append(reasons, "number:"+w)
		}
	}
	for _, name := range properNounsIn(text, vocab) {
		if !vocab[name] {
			reasons = append(reasons, "name:"+name)
		}
	}
	return len(reasons) == 0, reasons
}
