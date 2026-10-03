package chat

// Warn, do not hold.
//
// A chat message the content check flags (reviewrequired=1) is delivered to the recipient
// anyway, tagged with a short reason, so the client shows it behind a warning the member taps
// through, rather than being invisible until a moderator approves it. A moderator can still
// reject it later, which hides it again.

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

// HeldReasonsNeverDelivered are the stored reasons that mean "a person decided this sender
// is not to be heard", not "the content check saw something". A member on full chat
// moderation (a shadow ban) is held with the generic 'Spam' reason; 'Last' is the hold that
// chains from it; 'Fully' is the explicit form. None of those is a warning to tap through.
// A missing reason is treated the same way, because it cannot be explained to the member.
const HeldReasonsNeverDelivered = "'Spam', 'Fully', 'Last'"

// deliverableSQL is the predicate that says a message from somebody else may be shown to a
// member. col is the column prefix, such as "cmv." or "", exactly as the caller writes SQL.
//
// A message held for review because of who sent it (HeldReasonsNeverDelivered) stays hidden,
// and so does a rejected one. A message the content check held for its content is delivered,
// and the client warns.
func deliverableSQL(col string) string {
	return "(" + col + "reviewrequired = 0 OR (" + col + "reportreason IS NOT NULL AND " +
		col + "reportreason NOT IN (" + HeldReasonsNeverDelivered + "))) AND " + col + "reviewrejected = 0"
}
