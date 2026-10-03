package test

import (
	"fmt"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// The browse list runs on ONE clock, visibleSince (isochrone_visiblesince_test.go): the
// client's "Newest posted" sort and each card's age badge both read it, so the order can never
// contradict the ages printed on it. That rule reached the reach feed and the full message
// record only. "All my communities" and a single community render from /message/mygroups, and
// a moved map from /message/inbounds - and both answered with a zero visibleSince (inbounds
// had no posted either). The client then sorted by its fallback while every card, re-rendered
// from the full message, printed the group arrival: a member on Newest posted saw
// 27 days, 7 days, 3 days, 28 days (Discourse 9808/801-802). The list locks its order at first
// paint, so the full records loading a moment later could not repair it - the summary itself
// has to carry the field.

// coinedWord is a rare, short word (<=10 chars) so a search hit is deterministic and stays
// within SEARCH_LIMIT in the shared DB - the same trick as TestAPISearch_DedupsExactAndStartsMatch.
func coinedWord() string {
	return fmt.Sprintf("zv%d", time.Now().UnixNano()%100000)
}

func assertOneClock(t *testing.T, feed string, msgs []message.MessageSummary, msgID uint64) {
	var found *message.MessageSummary
	for i := range msgs {
		if msgs[i].ID == msgID {
			found = &msgs[i]
			break
		}
	}
	if !assert.NotNil(t, found, "%s returns the post", feed) {
		return
	}

	assert.False(t, found.VisibleSince.IsZero(), "%s carries visibleSince", feed)
	daysAgo := time.Since(found.VisibleSince).Hours() / 24
	assert.InDelta(t, 3, daysAgo, 1,
		"%s: visibleSince is the OLDEST LIVE group arrival (the 3-day repost) - not the write time (30 days), not the onward ripple (1 day), not the deleted row (20 days): got %v", feed, found.VisibleSince)

	assert.False(t, found.Posted.IsZero(), "%s carries posted", feed)
	postedDaysAgo := time.Since(found.Posted).Hours() / 24
	assert.InDelta(t, 30, postedDaysAgo, 1,
		"%s: posted is still when the post was written, so the card can say 'first posted 30 days': got %v", feed, found.Posted)
}
