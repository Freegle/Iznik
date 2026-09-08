package assistant

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

// TemplateFor returns the fallback line for a state.
func TemplateFor(state string) string {
	if t, ok := templates[state]; ok {
		return t
	}
	return templates["HUB"]
}
