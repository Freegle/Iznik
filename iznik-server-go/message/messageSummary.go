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
	// Posted is the ORIGINAL post arrival (messages.arrival), stable across
	// rippling. On the nearby/reach feed the client's "Newest posted" sort orders
	// by this so a post that merely rippled again does not jump to the top with a
	// days-old badge (Discourse 9844). Arrival, by contrast, is the reach-bumped
	// spatial arrival used for the relevance score. Only populated on the browse
	// feed; zero elsewhere (falls back to arrival client-side).
	Posted time.Time `json:"posted,omitempty"`
	// VisibleSince is the earliest this post could have been seen: the oldest
	// arrival across the groups it is live on. It is the ONE clock the browse feed
	// uses - both the "Newest posted" order and the card's time badge - so the list
	// can never contradict the dates printed on it.
	//
	// Neither of the two fields above could do that job. Arrival is the reach-bumped
	// spatial arrival, so it moves when a post merely ripples further. Posted is when
	// the message was written, which is NOT when it became available: a post written
	// on the 24th, approved onto its group on the 8th and rippled onward on the 12th
	// showed "5 days" on the card while sorting as 20 days old, so the feed printed
	// dates in an order its own sort key contradicted.
	//
	// It moves for the two reasons a post legitimately becomes available later than it
	// was written: a repost (the giver re-offering it, which updates the group row) and
	// a ripple into a further group. Ordering by it means a repost lifts the post back
	// up, which is the point of reposting.
	//
	// LIMITATION: the oldest arrival across ALL the post's groups, not just the ones
	// this viewer can see, which would need their membership set threading into the
	// query. Zero outside the browse feed; the client falls back to posted there.
	VisibleSince time.Time `json:"visibleSince,omitempty"`
	Date         time.Time `json:"date"`
	Lat          float64   `json:"lat"`
	Lng          float64   `json:"lng"`
	Unseen       bool      `json:"unseen"`
	// Score is the rippling relevance score (see isochrone.Score) used to
	// order the 'nearby' browse feed. Only populated on that path; zero/
	// omitted elsewhere.
	Score float64 `json:"score,omitempty"`
	// Road drive time/distance from the VIEWER's home to this post's blurred
	// location (nil when the reach engine cannot answer). Shipped with the
	// feed so "Closest" can order by road miles from the FIRST render - the
	// same values the full message record carries - with no client-side
	// routing calls and no later re-sort.
	Roadmins  *float64 `json:"roadmins,omitempty" gorm:"-"`
	Roadmiles *float64 `json:"roadmiles,omitempty" gorm:"-"`
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
	// Fromuser is the post's author, scanned only so a feed can derive Mine below. It is
	// never exposed to clients (json:"-"): the browse feeds already blur a post's location,
	// so handing out the author's user id alongside it would give back what the blurring
	// takes away. Populated only where a feed selects it; zero elsewhere.
	Fromuser uint64 `json:"-" gorm:"column:fromuser"`
	// Mine is true when the post's author is the viewer. Every browse feed sets it so the
	// client can float the viewer's own recent posts to the top of every sort order —
	// members otherwise lose track of their own posts among the reach-ordered feed and
	// assume they are not showing (Discourse 9933). Always derived in Go from Fromuser (or
	// from an arm that is own-posts-only by construction), never scanned from SQL.
	Mine bool `json:"mine,omitempty" gorm:"-"`
}
