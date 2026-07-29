package message

// effectiveHeldby returns the hold a VIEWER should see for a (possibly
// multi-group) message: a hold placed on one of the viewer's OWN moderated
// groups. A hold another team placed on a rippled-out copy — a group the viewer
// does not moderate — must NOT show to them, so it is ignored.
//
// This replaces reading the message-level messages.heldby mirror, which is set
// globally by Hold / Back-to-Pending and so leaked one group's hold to every
// group the post reached (the "posts held by mods not on my team" bug). The
// per-group truth lives in messages_groups.heldby.
//
// Returns nil when none of the viewer's groups hold the message.
func effectiveHeldby(groups []MessageGroup, viewerGroups map[uint64]bool) *uint64 {
	for i := range groups {
		if groups[i].Heldby != nil && viewerGroups[groups[i].Groupid] {
			return groups[i].Heldby
		}
	}
	return nil
}
