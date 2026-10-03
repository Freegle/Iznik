package assistant

import "strings"

// Fallback lines, used only when the model is unavailable, over quota, or its reply
// failed the check twice. Plain, short, in the same voice.
var templates = map[string]string{
	"HUB":              "Give and get stuff for free, near you. What would you like to do?",
	"GIVE_PHOTO":       "Have you got a photo? Posts with one get far more interest.",
	"GIVE_ITEM":        "What is it, in a few words?",
	"GIVE_DESCRIPTION": "Anything people should know? Condition, size, when it can be collected.",
	"GIVE_QUANTITY":    "How many are there?",
	"GIVE_WHERE":       "Roughly where is it? A postcode is easiest, and only the area is shown.",
	"GIVE_EMAIL":       "Where should replies go? Pop in your email.",
	"GIVE_CONFIRM":     "Here's what will go up. Happy with it?",
	"GIVE_POST":        "Posting that for you now.",
	"GIVE_DONE":        "That's with your local freeglers now. When someone's keen you'll hear from them right here.",
	"ASK_ITEM":         "What are you looking for?",
	"ASK_MATCHES":      "A few things nearby might be what you want. Have a look, or carry on asking.",
	"ASK_DESCRIPTION":  "Anything that would help people know if theirs would suit?",
	"ASK_WHERE":        "Roughly where are you? A postcode is easiest.",
	"ASK_EMAIL":        "Where should offers go? Pop in your email.",
	"ASK_CONFIRM":      "Here's your ask. Happy with it?",
	"ASK_POST":         "Posting that for you now.",
	"ASK_DONE":         "That's with your local freeglers now. Offers will arrive right here.",
	"NEARBY":           "Here's what's on offer near you.",
	"NEARBY_WHERE":     "Roughly where are you? A postcode is easiest.",
	"COMMUNITY":        "These are the communities nearest you.",
	"COMMUNITY_WHERE":  "Roughly where are you? A postcode is easiest.",
	"HELP":             "What can we help with?",
	"CANCELLED":        "No problem. Whenever you like, I can help you give or find something.",
}

const templateSteer = "Freegle is for giving and getting things, free, near you. I'm here when you want to give something or find something."
const templateAbuse = "That's not something Freegle can help with. If you've something to pass on, I'm here."

// Second forms of the questions, for when the first has just been asked and the member
// typed something that did not answer it. Saying the identical line twice reads as a
// machine; a rephrase reads as a person trying again.
var templatesAgain = map[string]string{
	"GIVE_PHOTO":       "A photo helps a lot, but you can tap No photo and carry on.",
	"GIVE_ITEM":        "Sorry, I didn't catch what it is. A few words, like wooden chair or box of books.",
	"GIVE_DESCRIPTION": "Anything else worth saying about it? If not, tap Skip.",
	"GIVE_QUANTITY":    "How many are there? Tap a number.",
	"GIVE_WHERE":       "I need a postcode to find the people near you. What's yours?",
	"GIVE_EMAIL":       "Which email should replies go to?",
	"GIVE_CONFIRM":     "Happy with that? Tap Post it, or Change something.",
	"ASK_ITEM":         "Sorry, I didn't catch that. What are you after, in a few words?",
	"ASK_DESCRIPTION":  "Anything that would help people know if theirs would do? If not, tap Skip.",
	"ASK_WHERE":        "I need a postcode to find the people near you. What's yours?",
	"ASK_EMAIL":        "Which email should replies go to?",
	"ASK_CONFIRM":      "Happy with that? Tap Post it, or Change something.",
	"NEARBY_WHERE":     "A postcode is the quickest way for me to find what's near you.",
	"COMMUNITY_WHERE":  "A postcode is the quickest way for me to find your community.",
	"HELP":             "Tap one of those, or just say what you're after.",
}

// TemplateFor returns the fallback line for a state.
func TemplateFor(state string) string {
	if t, ok := templates[state]; ok {
		return t
	}
	return templates["HUB"]
}

