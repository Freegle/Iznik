package message

import (
	"encoding/json"
	"time"
)

func (MessageOutcome) TableName() string {
	return "messages_outcomes"
}

func (MessagePromise) TableName() string {
	return "messages_promises"
}

type MessageOutcome struct {
	ID        uint64    `json:"id" gorm:"primary_key"`
	Msgid     uint64    `json:"msgid"`
	Timestamp time.Time `json:"timestamp"`
	Outcome   string    `json:"outcome"`
}

// MessagePromise is a row of messages_promises. The Terms/Acceptedat/Acceptedby
// fields are the optional "agreement" extension (see handleAcceptAgreement):
// they stay NULL - and are omitted from the JSON - unless a client chooses to
// use them, so a plain Freegle promise serialises exactly as it always has.
type MessagePromise struct {
	ID         uint64          `json:"id" gorm:"primary_key"`
	Msgid      uint64          `json:"msgid"`
	Userid     uint64          `json:"userid"`
	Promisedat time.Time       `json:"promisedat"`
	Terms      json.RawMessage `json:"terms,omitempty"`
	Acceptedat *time.Time      `json:"acceptedat,omitempty"`
	Acceptedby *uint64         `json:"acceptedby,omitempty"`
}
