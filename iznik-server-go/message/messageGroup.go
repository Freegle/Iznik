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
	Approvedby              uint64           `json:"approvedby"`
	Heldby                  *uint64          `json:"heldby,omitempty"`
	Spamtype                *string          `json:"spamtype,omitempty"`
	Spamreason              *string          `json:"spamreason,omitempty"`
	ContentcheckCheckedAt   *time.Time       `json:"contentcheck_checked_at,omitempty"`
	ContentcheckReasons     *json.RawMessage `json:"contentcheck_reasons,omitempty"`

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

	// ModMessagingAllowed is whether mods on this group may message the poster of this
	// message directly. Defaults true for ordinary Freegle posts; TN API ingestion sets
	// it false unless TN told us the poster consented for this group (see
	// PostSyncer::processPost / GroupPostIngestionService in iznik-batch).
	ModMessagingAllowed bool `json:"mod_messaging_allowed"`
}
