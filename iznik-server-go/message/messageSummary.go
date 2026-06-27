package message

import "time"

type MessageSummary struct {
	ID         uint64    `json:"id" gorm:"primary_key"`
	Hasoutcome bool      `json:"hasoutcome"`
	Successful bool      `json:"successful"`
	Promised   bool      `json:"promised"`
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	SpatialID  *uint64   `json:"spatialid,omitempty" gorm:"column:spatialid"`
	Type       string    `json:"type"`
	Arrival    time.Time `json:"arrival"`
	Date       time.Time `json:"date"`
	Lat        float64   `json:"lat"`
	Lng        float64   `json:"lng"`
	Unseen     bool      `json:"unseen"`
	// Bulkcount is the number of items in the bulk-offer catalogue, or 0 for
	// ordinary single-item messages. Non-zero flags this as a bulk offer.
	Bulkcount int `json:"bulkcount,omitempty" gorm:"column:bulkcount"`
}