// WarmTemplate is the fallback line with the thread carried through it: what they just
// gave is acknowledged in a few words before the next question, and the stage they have
// reached is marked lightly. Only what is in the slots and facts is ever named.
func WarmTemplate(state string, slots Slots, facts Facts) string {
	item := shortItem(slots.str("item"))
	where := strings.TrimSpace(facts.str("locationName"))
	community := strings.TrimSpace(facts.str("community"))
	switch state {
	case "GIVE_PHOTO":
		if item != "" {
			return "A " + item + ", lovely. Have you got a photo? Posts with one get far more interest."
		}
		return "Lovely, let's find it a new home nearby. Have you got a photo? Posts with one get far more interest."
	case "GIVE_ITEM":
		if facts.truthy("hasRealPhoto") {
			return "Thanks, that helps people picture it. What is it, in a few words?"
		}
		if slots.has("photoDecided") {
			return "No problem, words will do. What is it, in a few words?"
		}
		return templates[state]
	case "GIVE_DESCRIPTION":
		if item != "" {
			return "A " + item + ", great. Anything people should know? Condition, size, when it can be collected."
		}
		return templates[state]
	case "GIVE_QUANTITY":
		return "Thanks. How many are there?"
	case "GIVE_WHERE":
		return "Nearly there. Roughly where is it? A postcode is easiest, and only the area is shown."
	case "GIVE_EMAIL":
		if where != "" {
			return where + ", lovely. Last thing: where should replies go? Pop in your email."
		}
		return "Last thing: where should replies go? Pop in your email."
	case "GIVE_CONFIRM":
		return "That's everything. Here's what will go up. Happy with it?"
	case "GIVE_DONE":
		if community != "" {
			return "That's with your local freeglers now, all around " + community + ". When someone's keen you'll hear from them right here."
		}
		return templates[state]
	case "ASK_ITEM":
		return "Let's see who nearby can help. What are you looking for?"
	case "ASK_MATCHES":
		if item != "" {
			return "A " + item + ". A few things nearby might be just that. Have a look, or carry on asking."
		}
		return templates[state]
	case "ASK_DESCRIPTION":
		if item != "" {
			return "A " + item + ", got it. Anything that would help people know if theirs would suit? Size, or what it's for."
		}
		return templates[state]
	case "ASK_WHERE":
		return "Nearly there. Roughly where are you? A postcode is easiest, and only the area is shown."
	case "ASK_EMAIL":
		if where != "" {
			return where + ", lovely. Last thing: where should offers go? Pop in your email."
		}
		return "Last thing: where should offers go? Pop in your email."
	case "ASK_CONFIRM":
		return "That's everything. Here's your ask. Happy with it?"
	case "ASK_DONE":
		if community != "" {
			return "That's with your local freeglers now, all around " + community + ". Offers will arrive right here."
		}
		return templates[state]
	case "NEARBY":
		if where != "" {
			return "Here's what's on offer around " + where + " just now."
		}
		return templates[state]
	}
	return TemplateFor(state)
}

// shortItem trims a slot value to something that reads inside a sentence.
func shortItem(item string) string {
	item = strings.TrimSpace(strings.ToLower(item))
	item = strings.TrimRight(item, ".!")
	if len([]rune(item)) > 40 {
		return ""
	}
	return item
}

// TemplateAfter is TemplateFor without repeating itself: if the line for the state is the
// one Freegle has just said, the question's second form is used, and outside a question the
// steer line, which says what Freegle is for.
func TemplateAfter(state, lastSaid string) string {
	return templateAfter(state, lastSaid, Slots{}, Facts{})
}

// WarmTemplateAfter is TemplateAfter with the thread carried through (WarmTemplate).
func WarmTemplateAfter(state, lastSaid string, slots Slots, facts Facts) string {
	return templateAfter(state, lastSaid, slots, facts)
}

func templateAfter(state, lastSaid string, slots Slots, facts Facts) string {
	t := WarmTemplate(state, slots, facts)
	if lastSaid == "" || t != lastSaid {
		return t
	}
	if again, ok := templatesAgain[state]; ok {
		return again
	}
	return templateSteer
}
