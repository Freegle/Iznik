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
}
