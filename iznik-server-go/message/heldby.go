package message

// effectiveHeldby returns the hold a VIEWER should see for a (possibly
// multi-group) message: a hold placed on one of the viewer's OWN moderated
// groups. A hold another team placed on a rippled-out copy — a group the viewer
// does not moderate — must NOT show to them, so it is ignored.
//
// This is a BACKWARDS-COMPATIBILITY shim, not the truth. The truth is per-group
// (messages_groups.heldby, exposed as groups[].heldby): a hold belongs to a
// (message, group) pair, and no message-wide value can answer "is this held?"
// for a post that reached several groups. Up-to-date clients read the per-group
// row for the group they are acting on.
//
// It exists because the ModTools app bundles its web build into the binary, so
// installed apps run frontend code that predates the per-group change and render
// held state from the message-level field. Dropping the field stopped holds
// working in every installed app (Discourse 9481/636). Computing it from the
// per-group rows keeps those apps working without resurrecting the
// messages.heldby mirror, which is genuinely obsolete.
//
// Returns nil when none of the viewer's groups hold the message, or when the
// viewer's own groups disagree about it (held on one, not on another) - a compat
// client has no way to say WHICH of the viewer's groups a single yes/no flag refers
// to, so claiming "held" when it's only true for a group the viewer isn't
// necessarily looking at is actively misleading (Discourse 9481/642: a moderator
// covering several communities saw a hold that belonged to a different one of
// theirs reported as if it were on the group they were reviewing). nil is exactly
// what an unheld post already reports, so this never invents a false negative -
// only avoids a false positive that a compat client cannot untangle.
func effectiveHeldby(groups []MessageGroup, viewerGroups map[uint64]bool) *uint64 {
	var holder *uint64
	sawOwnGroup := false
	for i := range groups {
		if !viewerGroups[groups[i].Groupid] {
			continue
		}
		sawOwnGroup = true
		if groups[i].Heldby == nil {
			return nil
		}
		holder = groups[i].Heldby
	}
	if !sawOwnGroup {
		return nil
	}
	return holder
}
