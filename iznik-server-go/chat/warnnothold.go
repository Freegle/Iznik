package chat

import (
	"os"
	"strings"
)

// Experiment: warn, do not hold.
//
// Today a chat message the content check flags (reviewrequired=1) is invisible to the
// recipient until a moderator approves it, and nobody tells either side. With nobody there
// the message is auto-rejected a week later. CHAT_WARN_NOT_HOLD=1 delivers the message
// anyway, tagged with a short reason, so the client can show it behind a warning the member
// taps through. A moderator can still reject it later, which hides it again.
//
// Off unless switched on. This is a thought experiment, not the shipped behaviour.
func WarnNotHold() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("CHAT_WARN_NOT_HOLD")))
	return v == "1" || v == "true" || v == "yes" || v == "on"
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
	default:
		return "checked"
	}
}

// SensitiveSnippet is what the chat list shows in place of a held message's text, so the
// warning is not bypassed by the preview.
const SensitiveSnippet = "New message - tap to view"

// HeldReasonsNeverDelivered are the stored reasons that mean "a person decided this sender
// is not to be heard", not "the content check saw something". A member on full chat
// moderation (a shadow ban) is held with the generic 'Spam' reason; 'Last' is the hold that
// chains from it; 'Fully' is the explicit form. None of those is a warning to tap through.
// A missing reason is treated the same way, because it cannot be explained to the member.
const HeldReasonsNeverDelivered = "'Spam', 'Fully', 'Last'"

// deliverableSQL is the predicate that says a message from somebody else may be shown to a
// member. col is the column prefix, such as "cmv." or "", exactly as the caller writes SQL.
//
// Normally a message held for review is not deliverable. Under the experiment a message the
// content check held is delivered and the client warns; a message held because of who sent
// it (HeldReasonsNeverDelivered) stays hidden, and so does a rejected one.
func deliverableSQL(col string) string {
	if WarnNotHold() {
		return "(" + col + "reviewrequired = 0 OR (" + col + "reportreason IS NOT NULL AND " +
			col + "reportreason NOT IN (" + HeldReasonsNeverDelivered + "))) AND " + col + "reviewrejected = 0"
	}
	return col + "reviewrequired = 0 AND " + col + "reviewrejected = 0"
}
