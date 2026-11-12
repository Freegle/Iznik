package utils

import "fmt"

// The Check queue is the live posts that published themselves on a community in the
// post-moderation trial and that no moderator has yet looked at. Everything that asks "is this
// copy in the Check queue?" builds its query from these two pieces, so there is one definition:
// the list ModTools shows, the menu badge, "Mark all as checked", marking particular posts, and
// the Check reject. They were once four hand-written copies, and they drifted: the bucket
// stamped posts of deleted members that the list hid, marking ids stamped copies a moderator
// had approved, and a post left the list and the badge the moment its poster left the
// community.

// CheckQueueJoins joins the poster's message, account and membership onto messages_groups,
// which the caller aliases as mg. The membership is a LEFT JOIN: a poster who has since left
// the community, or been banned from it, has no membership row, and their live post must stay
// in the queue (for a banned poster it is the post a moderator most wants to see).
const CheckQueueJoins = "INNER JOIN messages m ON m.id = mg.msgid " +
	"INNER JOIN users u ON u.id = m.fromuser " +
	"LEFT JOIN memberships mem ON mem.userid = m.fromuser AND mem.groupid = mg.groupid "

// CheckQueueConditions is the WHERE for a copy being in the Check queue: live, direct (not
// rippled in), not deleted, published by itself (no moderator approved it), from a member with
// no posting status (the auto-moderated tier; a poster with no membership row passes, see
// CheckQueueJoins), and inside the check window. unchecked adds "and no moderator or
// microvolunteer quorum has marked it checked": the queue itself, its badge and the marking
// actions want that; the Check reject does not, because a post stamped checked by two
// microvolunteers can still be pulled back by a moderator.
//
// The caller restricts mg.groupid to the communities in the trial.
func CheckQueueConditions(unchecked bool) string {
	sql := "mg.collection = '" + COLLECTION_APPROVED + "' AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND u.deleted IS NULL " +
		"AND mg.approvedby IS NULL AND mg.rippled_in = 0 AND mem.ourPostingStatus IS NULL " +
		fmt.Sprintf("AND mg.arrival >= NOW() - INTERVAL %d DAY", MESSAGE_CHECK_WINDOW_DAYS)
	if unchecked {
		sql += " AND mg.checkedat IS NULL"
	}

	return sql
}

// CheckRejectLogText is the text of the Hold log written on the community where a moderator
// pulled a post back from Check. The automated-review agreement report finds those pull-backs
// by it, and reads what the moderator then decided.
const CheckRejectLogText = "Pulled back from Check"
