package chat

import (
	"os"
	"strings"
)

// Warn, do not hold.
//
// With CHAT_WARN_NOT_HOLD switched on, a chat message the content check flags
// (reviewrequired=1) is delivered to the recipient anyway, tagged with a short reason, so the
// client shows it behind a warning the member taps through, rather than being invisible until
// a moderator approves it. A moderator can still reject it later, which hides it again. With
// the switch off, a held message stays hidden from the recipient.

// Enabled reports whether warn-not-hold is switched on. It reads CHAT_WARN_NOT_HOLD with the
// same rules as the batch config (a boolean string), so both tiers agree. It is read on every
// call so the switch can change without a restart.
func Enabled() bool {
	switch strings.ToLower(strings.TrimSpace(os.Getenv("CHAT_WARN_NOT_HOLD"))) {
	case "1", "true", "on", "yes":
		return true
	}
	return false
}

// SensitiveReason turns the stored reportreason into the short key the client uses to pick
// its warning wording. The stored values are moderator-facing enum members; the member only
// needs to know what kind of care to take. Anything unknown, including a missing reason, is
// "checked": the message is being looked at, be careful.
func SensitiveReason(reportreason *string) string {
	if reportreason == nil {
		return "checked"
	}
	switch *reportreason {
	case "Money":
		return "money"
	case "Link", "URL on DBL":
		return "link"
	case "Email":
		return "contact"
	case "Language":
		return "language"
	case "WorryWord":
		return "concern"
	case "Referenced known spammer", "Greetings spam", "Known spam keyword":
		return "scam"
	case "Abuse":
		return "abuse"
	default:
		return "checked"
	}
}

// SensitiveSnippet is what the chat list shows in place of a held message's text, so the
// warning is not bypassed by the preview.
const SensitiveSnippet = "New message - tap to view"

// HeldReasonsDelivered are the stored reasons that mean "the content check saw something in
// the text", which a member can be warned about and left to judge. Every other reason, and a
// missing one, is a hold the member cannot be given a useful warning for: a shadow ban
// ('Spam', 'Fully', 'Last'), a blocked source (CountryBlocked, SpamAssassin, Script), a reused
// image (SameImage, DodgyImage) and so on. Those stay hidden. This is an allowlist, so a
// reason added to the enum later stays hidden until it is chosen here.
const HeldReasonsDelivered = "'Money', 'Link', 'URL on DBL', 'Email', 'Language', 'WorryWord', " +
	"'Referenced known spammer', 'Greetings spam', 'Known spam keyword', 'Abuse'"

// heldDeliverable reports whether a held message with this reason is delivered under
// warn-not-hold. It is the Go twin of HeldReasonsDelivered.
func heldDeliverable(reportreason *string) bool {
	if !Enabled() || reportreason == nil {
		return false
	}
	switch *reportreason {
	case "Money", "Link", "URL on DBL", "Email", "Language", "WorryWord",
		"Referenced known spammer", "Greetings spam", "Known spam keyword", "Abuse":
		return true
	}
	return false
}

// deliverableSQL is the predicate that says a message from somebody else may be shown to a
// member. col is the column prefix, such as "cmv." or "", exactly as the caller writes SQL.
//
// With warn-not-hold off, only a message that is neither held nor rejected may be shown. With
// it on, a message the content check held for its content (HeldReasonsDelivered) may be shown
// too, and the client warns. A rejected message is never shown.
func deliverableSQL(col string) string {
	if !Enabled() {
		return "(" + col + "reviewrequired = 0 AND " + col + "reviewrejected = 0)"
	}

	return "((" + col + "reviewrequired = 0 OR " + col + "reportreason IN (" + HeldReasonsDelivered + ")) AND " +
		col + "reviewrejected = 0)"
}
