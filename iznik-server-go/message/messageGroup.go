package message

import (
	"encoding/json"
	"time"
)

type Tabler interface {
	TableName() string
}

func (MessageGroup) TableName() string {
	return "messages_groups"
}

type MessageGroup struct {
	Groupid     uint64    `json:"groupid"`
	Msgid       uint64    `json:"msgid"`
	Arrival     time.Time `json:"arrival"`
	Collection  string    `json:"collection"`
	Autoreposts uint      `json:"autoreposts"`

	// There's a slight privacy issue in returning the approval id.  Potentially we might not want users to know that
	// their messages are moderated, and we might not want to reveal the id of the moderator.  However it's a useful
	// thing to be able to show mods themselves.
	Approvedby            uint64           `json:"approvedby"`
	Heldby                *uint64          `json:"heldby,omitempty"`
	Spamtype              *string          `json:"spamtype,omitempty"`
	Spamreason            *string          `json:"spamreason,omitempty"`
	ContentcheckCheckedAt *time.Time       `json:"contentcheck_checked_at,omitempty"`
	ContentcheckReasons   *json.RawMessage `json:"contentcheck_reasons,omitempty"`

	// RippledIn is set when this messages_groups row was created by the rippling engine
	// (the post originated on another group and rippled in here). The moderation UI uses
	// it to show the "rippled out / rippled in" banner authoritatively rather than guessing
	// from arrival times, which the approve path (arrival=NOW()) can scramble. (9808/303)
	RippledIn uint8 `json:"rippled_in"`

	// RippleProximityP/Q are the human place names for the P/Q "quicker to get to" moderator
	// note (see ExpandService::recordRippleProximity), set only when quicker=true. Absent
	// (omitempty) means either this copy was not rippled-in, or it was not quicker, or the
	// routing/KNN calls failed at ripple-in time — the frontend shows nothing in all three cases.
	RippleProximityP *string `json:"ripple_proximity_p,omitempty"`
	RippleProximityQ *string `json:"ripple_proximity_q,omitempty"`

	// QualitySample is set by AutoApproveCleanService when a clean post is held back
	// for a manual quality check. Scanned for the autoapproveat estimate; not serialised.
	QualitySample int `json:"-" gorm:"column:quality_sample"`

	// NeedsModerator is set on a copy a moderator's Back to pending pulled back, and
	// cleared when a moderator approves that copy. While set, no automatic path (content
	// check, auto-approve) may approve the copy - scanned for the autoapproveat estimate;
	// not serialised.
	NeedsModerator bool `json:"-" gorm:"column:needs_moderator"`

	// AutoapproveHoldUntil is the server-side extend-only hold set when the Pending
	// queue is viewed (see ListMessagesMT). Scanned but not serialised — the frontend
	// uses the computed Autoapproveat below.
	AutoapproveHoldUntil *time.Time `json:"-" gorm:"column:autoapprove_hold_until"`

	// Autoapproveat is the earliest time this post may be auto-approved, exposed only
	// on Pending messages viewed by a group moderator. nil = no auto-approval expected
	// (held / spam / danger-signalled, or not on any auto-approve path).
	Autoapproveat *time.Time `json:"autoapproveat,omitempty" gorm:"-"`
	// ModMessagingAllowed is whether mods on this group may message the poster of this
	// message directly. Defaults true for ordinary Freegle posts; TN API ingestion sets
	// it false unless TN told us the poster consented for this group (see
	// PostSyncer::processPost / GroupPostIngestionService in iznik-batch).
	ModMessagingAllowed bool `json:"mod_messaging_allowed"`

	// Automod is the flowchart's stored decision for this group, inlined only for a
	// moderator of this specific group on an automod group (see utils.AutomodGroup and
	// populateAutomodDecisions in autoapproveat.go). nil for everyone else, and for a
	// group with no messages_automod row yet (not run, or not on an automod path).
	Automod *AutomodDecision `json:"automod,omitempty" gorm:"-"`
}

// AutomodDecision is the automod flowchart's stored decision for one (msgid, groupid),
// mirroring messages_automod. Path is the node-by-node record (question, answer,
// confidence, evidence per node) the ModAutomodModal renders.
type AutomodDecision struct {
	Verdict string          `json:"verdict"`
	Reason  *string         `json:"reason,omitempty"`
	End     string          `json:"end"`
	Mode    string          `json:"mode"`
	Version string          `json:"version"`
	Path    json.RawMessage `json:"path"`
	Created time.Time       `json:"created"`
}

// modMessagingAllowed reduces a post's group rows to the one message-level answer the
// moderation UI needs: may this post's poster be talked to at all?
//
// Only the ORIGIN row (rippled_in = 0) carries the answer. The rippling engine inserts its
// copies without the column, so they take the table default (allowed) and would mask an
// unaddressed origin. A post with no origin row among the rows supplied reads as allowed -
// the safe direction, since everything this gates removes moderator abilities.
func modMessagingAllowed(groups []MessageGroup) bool {
	for _, g := range groups {
		if g.RippledIn == 0 && !g.ModMessagingAllowed {
			return false
		}
	}

	return true
}

// listModMessagingAllowed is modMessagingAllowed for the mod queue's leaner group rows.
// Same rule, different struct - the queue carries only the handful of columns it renders.
func listModMessagingAllowed(groups []MessageGroupInfo) bool {
	for _, g := range groups {
		if g.RippledIn == 0 && !g.ModMessagingAllowed {
			return false
		}
	}

	return true
}
