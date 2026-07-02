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
	// Score is the rippling relevance score (see isochrone.Score) used to
	// order the 'nearby' browse feed. Only populated on that path; zero/
	// omitted elsewhere.
	Score float64 `json:"score,omitempty"`
	// Distance is the great-circle distance in miles from the viewer to this
	// post, computed from the BLURRED (already-privacy-fuzzed) coordinates —
	// never the real ones, so it can't be used to triangulate a post's true
	// location. Only populated on the 'nearby' browse feed; 0 elsewhere (0 is
	// a meaningful "very close" value there too, so this field is never
	// omitted).
	Distance float64 `json:"distance"`
	// Pinned is true when this post has a messages_pinned row (a paid bulk-offer
	// clearance): the browse feed floats it to the top whenever it already qualifies
	// to appear. Only set on the browse feed; omitted (false) elsewhere.
	Pinned bool `json:"pinned,omitempty" gorm:"-"`
}
