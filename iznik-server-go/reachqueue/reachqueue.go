// Package reachqueue is the member side of reach mail.
//
// Reach mail has two triggers: a post's reach moved (the batch pass resumes from a mark on
// rippling_reach.updated_at) and a member changed. This package carries the second. The
// codepath that changes a member - a join, a postcode change, a return after a long absence, a
// switch to immediate mail - queues them here, and iznik-batch's reach pass drains the queue,
// asking which live posts now cover the member and mailing them about each. The
// rippling_reach_notified ledger dedupes, so queueing someone already mailed is harmless.
//
// One row per member: the UNIQUE key on userid collapses a burst of signals before the drain
// into one row carrying the latest reason. PHP writes the same table through
// App\Services\Ripple\ReachMemberQueueService.
package reachqueue

import "gorm.io/gorm"

const (
	ReasonJoined    = "joined"
	ReasonMoved     = "moved"
	ReasonReturned  = "returned"
	ReasonFrequency = "frequency"
)

// QueueMember records that userid's eligibility for reach mail may have changed.
func QueueMember(db *gorm.DB, userid uint64, reason string) {
	if userid == 0 {
		return
	}
	db.Exec("INSERT INTO rippling_reach_member_pending (userid, reason, added) VALUES (?, ?, NOW()) "+
		"ON DUPLICATE KEY UPDATE reason = VALUES(reason), added = VALUES(added)", userid, reason)
}

// BumpReachForRepost marks a reposted post's reach as changed so the reach pass re-evaluates
// it. A Taken or Received post stays in messages_spatial, so its reach row survives the
// outcome with its old updated_at; clearing the outcome on repost makes the post mailable
// again but signals nothing by itself. A no-op when the row was dropped (Withdrawn leaves the
// index and the retract path removes the row): that repost re-enters reach like a first
// approval. Everyone mailed in the post's first life is in the ledger and is not mailed twice.
func BumpReachForRepost(db *gorm.DB, msgid uint64) {
	if msgid == 0 {
		return
	}
	db.Exec("UPDATE rippling_reach SET updated_at = NOW() WHERE msgid = ?", msgid)
}
